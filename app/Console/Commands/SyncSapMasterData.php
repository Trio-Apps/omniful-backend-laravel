<?php

namespace App\Console\Commands;

use App\Services\MasterData\SapItemSyncService;
use App\Services\MasterData\SapSupplierSyncService;
use App\Services\MasterData\SapWarehouseSyncService;
use App\Services\IntegrationDirectionService;
use App\Services\OmnifulApiClient;
use App\Services\SapServiceLayerClient;
use Illuminate\Console\Command;

class SyncSapMasterData extends Command
{
    protected $signature = 'sap:sync-master {--warehouses} {--suppliers} {--items} {--push}';

    protected $description = 'Sync SAP master data into local tables and optionally push to Omniful';

    public function handle(
        SapServiceLayerClient $sapClient,
        OmnifulApiClient $omnifulClient,
        SapWarehouseSyncService $warehouseService,
        SapSupplierSyncService $supplierService,
        SapItemSyncService $itemService,
        IntegrationDirectionService $directionService
    ): int {
        $syncWarehouses = (bool) $this->option('warehouses');
        $syncSuppliers = (bool) $this->option('suppliers');
        $syncItems = (bool) $this->option('items');
        $pushToOmniful = (bool) $this->option('push');

        if (!$syncWarehouses && !$syncSuppliers && !$syncItems) {
            $syncWarehouses = true;
            $syncSuppliers = true;
            $syncItems = true;
        }

        try {
            if ($syncWarehouses) {
                if ($directionService->isSapToOmniful('warehouses')) {
                    $this->info('Syncing SAP warehouses...');
                    $warehouseService->syncFromSap($sapClient);
                } else {
                    $this->info('Syncing Omniful warehouses to SAP...');
                    $this->printPushSummary($warehouseService->syncFromOmniful($omnifulClient, $sapClient));
                }
            }

            if ($syncSuppliers) {
                if ($directionService->isSapToOmniful('suppliers')) {
                    $this->info('Syncing SAP suppliers...');
                    $supplierService->syncFromSap($sapClient);
                } else {
                    $this->info('Syncing Omniful suppliers to SAP...');
                    $this->printPushSummary($supplierService->syncFromOmniful($omnifulClient, $sapClient));
                }
            }

            if ($syncItems) {
                if ($directionService->isSapToOmniful('items')) {
                    $this->info('Syncing SAP items...');
                    $itemService->syncFromSap($sapClient);
                } else {
                    $this->info('Syncing Omniful items to SAP...');
                    $this->printPushSummary($itemService->syncFromOmniful($omnifulClient, $sapClient));
                }
            }

            if ($pushToOmniful) {
                if ($syncWarehouses && $directionService->isSapToOmniful('warehouses')) {
                    $this->info('Pushing warehouses to Omniful...');
                    $this->printPushSummary($warehouseService->pushToOmniful($omnifulClient));
                }

                if ($syncSuppliers && $directionService->isSapToOmniful('suppliers')) {
                    $this->info('Pushing suppliers to Omniful...');
                    $this->printPushSummary($supplierService->pushToOmniful($omnifulClient));
                }

                if ($syncItems && $directionService->isSapToOmniful('items')) {
                    $this->info('Pushing items to Omniful...');
                    $this->printPushSummary($itemService->pushToOmniful($omnifulClient));
                }
            }
        } catch (\Throwable $e) {
            $this->error('Sync failed: ' . $e->getMessage());
            return self::FAILURE;
        }

        $this->info('Done.');

        return self::SUCCESS;
    }

    private function printPushSummary(array $result): void
    {
        $ok = (int) ($result['ok'] ?? 0);
        $failed = (int) ($result['failed'] ?? 0);
        $this->line('Synced: ' . $ok . ' | Failed: ' . $failed);

        $errors = array_slice((array) ($result['errors'] ?? []), 0, 5);
        foreach ($errors as $error) {
            $this->warn((string) $error);
        }
    }
}
