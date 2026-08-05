<?php

namespace App\Console\Commands;

use App\Services\SapServiceLayerClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Repair pass for omniful:cancel-cogs-jes: that command cancelled some COGS JEs
 * that had ALREADY been reversed by an earlier manual COGS-reversal (which does
 * NOT set StornoDate on the original, so it was invisible), leaving those orders
 * DOUBLE-reversed — a NEGATIVE balance on account 1105002.
 *
 * For every order in the list this recomputes its net on 1105002; when the net is
 * negative it posts a correcting JE (Debit 1105002 / Credit 1105001 for the exact
 * shortfall, copying the original cost centers) to bring the order back to zero,
 * tagged "COGS-<order>-correction". Idempotent (skips orders already at >= 0) and
 * resilient to transient SAP TLS/timeout errors.
 */
class FixCogsOverReversed extends Command
{
    protected $signature = 'omniful:fix-cogs-overreversed {--file=cogs_je_list.csv} {--dry-run} {--limit=0} {--offset=0}';

    protected $description = 'Post correcting JEs to restore account 1105002 to zero for orders double-reversed by omniful:cancel-cogs-jes.';

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

        $get = new \ReflectionMethod($client, 'get');
        $get->setAccessible(true);
        $post = new \ReflectionMethod($client, 'post');
        $post->setAccessible(true);
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

        // Distinct numeric order ids from the CSV (column 2 = omni), preserving order.
        $rows = array_values(array_filter(array_map('trim', file($path)), fn ($l) => $l !== ''));
        if ($rows && stripos($rows[0], 'origin') === 0) {
            array_shift($rows);
        }
        $orders = [];
        foreach ($rows as $line) {
            $omni = trim((string) (explode(',', $line)[1] ?? ''));
            if ($omni !== '' && ctype_digit($omni)) {
                $orders[$omni] = true;
            }
        }
        $orders = array_keys($orders);
        if ($offset > 0) {
            $orders = array_slice($orders, $offset);
        }
        if ($limit > 0) {
            $orders = array_slice($orders, 0, $limit);
        }

        $stats = ['seen' => 0, 'ok_zero' => 0, 'corrected' => 0, 'already_corrected' => 0, 'positive' => 0, 'error' => 0];
        $idx = $offset;

        foreach ($orders as $order) {
            $idx++;
            $stats['seen']++;
            try {
                $jes = (array) ($retry(fn () => $get->invoke($client, "/JournalEntries?{$S}filter=Reference eq '{$order}'&{$S}top=40"))->json('value') ?? []);

                $net = 0.0;
                $hasCorrection = false;
                $fwdLines = null;
                foreach ($jes as $je) {
                    if (stripos((string) ($je['Reference2'] ?? ''), '-correction') !== false) {
                        $hasCorrection = true;
                    }
                    $touches1105002 = false;
                    foreach ((array) ($je['JournalEntryLines'] ?? []) as $l) {
                        if ((string) ($l['AccountCode'] ?? '') === '1105002') {
                            $net += ((float) ($l['Debit'] ?? 0) - (float) ($l['Credit'] ?? 0));
                            $touches1105002 = true;
                        }
                    }
                    // Remember a forward-shaped JE (debits 1105002) to copy cost centers.
                    if ($fwdLines === null && $touches1105002) {
                        foreach ((array) ($je['JournalEntryLines'] ?? []) as $l) {
                            if ((string) ($l['AccountCode'] ?? '') === '1105002' && (float) ($l['Debit'] ?? 0) > 0) {
                                $fwdLines = (array) $je['JournalEntryLines'];
                            }
                        }
                    }
                }

                if ($net > 0.01) {
                    $stats['positive']++; // under-reversed — leave it (not our over-reversal)

                    continue;
                }
                if (abs($net) < 0.01) {
                    $stats['ok_zero']++;

                    continue;
                }
                if ($hasCorrection) {
                    $stats['already_corrected']++;

                    continue;
                }

                // net < 0 → post a correcting Debit 1105002 / Credit 1105001 for |net|.
                $amount = round(abs($net), 2);
                $cc = $this->costCentersFrom($fwdLines);
                $lines = [
                    array_merge(['AccountCode' => '1105002', 'Debit' => $amount], $cc),
                    array_merge(['AccountCode' => '1105001', 'Credit' => $amount], $cc),
                ];

                if ($dry) {
                    $this->line("[DRY] order {$order}: net={$net} would correct +{$amount}");
                    $stats['corrected']++;

                    continue;
                }

                $today = now()->format('Y-m-d');
                $body = [
                    'ReferenceDate' => $today,
                    'DueDate' => $today,
                    'TaxDate' => $today,
                    'Memo' => 'COGS correction: restore 1105002 after double reversal (order ' . $order . ')',
                    'Reference' => (string) $order,
                    'Reference2' => 'COGS-' . $order . '-correction',
                    'JournalEntryLines' => $lines,
                ];
                $res = $retry(fn () => $post->invoke($client, '/JournalEntries', $body));
                if ($res->successful() || $res->status() === 201) {
                    $stats['corrected']++;
                } else {
                    $stats['error']++;
                    Log::warning('COGS correction JE failed', ['order' => $order, 'status' => $res->status(), 'body' => substr((string) $res->body(), 0, 300)]);
                }
            } catch (\Throwable $e) {
                $stats['error']++;
                Log::warning('COGS overreversed fix row failed', ['order' => $order, 'error' => substr($e->getMessage(), 0, 200)]);
                usleep(500000);
            }

            if ($stats['seen'] % 25 === 0) {
                $this->info('progress @' . $idx . ': ' . json_encode($stats, JSON_UNESCAPED_SLASHES));
            }
            usleep(100000);
        }

        $this->info(($dry ? '[DRY RUN] ' : '') . 'DONE @' . $idx . ': ' . json_encode($stats, JSON_UNESCAPED_SLASHES));

        return self::SUCCESS;
    }

    /**
     * @param array<int,array<string,mixed>>|null $fwdLines
     * @return array<string,string>
     */
    private function costCentersFrom(?array $fwdLines): array
    {
        foreach ((array) $fwdLines as $l) {
            $cc = [];
            foreach (['CostingCode', 'CostingCode2', 'CostingCode3'] as $f) {
                if (!empty($l[$f])) {
                    $cc[$f] = (string) $l[$f];
                }
            }
            if ($cc !== []) {
                return $cc;
            }
        }

        return ['CostingCode' => 'CEN011', 'CostingCode2' => 'CEN11'];
    }
}
