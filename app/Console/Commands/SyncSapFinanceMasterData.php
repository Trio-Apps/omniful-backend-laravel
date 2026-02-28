<?php

namespace App\Console\Commands;

use App\Services\MasterData\SapFinanceMasterDataSyncService;
use App\Services\SapServiceLayerClient;
use Illuminate\Console\Command;

class SyncSapFinanceMasterData extends Command
{
    protected $signature = 'sap:sync-finance-master';

    protected $description = 'Sync SAP finance master data into dedicated local tables.';

    public function handle(SapFinanceMasterDataSyncService $service, SapServiceLayerClient $client): int
    {
        try {
            $summary = $service->syncFromSap($client);
        } catch (\Throwable $e) {
            $this->error('Finance master sync failed: ' . $e->getMessage());
            return self::FAILURE;
        }

        $this->info('Finance master data synced.');
        foreach ($summary as $key => $value) {
            $this->line($key . ': ' . $value);
        }

        return self::SUCCESS;
    }
}
