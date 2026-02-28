<?php

namespace App\Console\Commands;

use App\Services\MasterData\SapBankingCatalogSyncService;
use App\Services\SapServiceLayerClient;
use Illuminate\Console\Command;

class SyncSapBankingCatalog extends Command
{
    protected $signature = 'sap:sync-banking-catalog';

    protected $description = 'Sync SAP banking snapshots for deposits and checks for payment.';

    public function handle(SapBankingCatalogSyncService $service, SapServiceLayerClient $client): int
    {
        try {
            $summary = $service->syncFromSap($client);
        } catch (\Throwable $e) {
            $this->error('Banking catalog sync failed: ' . $e->getMessage());
            return self::FAILURE;
        }

        $this->info('Banking catalog synced.');
        foreach ($summary as $key => $value) {
            $this->line($key . ': ' . $value);
        }

        return self::SUCCESS;
    }
}
