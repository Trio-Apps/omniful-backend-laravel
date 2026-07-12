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

// SAP -> Omniful inventory quantity push. Runs every minute, but the command
// self-gates on omniful.inventory_push.enabled and the configured cadence, so
// it no-ops until a run is actually due (same pattern as omniful:auto-sync).
Schedule::command('omniful:inventory-qty-push')->everyMinute()->withoutOverlapping(10);

// DISABLED: scanning ~20k order last_payload blobs (2.7 GB) per run is far too
// heavy — runs overran their withoutOverlapping window and stacked up, saturating
// disk I/O (iowait ~48%, load ~9) and starving both the queue workers and the
// portal. The dashboard widgets already fall back to cheap indexed counts when
// this cache is cold, so the landing page stays functional without it. The real
// fix is to store revenue/email/payment_method as real columns at ingest and
// aggregate via SQL (no payload scan); until that lands, keep this OFF.
// Schedule::command('dashboard:cache-metrics')->everyFiveMinutes()->withoutOverlapping(10);
