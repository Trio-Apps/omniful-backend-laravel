<?php

namespace App\Jobs;

use App\Models\SapSyncEvent;
use App\Services\MasterData\SapItemSyncService;
use App\Services\OmnifulApiClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class RunSapItemBackgroundPush implements ShouldQueue
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
        SapItemSyncService $itemSync
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
            $details = $itemSync->pushToOmniful($client);
            $summary = [
                'synced' => (int) ($details['ok'] ?? 0),
                'failed' => (int) ($details['failed'] ?? 0),
            ];

            $event->update([
                'sap_status' => 'completed',
                'sap_error' => null,
                'payload' => array_merge($basePayload, [
                    'started_at' => $basePayload['started_at'] ?? now()->toDateTimeString(),
                    'finished_at' => now()->toDateTimeString(),
                    'summary' => $summary,
                    'details' => $details,
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
