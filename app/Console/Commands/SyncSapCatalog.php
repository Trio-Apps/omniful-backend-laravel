<?php

namespace App\Console\Commands;

use App\Services\SapCatalogSyncService;
use App\Services\SapServiceLayerClient;
use Illuminate\Console\Command;

class SyncSapCatalog extends Command
{
    protected $signature = 'sap:sync-catalog {resource?* : Optional resource keys to sync} {--list : List available resource keys}';

    protected $description = 'Sync configured SAP Service Layer resources into the local catalog table.';

    public function handle(SapCatalogSyncService $service, SapServiceLayerClient $client): int
    {
        if ((bool) $this->option('list')) {
            foreach ($service->definitions() as $resource => $definition) {
                $this->line($resource . ' => ' . (string) ($definition['path'] ?? ''));
            }

            return self::SUCCESS;
        }

        $resources = array_values(array_filter(
            array_map('strval', (array) $this->argument('resource')),
            fn ($value) => trim($value) !== ''
        ));

        try {
            $summary = $service->sync($client, $resources);
        } catch (\Throwable $e) {
            $this->error('SAP catalog sync failed: ' . $e->getMessage());
            return self::FAILURE;
        }

        $this->info('Resources synced: ' . (int) $summary['resources']);
        $this->line('Records upserted: ' . (int) $summary['records']);
        $this->line('Failed resources: ' . (int) $summary['failed']);

        foreach ((array) $summary['errors'] as $error) {
            $this->warn((string) $error);
        }

        return self::SUCCESS;
    }
}
