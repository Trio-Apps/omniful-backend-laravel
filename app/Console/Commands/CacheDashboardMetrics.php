<?php

namespace App\Console\Commands;

use App\Support\DashboardMetrics;
use Illuminate\Console\Command;

class CacheDashboardMetrics extends Command
{
    protected $signature = 'dashboard:cache-metrics';

    protected $description = 'Recompute and cache the heavy dashboard metrics (order/revenue scans) so the Filament widgets read instantly instead of scanning payloads per request.';

    public function handle(DashboardMetrics $metrics): int
    {
        $started = microtime(true);
        $metrics->warm();
        $this->info(sprintf('Dashboard metrics cached in %.1fs.', microtime(true) - $started));

        return self::SUCCESS;
    }
}
