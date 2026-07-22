<?php

namespace App\Jobs;

use App\Models\OmnifulOrderBackfillRun;
use App\Services\OmnifulOrderBackfillService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Drives ONE backfill run, a few list pages at a time. After each batch it
 * re-dispatches a continuation of itself (carrying the run id) until the range
 * is exhausted or the run is cancelled — so a month-long backfill is a chain of
 * short, restart-safe jobs rather than one giant job. Runs on its own queue
 * (order_backfill.queue) with a single worker, so the chain is serialized.
 */
class RunOmnifulOrderBackfill implements ShouldQueue
{
    use Queueable;

    /** Per-batch retry budget for transient Omniful list-fetch failures. */
    public int $tries = 8;

    /** Kept under the queue retry_after so a batch is never double-run. */
    public int $timeout = 900;

    /** @return array<int,int> */
    public function backoff(): array
    {
        return [10, 30, 60, 120, 300];
    }

    public function __construct(public int $runId)
    {
        $this->onQueue((string) config('omniful.order_backfill.queue', 'omniful-backfill'));
    }

    public function handle(OmnifulOrderBackfillService $service): void
    {
        $run = OmnifulOrderBackfillRun::find($this->runId);
        if ($run === null || !$run->isActive()) {
            return;
        }

        if ((string) $run->source_type === 'id_list') {
            $service->runIdBatch($run);
        } else {
            $service->runBatch($run);
        }

        $run->refresh();
        if (in_array($run->status, ['running', 'queued'], true)) {
            // More pages remain — continue as a fresh job (fresh retry budget).
            self::dispatch($this->runId)
                ->onQueue((string) config('omniful.order_backfill.queue', 'omniful-backfill'));
        }
    }

    public function failed(\Throwable $e): void
    {
        $run = OmnifulOrderBackfillRun::find($this->runId);
        if ($run !== null && $run->isActive()) {
            $run->update([
                'status' => 'failed',
                'finished_at' => now(),
                'last_error' => substr($e->getMessage(), 0, 500),
                'last_activity' => 'failed',
            ]);
        }
        Log::error('Order backfill run failed', ['run_id' => $this->runId, 'error' => $e->getMessage()]);
    }
}
