<?php

namespace App\Jobs;

use App\Models\SapSyncEvent;
use App\Services\MasterData\SapCostCenterSyncService;
use App\Services\SapServiceLayerClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class RunSapCostCenterBackgroundSync implements ShouldQueue
{
    use Queueable;

    public int $timeout = 3600;

    public int $tries = 1;

    public function __construct(
        public int $syncEventId
    ) {
    }

    public function handle(
        SapServiceLayerClient $client,
        SapCostCenterSyncService $costCenterSync
    ): void {
        $event = SapSyncEvent::find($this->syncEventId);
        if ($event === null) {
            return;
        }

        $basePayload = (array) ($event->payload ?? []);
        $event->update([
            'sap_status' => 'running',
            'sap_error' => null,
            'payload' => array_merge($basePayload, [
                'started_at' => now()->toDateTimeString(),
            ]),
        ]);

        try {
            $summary = $costCenterSync->syncFromSap($client);

            $event->update([
                'sap_status' => 'completed',
                'sap_error' => null,
                'payload' => array_merge($basePayload, [
                    'started_at' => $basePayload['started_at'] ?? now()->toDateTimeString(),
                    'finished_at' => now()->toDateTimeString(),
                    'summary' => $summary,
                ]),
            ]);
        } catch (\Throwable $exception) {
            $event->update([
                'sap_status' => 'failed',
                'sap_error' => $exception->getMessage(),
                'payload' => array_merge($basePayload, [
                    'finished_at' => now()->toDateTimeString(),
                ]),
            ]);

            throw $exception;
        }
    }

    public function failed(\Throwable $exception): void
    {
        $event = SapSyncEvent::find($this->syncEventId);
        if ($event === null) {
            return;
        }

        if ($event->sap_status !== 'failed') {
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
}
