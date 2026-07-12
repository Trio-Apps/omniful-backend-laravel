<?php

namespace App\Jobs;

use App\Models\SapSyncEvent;
use App\Services\OmnifulApiClient;
use App\Services\SapInventoryQtyPushService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class RunSapInventoryQtyPush implements ShouldQueue
{
    use Queueable;

    public int $timeout = 3600;

    public int $tries = 1;

    public function __construct(
        public int $syncEventId
    ) {
    }

    public function handle(
        OmnifulApiClient $client,
        SapInventoryQtyPushService $service
    ): void {
        $event = SapSyncEvent::find($this->syncEventId);
        if ($event === null) {
            return;
        }

        $basePayload = (array) ($event->payload ?? []);
        if ($event->sap_status === 'cancel_requested') {
            $event->update([
                'sap_status' => 'cancelled',
                'sap_error' => 'Push stopped by user request.',
                'payload' => array_merge($basePayload, [
                    'finished_at' => now()->toDateTimeString(),
                ]),
            ]);

            return;
        }

        $event->update([
            'sap_status' => 'running',
            'sap_error' => null,
            'payload' => array_merge($basePayload, [
                'started_at' => now()->toDateTimeString(),
            ]),
        ]);

        try {
            $details = $service->pushToOmniful($client, $event);

            // Re-read so the live progress the service wrote is preserved.
            $event->refresh();
            $freshPayload = (array) ($event->payload ?? []);

            $summary = [
                'pushed' => (int) ($details['ok'] ?? 0),
                'failed' => (int) ($details['failed'] ?? 0),
                'skipped' => (int) ($details['skipped'] ?? 0),
            ];
            $finalStatus = !empty($details['cancelled']) ? 'cancelled' : 'completed';

            $event->update([
                'sap_status' => $finalStatus,
                'sap_error' => !empty($details['cancelled']) ? 'Push stopped by user request.' : null,
                'payload' => array_merge($freshPayload, [
                    'started_at' => $freshPayload['started_at'] ?? now()->toDateTimeString(),
                    'finished_at' => now()->toDateTimeString(),
                    'summary' => $summary,
                    'details' => $details,
                ]),
            ]);
        } catch (\Throwable $exception) {
            $event->refresh();
            $event->update([
                'sap_status' => 'failed',
                'sap_error' => $exception->getMessage(),
                'payload' => array_merge((array) ($event->payload ?? []), [
                    'finished_at' => now()->toDateTimeString(),
                ]),
            ]);

            throw $exception;
        }
    }

    public function failed(\Throwable $exception): void
    {
        $event = SapSyncEvent::find($this->syncEventId);
        if ($event === null || $event->sap_status === 'failed') {
            return;
        }

        $payload = (array) ($event->payload ?? []);
        $event->update([
            'sap_status' => 'failed',
            'sap_error' => $exception->getMessage(),
            'payload' => array_merge($payload, [
                'finished_at' => now()->toDateTimeString(),
            ]),
        ]);
    }
}
