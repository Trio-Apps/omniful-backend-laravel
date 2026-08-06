<?php

namespace App\Console\Commands;

use App\Models\IntegrationSetting;
use App\Models\OmnifulOrder;
use App\Services\SapServiceLayerClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Re-post the COGS journals that omniful:cancel-cogs-jes reversed, now onto the
 * CORRECT accounts (expense 5101017 Dr / inventory 1105002 Cr, from integration
 * settings) — the SAP team confirmed the fix and asked to "re-integrate the rest
 * without STO".
 *
 * Reads a list of numeric Omniful order ids (STO_/non-numeric already excluded)
 * and, for each SALE (omniful_status delivered/shipped/partially_delivered),
 * calls the SAME createCogsJournalForOmnifulOrder the webhook uses — which is
 * idempotent (rebinds an existing "COGS-<order>" JE instead of double-posting).
 * Cancelled / returned / not-yet-delivered orders are SKIPPED: their COGS nets to
 * zero, so re-posting a forward-only COGS would overstate the expense.
 */
class ReintegrateCogsJournals extends Command
{
    protected $signature = 'omniful:reintegrate-cogs {--file=cogs_reintegrate_ids.txt} {--dry-run} {--limit=0} {--offset=0}';

    protected $description = 'Re-post COGS journals (correct accounts) for the cancelled sales orders. Skips cancelled/returned. Idempotent.';

    /** omniful_status values that represent a real sale eligible for COGS. */
    private const SALE_STATUSES = ['delivered', 'shipped', 'partially_delivered', 'completed'];

    public function handle(SapServiceLayerClient $client): int
    {
        $dry = (bool) $this->option('dry-run');
        $limit = (int) $this->option('limit');
        $offset = (int) $this->option('offset');

        $path = storage_path('app/' . $this->option('file'));
        if (!is_file($path)) {
            $this->error('File not found: ' . $path);

            return self::FAILURE;
        }

        $settings = IntegrationSetting::query()->first();
        $expense = (string) ($settings->order_cogs_expense_account ?? '');
        $offsetAcct = (string) ($settings->order_cogs_inventory_offset_account ?? '');
        if ($expense === '' || $offsetAcct === '') {
            $this->error('COGS accounts not configured in integration settings');

            return self::FAILURE;
        }
        $this->info("Using expense={$expense} (Dr) / offset={$offsetAcct} (Cr)");

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

        $ids = array_values(array_filter(array_map('trim', file($path)), fn ($v) => $v !== '' && ctype_digit($v)));
        if ($offset > 0) {
            $ids = array_slice($ids, $offset);
        }
        if ($limit > 0) {
            $ids = array_slice($ids, 0, $limit);
        }

        $stats = ['seen' => 0, 'posted' => 0, 'skipped_nonsale' => 0, 'skipped_no_items' => 0, 'not_in_db' => 0, 'ignored' => 0, 'error' => 0];
        $i = $offset;

        foreach ($ids as $id) {
            $i++;
            $stats['seen']++;
            try {
                $order = OmnifulOrder::where('external_id', $id)->first();
                if ($order === null) {
                    $stats['not_in_db']++;

                    continue;
                }
                if (!in_array((string) $order->omniful_status, self::SALE_STATUSES, true)) {
                    $stats['skipped_nonsale']++;

                    continue;
                }

                $payload = (array) $order->last_payload;
                $data = (array) data_get($payload, 'data', $payload);
                $items = data_get($data, 'order_items') ?? data_get($data, 'items') ?? [];
                if (!is_array($items) || $items === []) {
                    $stats['skipped_no_items']++;

                    continue;
                }

                if ($dry) {
                    $stats['posted']++;

                    continue;
                }

                $res = $retry(fn () => $client->createCogsJournalForOmnifulOrder([
                    'reference' => (string) $id,
                    'external_id' => (string) $id,
                    'order_items' => $items,
                    'hub_code' => (string) ($order->hub_code ?: data_get($data, 'hub_code', '')),
                    'posting_date' => data_get($data, 'order_created_at') ?? data_get($data, 'created_at'),
                    'memo' => 'COGS journal from Omniful order ' . $id,
                    'expense_account' => $expense,
                    'offset_account' => $offsetAcct,
                ]));

                if (($res['ignored'] ?? false) === true) {
                    $stats['ignored']++;
                } elseif (!empty($res['JdtNum'])) {
                    $stats['posted']++;
                    $order->forceFill([
                        'sap_cogs_journal_entry' => (string) $res['JdtNum'],
                        'sap_cogs_journal_num' => (string) ($res['Number'] ?? $res['DocNum'] ?? ''),
                        'sap_cogs_status' => 'created',
                        'sap_cogs_error' => null,
                    ])->save();
                } else {
                    $stats['error']++;
                    Log::warning('COGS reintegrate: no JdtNum', ['order' => $id, 'res' => array_slice($res, 0, 5)]);
                }
            } catch (\Throwable $e) {
                $stats['error']++;
                Log::warning('COGS reintegrate row failed', ['order' => $id, 'error' => substr($e->getMessage(), 0, 200)]);
                usleep(500000);
            }

            if ($stats['seen'] % 25 === 0) {
                $this->info('progress @' . $i . ': ' . json_encode($stats, JSON_UNESCAPED_SLASHES));
            }
            usleep(100000);
        }

        $this->info(($dry ? '[DRY RUN] ' : '') . 'DONE @' . $i . ': ' . json_encode($stats, JSON_UNESCAPED_SLASHES));

        return self::SUCCESS;
    }
}
