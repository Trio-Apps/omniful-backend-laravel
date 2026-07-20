<?php

namespace App\Console\Commands;

use App\Services\OmnifulOrderBackfillService;
use Illuminate\Console\Command;

class BackfillOmnifulOrders extends Command
{
    protected $signature = 'omniful:order-backfill {from : Created-from date (Y-m-d)} {to : Created-to date (Y-m-d)}';

    protected $description = 'Pull orders from Omniful for a created-date range and enqueue the ones missing from our DB';

    public function handle(OmnifulOrderBackfillService $service): int
    {
        $run = $service->startRun((string) $this->argument('from'), (string) $this->argument('to'));

        $this->info(sprintf(
            'Backfill run #%d queued for %s → %s (watch it on the Order Backfill page or the omniful_order_backfill_runs table).',
            $run->id,
            $run->date_from->format('Y-m-d'),
            $run->date_to->format('Y-m-d'),
        ));

        return self::SUCCESS;
    }
}
