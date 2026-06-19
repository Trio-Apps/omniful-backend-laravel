<?php

namespace App\Jobs;

use App\Models\OmnifulOrder;
use App\Models\OmnifulOrderEvent;
use App\Services\Webhooks\OrderWebhookService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class ProcessOmnifulOrderEvent implements ShouldQueue
{
    use Queueable;

    public int $timeout = 900;

    public int $tries = 3;

    public function __construct(
        public int $eventId,
        public bool $force = false,
    ) {
        $this->onQueue('omniful-orders');
    }

    public function handle(OrderWebhookService $service): void
    {
        $event = OmnifulOrderEvent::find($this->eventId);
        if ($event === null) {
            return;
        }

        $externalId = trim((string) ($event->external_id ?? ''));
        if ($externalId === '') {
            $service->process($event, $this->force);
            return;
        }

        // Lock TTL must match (or exceed) the job timeout, otherwise a long-running
        // worker can lose the lock mid-processing and a parallel worker can pick up
        // the same external_id — causing duplicate SAP AR reserve invoice POSTs.
        $lock = Cache::lock('omniful-order-processing:' . $externalId, $this->timeout);
        if (!$lock->get()) {
            $this->release(10);
            return;
        }

        try {
            // Re-read the event/order under the lock to avoid acting on stale data
            // produced by a sibling worker that just finished for the same order.
            $event->refresh();

            // A manual force-resend skips the no-op classification gate and runs
            // the full SAP flow (re-bind existing docs, complete missing steps,
            // or recreate only if the invoice was removed from SAP).
            if (!$this->force) {
                $classification = $service->classifyEventForProcessing($event);
                if (!($classification['queue'] ?? false)) {
                    $service->applyNoOpEventOutcome($event);
                    return;
                }
            }

            OmnifulOrder::where('external_id', $externalId)->update([
                'sap_status' => 'running',
                'sap_error' => null,
            ]);

            $service->process($event, $this->force);
        } catch (\Throwable $e) {
            Log::error('Queued SAP order sync failed', [
                'event_id' => $event->id,
                'external_id' => $externalId,
                'error' => $e->getMessage(),
            ]);

            OmnifulOrder::where('external_id', $externalId)->update([
                'sap_status' => 'failed',
                'sap_error' => $e->getMessage(),
            ]);

            throw $e;
        } finally {
            optional($lock)->release();
        }
    }
}
