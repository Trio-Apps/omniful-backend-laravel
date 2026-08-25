<?php

namespace App\Console\Commands;

use App\Models\OmnifulOrder;
use App\Services\SapServiceLayerClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Link STANDALONE return credit notes to their source A/R invoice using SAP's
 * Document References (the "Referenced Documents" view of the relationship map).
 *
 * Background: a credit memo can only be BASE-referenced (BaseType 13) while the
 * invoice still has an open line; a fully delivered/paid invoice cannot even be
 * reopened (SL returns -5006 "action not supported"), so those returns were posted
 * standalone and showed unlinked. A DocumentReference achieves the link with ONE
 * PATCH, no cancellation/recreation and ZERO financial impact.
 *
 * Input CSV rows: `docnum,order` — the credit note's DocNum and the Omniful order
 * id (present on both sides). The invoice is resolved from our order record, and
 * from SAP by the order UDF as a fallback.
 *
 * Idempotent (skips credit notes that already carry a reference; a re-PATCH would
 * anyway just re-set the same single reference), resumable via --offset, and
 * tolerant of transient SAP TLS/timeout errors.
 */
class LinkStandaloneCreditNotes extends Command
{
    protected $signature = 'omniful:link-standalone-credit-notes {--file=cn_fix_list.csv} {--since=} {--dry-run} {--limit=0} {--offset=0}';

    protected $description = 'Attach a DocumentReference (source A/R invoice) to standalone return credit notes so they show linked in SAP.';

