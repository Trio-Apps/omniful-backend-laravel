<?php

namespace App\Console\Commands;

use App\Services\MasterData\SapFinanceDocumentSyncService;
use App\Services\SapServiceLayerClient;
use Illuminate\Console\Command;

class SyncSapFinanceDocuments extends Command
{
    protected $signature = 'sap:sync-finance-docs';

    protected $description = 'Sync SAP finance document snapshots into a dedicated local table.';

    public function handle(SapFinanceDocumentSyncService $service, SapServiceLayerClient $client): int
    {
        try {
            $summary = $service->syncFromSap($client);
        } catch (\Throwable $e) {
            $this->error('Finance document sync failed: ' . $e->getMessage());
            return self::FAILURE;
        }

        $this->info('Finance documents synced.');
        foreach ($summary as $key => $value) {
            $this->line($key . ': ' . $value);
        }

        return self::SUCCESS;
    }
}
