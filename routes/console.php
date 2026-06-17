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
