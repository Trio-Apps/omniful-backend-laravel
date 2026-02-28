<?php

namespace App\Console\Commands;

use App\Services\MasterData\SapInventoryCatalogSyncService;
use App\Services\SapServiceLayerClient;
use Illuminate\Console\Command;

class SyncSapInventoryCatalog extends Command
{
    protected $signature = 'sap:sync-inventory-catalog';

    protected $description = 'Sync SAP inventory snapshots for requests, countings, postings, and production orders.';

    public function handle(SapInventoryCatalogSyncService $service, SapServiceLayerClient $client): int
    {
        try {
            $summary = $service->syncFromSap($client);
        } catch (\Throwable $e) {
            $this->error('Inventory catalog sync failed: ' . $e->getMessage());
            return self::FAILURE;
        }

        $this->info('Inventory catalog synced.');
        foreach ($summary as $key => $value) {
            $this->line($key . ': ' . $value);
        }

        return self::SUCCESS;
    }
}
