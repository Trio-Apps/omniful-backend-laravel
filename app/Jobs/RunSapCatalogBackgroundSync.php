<?php

namespace App\Jobs;

use App\Models\SapSyncEvent;
use App\Services\MasterData\SapBankingCatalogSyncService;
use App\Services\MasterData\SapFinanceDocumentSyncService;
use App\Services\MasterData\SapFinanceMasterDataSyncService;
use App\Services\MasterData\SapInventoryCatalogSyncService;
use App\Services\MasterData\SapSalesCatalogSyncService;
use App\Services\SapServiceLayerClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class RunSapCatalogBackgroundSync implements ShouldQueue
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
        SapFinanceMasterDataSyncService $financeMasterDataSync,
        SapFinanceDocumentSyncService $financeDocumentSync,
        SapSalesCatalogSyncService $salesCatalogSync,
        SapInventoryCatalogSyncService $inventoryCatalogSync,
        SapBankingCatalogSyncService $bankingCatalogSync
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
            $financeMaster = $financeMasterDataSync->syncFromSap($client);
            $financeDocuments = $financeDocumentSync->syncFromSap($client);
            $sales = $salesCatalogSync->syncFromSap($client);
            $inventory = $inventoryCatalogSync->syncFromSap($client);
            $banking = $bankingCatalogSync->syncFromSap($client);

            $summary = [
                'finance_master_total' => (int) ($financeMaster['total'] ?? 0),
                'finance_documents_total' => (int) ($financeDocuments['total'] ?? 0),
                'sales_total' => (int) ($sales['total'] ?? 0),
                'inventory_total' => (int) ($inventory['total'] ?? 0),
                'banking_total' => (int) ($banking['total'] ?? 0),
            ];

            $event->update([
                'sap_status' => 'completed',
                'sap_error' => null,
                'payload' => array_merge($basePayload, [
                    'started_at' => $basePayload['started_at'] ?? now()->toDateTimeString(),
                    'finished_at' => now()->toDateTimeString(),
                    'summary' => $summary,
                    'details' => [
                        'finance_master' => $financeMaster,
                        'finance_documents' => $financeDocuments,
                        'sales' => $sales,
                        'inventory' => $inventory,
                        'banking' => $banking,
                    ],
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
