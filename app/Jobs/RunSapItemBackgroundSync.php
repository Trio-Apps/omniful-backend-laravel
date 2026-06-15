<?php

namespace App\Jobs;

use App\Models\SapSyncEvent;
use App\Services\MasterData\SapItemIntegrationService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class RunSapItemBackgroundSync implements ShouldQueue
{
    use Queueable;

    public int $timeout = 3600;

    public int $tries = 1;

    public function __construct(
        public int $syncEventId
    ) {
    }

    public function handle(
        SapItemIntegrationService $integration
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
            // Agreed flow: read only OITM items flagged not-integrated
            // (U_omInt = N), integrate each as a SKU (inventory item) or a KIT
            // (sales-only combo from ZIDCOMBO), then stamp the flag(s) to Y.
            $details = $integration->run();

            $summary = [
                'mode' => 'sap_to_omniful_integration',
                'total' => (int) ($details['total'] ?? 0),
                'skus_created' => (int) ($details['skus_created'] ?? 0),
                'kits_created' => (int) ($details['kits_created'] ?? 0),
                'ignored_no_combo' => (int) ($details['ignored_no_combo'] ?? 0),
                'skipped' => (int) ($details['skipped_other'] ?? 0),
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
