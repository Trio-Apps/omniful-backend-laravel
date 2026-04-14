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
        public int $eventId
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
            $service->process($event);
            return;
        }

        $lock = Cache::lock('omniful-order-processing:' . $externalId, 300);
        if (!$lock->get()) {
            $this->release(10);
            return;
        }

        try {
            OmnifulOrder::where('external_id', $externalId)->update([
                'sap_status' => 'retrying',
                'sap_error' => null,
            ]);

            $service->process($event);
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
