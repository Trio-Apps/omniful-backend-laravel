<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Auto Sync (SAP -> Omniful) for items/suppliers. Runs every minute but only
// does work once per the interval configured on the Integration Settings page
// (and only when Auto Sync is enabled there). Requires the OS cron to run
// `php artisan schedule:run` every minute.
Schedule::command('omniful:auto-sync')->everyMinute()->withoutOverlapping(10);

// Pre-compute the heavy dashboard metrics (order/revenue payload scans) off the
// web request. The Filament widgets only READ the cache this writes, so the
// landing page stays instant instead of timing out (504) on a per-request scan.
Schedule::command('dashboard:cache-metrics')->everyFiveMinutes()->withoutOverlapping(10);
