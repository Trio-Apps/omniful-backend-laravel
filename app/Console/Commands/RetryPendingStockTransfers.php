<?php

namespace App\Console\Commands;

use App\Models\OmnifulStockTransferEvent;
use App\Services\Webhooks\StockTransferWebhookService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Re-process stock transfer events that were DEFERRED because Omniful had not
 * yet reported the received quantities.
 *
 * The STO webhook arrives the moment the receipt is confirmed — sometimes seconds
 * before Omniful's own STO endpoint reflects the received quantities. Rather than
 * guess (the old code fell back to the APPROVED quantity and moved phantom stock),
 * the webhook now marks the event `pending_receipt` and this command retries it
 * until the real quantities are readable.
 *
 * Safe to run on a schedule: the per-STO lock and the SAP-side duplicate guard in
 * the service prevent a double post, and an event that still cannot be resolved
 * simply stays pending.
 */
class RetryPendingStockTransfers extends Command
{
    protected $signature = 'omniful:retry-pending-stock-transfers {--limit=50} {--min-age=60}';

    protected $description = 'Retry stock transfer events deferred because Omniful had not reported the received quantities yet.';

    public function handle(StockTransferWebhookService $service): int
    {
        $limit = max(1, (int) $this->option('limit'));
        // Give Omniful a moment to book the receipt before the first retry.
        $minAge = max(0, (int) $this->option('min-age'));

        $events = OmnifulStockTransferEvent::query()
            ->where('sap_status', 'pending_receipt')
            ->where('updated_at', '<=', now()->subSeconds($minAge))
            ->orderBy('id')
            ->limit($limit)
            ->get();

        if ($events->isEmpty()) {
            $this->info('No deferred stock transfers to retry.');

            return self::SUCCESS;
        }

        $stats = ['seen' => 0, 'posted' => 0, 'still_pending' => 0, 'other' => 0, 'error' => 0];

        foreach ($events as $event) {
            $stats['seen']++;
            $sto = (string) ($event->external_id ?? '');

            try {
                $service->process($event);
                $event->refresh();

                $status = (string) $event->sap_status;
                if (in_array($status, ['created', 'created_via_transit'], true)) {
                    $stats['posted']++;
                    $this->line('  posted ' . $sto . ' -> SAP ' . (string) $event->sap_doc_num);
                } elseif ($status === 'pending_receipt') {
                    $stats['still_pending']++;
                } else {
                    $stats['other']++;
                    $this->line('  ' . $sto . ' -> ' . $status);
                }
            } catch (\Throwable $e) {
                $stats['error']++;
                Log::warning('Deferred stock transfer retry failed', [
                    'sto' => $sto,
                    'error' => substr($e->getMessage(), 0, 300),
                ]);
                $this->warn('  ! ' . $sto . ': ' . substr($e->getMessage(), 0, 160));
            }

            usleep(300000);
        }

        $this->info('DONE: ' . json_encode($stats, JSON_UNESCAPED_SLASHES));

        return self::SUCCESS;
    }
}
