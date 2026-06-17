<?php

namespace App\Console\Commands;

use App\Models\IntegrationSetting;
use App\Services\IntegrationDirectionService;
use App\Services\MasterData\SapItemSyncService;
use App\Services\MasterData\SapSupplierSyncService;
use App\Services\OmnifulApiClient;
use App\Services\SapServiceLayerClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class RunAutoSync extends Command
{
    protected $signature = 'omniful:auto-sync {--force : Run now, ignoring the configured interval}';

    protected $description = 'Scheduled SAP -> Omniful master-data sync (items/suppliers) driven by the dashboard Auto Sync settings';

    public function handle(
        SapServiceLayerClient $sap,
        OmnifulApiClient $omniful,
        SapItemSyncService $itemService,
        SapSupplierSyncService $supplierService,
        IntegrationDirectionService $direction
    ): int {
        $settings = IntegrationSetting::active();
        if (!$settings || !$settings->auto_sync_enabled) {
            return self::SUCCESS;
        }

        $interval = max(1, (int) ($settings->auto_sync_interval_minutes ?? 15));
        $last = $settings->auto_sync_last_run_at;
        if (!$this->option('force') && $last !== null && $last->copy()->addMinutes($interval)->isFuture()) {
            // Not due yet — runs every minute but only works once per interval.
            return self::SUCCESS;
        }

        // Stamp the run start first so the interval is measured consistently and
        // a long run does not immediately re-trigger on the next minute.
        $settings->auto_sync_last_run_at = now();
        $settings->save();

        if ((bool) $settings->auto_sync_items_enabled
            && $direction->isDomainEnabled('items')
            && $direction->isSapToOmniful('items')) {
            $this->runDomain(
                'items',
                fn () => $itemService->syncFromSap($sap),
                fn () => $itemService->pushToOmniful($omniful)
            );
        }

        if ((bool) $settings->auto_sync_suppliers_enabled
            && $direction->isDomainEnabled('suppliers')
            && $direction->isSapToOmniful('suppliers')) {
            $this->runDomain(
                'suppliers',
                fn () => $supplierService->syncFromSap($sap),
                fn () => $supplierService->pushToOmniful($omniful)
            );
        }

        return self::SUCCESS;
    }

    private function runDomain(string $domain, callable $pull, callable $push): void
    {
        try {
            $pull();
            $push();
            $this->info('Auto sync ' . $domain . ' completed.');
        } catch (\Throwable $e) {
            Log::error('Auto sync failed for ' . $domain, ['error' => $e->getMessage()]);
            $this->error('Auto sync ' . $domain . ' failed: ' . $e->getMessage());
        }
    }
}