    public function handle(SapServiceLayerClient $client): int
    {
        $dry = (bool) $this->option('dry-run');
        $limit = (int) $this->option('limit');
        $offset = (int) $this->option('offset');

        $since = trim((string) $this->option('since'));
        $path = storage_path('app/' . $this->option('file'));
        if ($since === '' && !is_file($path)) {
            $this->error('File not found: ' . $path);

            return self::FAILURE;
        }

        $get = new \ReflectionMethod($client, 'get');
        $get->setAccessible(true);
        $patch = new \ReflectionMethod($client, 'patch');
        $patch->setAccessible(true);
        $S = '$';

        $retry = function (callable $fn, int $tries = 5) {
            for ($a = 1; ; $a++) {
                try {
                    return $fn();
                } catch (\Throwable $e) {
                    if ($a >= $tries) {
                        throw $e;
                    }
                    sleep(min(15, 2 ** $a));
                }
            }
        };

        // Rows are "docnum,order". Either from the CSV, or — with --since — scanned
        // straight from SAP: every non-cancelled credit note posted on/after that
        // date that carries no Document Reference yet. The order id comes from the
        // memo's own U_omo, so no CSV is needed for the ongoing gap.
        if ($since !== '') {
            $escSince = str_replace("'", "''", $since);
            $scan = $retry(fn () => $get->invoke(
                $client,
                "/CreditNotes?{$S}filter=" . rawurlencode("DocDate ge '{$escSince}'")
                . "&{$S}select=DocNum,DocumentReferences,Cancelled,U_omo,DocumentLines&{$S}orderby=DocEntry desc&{$S}top=1000"
            ));
            $rows = [];
            $baseLinked = 0;
            foreach ((array) ($scan->json('value') ?? []) as $cn) {
                if ((string) ($cn['Cancelled'] ?? 'tNO') === 'tYES' || !empty($cn['DocumentReferences'])) {
                    continue;
                }
                // Already linked through the document tree — adding a reference
                // would only duplicate what SAP already shows.
                $isBaseLinked = false;
                foreach ((array) ($cn['DocumentLines'] ?? []) as $cnLine) {
                    if ((int) ($cnLine['BaseType'] ?? -1) === 13) {
                        $isBaseLinked = true;
                        break;
                    }
                }
                if ($isBaseLinked) {
                    $baseLinked++;

                    continue;
                }
                $order = trim((string) ($cn['U_omo'] ?? ''));
                if ($order === '') {
                    continue;
                }
                $rows[] = ((string) ($cn['DocNum'] ?? '')) . ',' . $order;
            }
            $this->info('Skipped ' . $baseLinked . ' already base-referenced credit notes.');
            $this->info('Scanned SAP since ' . $since . ': ' . count($rows) . ' credit notes without a reference.');
        } else {
            $rows = array_values(array_filter(array_map('trim', file($path)), fn ($l) => $l !== ''));
            if ($rows && stripos($rows[0], 'docnum') === 0) {
                array_shift($rows);
            }
        }
        if ($offset > 0) {
            $rows = array_slice($rows, $offset);
        }
        if ($limit > 0) {
            $rows = array_slice($rows, 0, $limit);
        }

        $stats = [
            'seen' => 0, 'linked' => 0, 'already_linked' => 0,
            'cn_not_found' => 0, 'invoice_not_found' => 0, 'error' => 0,
        ];
        $i = $offset;
        $invoiceCache = [];

        foreach ($rows as $line) {
            $i++;
            [$docNum, $order] = array_pad(explode(',', $line), 2, '');
            $docNum = trim($docNum);
            $order = trim($order);
            if ($docNum === '' || !ctype_digit($docNum)) {
                continue;
            }
            $stats['seen']++;

            try {
                // 1) The credit note (entry + current references) by DocNum.
                $cn = (array) data_get(
                    $retry(fn () => $get->invoke($client, "/CreditNotes?{$S}filter=DocNum eq {$docNum}&{$S}select=DocEntry,DocNum,DocumentReferences&{$S}top=1"))->json(),
                    'value.0',
                    []
                );
                if ($cn === [] || empty($cn['DocEntry'])) {
                    $stats['cn_not_found']++;

                    continue;
                }
                if (!empty($cn['DocumentReferences'])) {
                    $stats['already_linked']++;

                    continue;
                }
                $cnEntry = (int) $cn['DocEntry'];

                // 2) The source invoice: our own order record first (indexed
                //    lookup, no SAP round-trip), then SAP by the order UDF.
                $invEntry = $invoiceCache[$order] ?? null;
                if ($invEntry === null) {
                    $invEntry = (int) (OmnifulOrder::where('external_id', $order)->value('sap_doc_entry') ?: 0);
                    if ($invEntry <= 0 && $order !== '') {
                        // The order reference is not always on U_omo: older
                        // invoices carry it on U_ZidId or NumAtCard, and reversed
                        // ones on a "<id>-…" suffixed U_omo. Try each in turn.
                        // Some references are decorated: a return ships as
                        // "RS-<order>" and a collision-renamed one as "<order>-N".
                        // Search the raw value first, then the bare order id.
                        $base = preg_replace('/-\d+$/', '', preg_replace('/^RS-/i', '', $order));
                        $escaped = str_replace("'", "''", $order);
                        $escapedBase = str_replace("'", "''", (string) $base);
                        $filters = [
                            "U_omo eq '{$escaped}'",
                            "U_ZidId eq '{$escaped}'",
                            "NumAtCard eq '{$escaped}'",
                            "startswith(U_omo,'{$escaped}')",
                        ];
                        if ($escapedBase !== '' && $escapedBase !== $escaped) {
                            $filters[] = "U_omo eq '{$escapedBase}'";
                            $filters[] = "U_ZidId eq '{$escapedBase}'";
                        }
                        foreach ($filters as $filter) {
                            $inv = (array) data_get(
                                $retry(fn () => $get->invoke($client, "/Invoices?{$S}filter=" . rawurlencode($filter) . "&{$S}select=DocEntry&{$S}orderby=DocEntry desc&{$S}top=1"))->json(),
                                'value.0',
                                []
                            );
                            $invEntry = (int) ($inv['DocEntry'] ?? 0);
                            if ($invEntry > 0) {
                                break;
                            }
                        }
                    }
                    $invoiceCache[$order] = $invEntry;
                }
                if ($invEntry <= 0) {
                    $stats['invoice_not_found']++;

                    continue;
                }

                if ($dry) {
                    $stats['linked']++;

                    continue;
                }

                // 3) Attach the reference. RefObjType "13" = A/R Invoice; SAP
                //    resolves it to rot_SalesInvoice and fills RefDocNum itself.
                $res = $retry(fn () => $patch->invoke($client, "/CreditNotes({$cnEntry})", [
                    'DocumentReferences' => [
                        ['RefDocEntr' => $invEntry, 'RefObjType' => '13'],
                    ],
                ]));

                if ($res->successful() || $res->status() === 204) {
                    $stats['linked']++;
                } else {
                    $stats['error']++;
                    Log::warning('Credit note link failed', [
                        'doc_num' => $docNum,
                        'cn_entry' => $cnEntry,
                        'invoice_entry' => $invEntry,
                        'status' => $res->status(),
                        'body' => substr((string) $res->body(), 0, 250),
                    ]);
                }
            } catch (\Throwable $e) {
                $stats['error']++;
                Log::warning('Credit note link row failed', ['doc_num' => $docNum, 'error' => substr($e->getMessage(), 0, 200)]);
                usleep(500000);
            }

            if ($stats['seen'] % 50 === 0) {
                $this->info('progress @' . $i . ': ' . json_encode($stats, JSON_UNESCAPED_SLASHES));
            }
        }

        $this->info(($dry ? '[DRY RUN] ' : '') . 'DONE @' . $i . ': ' . json_encode($stats, JSON_UNESCAPED_SLASHES));

        return self::SUCCESS;
    }
}
