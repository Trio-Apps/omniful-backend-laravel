<?php

namespace App\Console\Commands;

use App\Services\SapCatalogBackgroundSyncService;
use Illuminate\Console\Command;

class QueueSapCatalogSync extends Command
{
    protected $signature = 'sap:queue-catalog-sync';

    protected $description = 'Queue a full SAP catalog sync as a background job.';

    public function handle(SapCatalogBackgroundSyncService $dispatcher): int
    {
        $result = $dispatcher->dispatch('console');
        $event = $result['event'];

        if ((bool) $result['already_running']) {
            $this->warn('SAP catalog sync is already queued or running: ' . $event->event_key);
            return self::SUCCESS;
        }

        $this->info('SAP catalog sync queued: ' . $event->event_key);
        return self::SUCCESS;
    }
}
