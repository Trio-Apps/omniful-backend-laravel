<?php

namespace App\Console\Commands;

use App\Services\SapServiceLayerClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * One-off: cancel (reverse) the COGS journal entries listed in a CSV (account
 * 1105002 "بضاعة وسيط تحت التحضير"). Each row is `origin,omni` where `origin` is
 * the JE Number (the account-report "Origin No.") and `omni` the Omniful order id.
 *
 * Per JE: resolve JdtNum by Number → SAP `Cancel` action (posts an exact storno
 * that nets 1105002 back to zero) → stamp the original's Reference2 with a
 * "-reversed" suffix (our standard cancelled marker, so a re-run skips it and
 * COGS idempotency never rebinds it). READ nothing else — the order, its invoice,
 * payment, delivery are untouched; ONLY the COGS JE is reversed.
 *
 * Idempotent + resumable: JEs already carrying "reversed" in Reference2 are
 * skipped, and JEs SAP reports as "already been canceled" are counted + marked,
 * so the command can be re-run safely until every listed JE is reversed.
 */
class CancelCogsJournals extends Command
{
    protected $signature = 'omniful:cancel-cogs-jes {--file=cogs_je_list.csv} {--dry-run} {--limit=0} {--offset=0}';

    protected $description = 'Cancel/reverse the COGS journal entries in the given CSV (account 1105002), marking each Reference2 "-reversed". Idempotent.';

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
        $patch = new \ReflectionMethod($client, 'patch');
        $patch->setAccessible(true);
        $S = '$';

        $rows = array_values(array_filter(array_map('trim', file($path)), fn ($l) => $l !== ''));
        if ($rows && stripos($rows[0], 'origin') === 0) {
            array_shift($rows); // header
        }
        if ($offset > 0) {
            $rows = array_slice($rows, $offset);
        }
        if ($limit > 0) {
            $rows = array_slice($rows, 0, $limit);
        }

        $stats = ['seen' => 0, 'cancelled' => 0, 'already_reversed' => 0, 'skipped_marked' => 0, 'not_found' => 0, 'error' => 0];
        $row = $offset;

        foreach ($rows as $line) {
            $row++;
            [$origin] = array_pad(explode(',', $line), 2, '');
            $origin = trim($origin);
            if ($origin === '' || !ctype_digit($origin)) {
                continue;
            }
            $stats['seen']++;

            $je = (array) data_get(
                $get->invoke($client, "/JournalEntries?{$S}filter=Number eq {$origin}&{$S}select=JdtNum,Number,Reference2&{$S}top=1")->json(),
                'value.0',
                []
            );
            if ($je === [] || empty($je['JdtNum'])) {
                $stats['not_found']++;

                continue;
            }
            $jdt = (int) $je['JdtNum'];
            $ref2 = (string) ($je['Reference2'] ?? '');

            // Already reversed by us (marker present) → skip.
            if (stripos($ref2, 'reversed') !== false || stripos($ref2, '-rev') !== false) {
                $stats['skipped_marked']++;

                continue;
            }

            if ($dry) {
                $stats['cancelled']++;

                continue;
            }

            // Reverse: SAP Cancel posts an exact storno (nets 1105002 to zero).
            $cancel = $post->invoke($client, "/JournalEntries({$jdt})/Cancel", (object) []);
            $body = strtolower((string) $cancel->body());
            if ($cancel->successful() || $cancel->status() === 204) {
                $stats['cancelled']++;
            } elseif (str_contains($body, 'already been cancel')) {
                // An older reversal already offsets it — treat as done + mark it.
                $stats['already_reversed']++;
            } else {
                $stats['error']++;
                Log::warning('COGS JE cancel failed', ['jdt' => $jdt, 'number' => $origin, 'status' => $cancel->status(), 'body' => substr((string) $cancel->body(), 0, 300)]);

                continue; // do NOT mark — it wasn't reversed
            }

            // Stamp the "-reversed" marker (best-effort; the storno is the real state).
            $mark = $patch->invoke($client, "/JournalEntries({$jdt})", ['Reference2' => $ref2 . '-reversed']);
            if (!$mark->successful() && $mark->status() !== 204) {
                Log::info('COGS JE mark-reversed non-204', ['jdt' => $jdt, 'status' => $mark->status()]);
            }

            if ($stats['seen'] % 25 === 0) {
                $this->info('progress @row ' . $row . ': ' . json_encode($stats, JSON_UNESCAPED_SLASHES));
            }
        }

        $this->info(($dry ? '[DRY RUN] ' : '') . 'DONE @row ' . $row . ': ' . json_encode($stats, JSON_UNESCAPED_SLASHES));

        return self::SUCCESS;
    }
}
