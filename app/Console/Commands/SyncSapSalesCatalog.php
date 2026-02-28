<?php

namespace App\Console\Commands;

use App\Services\MasterData\SapSalesCatalogSyncService;
use App\Services\SapServiceLayerClient;
use Illuminate\Console\Command;

class SyncSapSalesCatalog extends Command
{
    protected $signature = 'sap:sync-sales-catalog';

    protected $description = 'Sync SAP sales snapshots for quotations, returns, and item groups.';

    public function handle(SapSalesCatalogSyncService $service, SapServiceLayerClient $client): int
    {
        try {
            $summary = $service->syncFromSap($client);
        } catch (\Throwable $e) {
            $this->error('Sales catalog sync failed: ' . $e->getMessage());
            return self::FAILURE;
        }

        $this->info('Sales catalog synced.');
        foreach ($summary as $key => $value) {
            $this->line($key . ': ' . $value);
        }

        return self::SUCCESS;
    }
}
