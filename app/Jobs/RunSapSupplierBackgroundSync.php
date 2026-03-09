<?php

namespace App\Jobs;

use App\Models\SapSyncEvent;
use App\Services\IntegrationDirectionService;
use App\Services\MasterData\SapSupplierSyncService;
use App\Services\OmnifulApiClient;
use App\Services\SapServiceLayerClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class RunSapSupplierBackgroundSync implements ShouldQueue
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
        OmnifulApiClient $omnifulClient,
        SapSupplierSyncService $supplierSync,
        IntegrationDirectionService $direction
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
            if ($direction->isSapToOmniful('suppliers')) {
                $details = $supplierSync->syncFromSap($client);
                $mode = 'sap_to_local';
            } else {
                $details = $supplierSync->syncFromOmniful($omnifulClient, $client);
                $mode = 'omniful_to_sap';
            }

            $summary = [
                'mode' => $mode,
                'total' => (int) ($details['total'] ?? (($details['ok'] ?? 0) + ($details['failed'] ?? 0))),
                'synced' => (int) ($details['synced'] ?? ($details['ok'] ?? 0)),
                'pending' => (int) ($details['pending'] ?? 0),
                'skipped' => (int) ($details['skipped'] ?? 0),
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
}
