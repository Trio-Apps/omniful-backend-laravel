<?php

namespace App\Console\Commands;

use App\Models\SapSyncEvent;
use App\Services\SapInventoryQtyPushService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class PushSapInventoryQuantities extends Command
{
    protected $signature = 'omniful:inventory-qty-push {--mode= : full|delta (defaults to config)} {--force : Ignore the enabled flag and cadence}';

    protected $description = 'Queue a SAP -> Omniful inventory quantity push (Available per synced item x hub).';

    public function handle(SapInventoryQtyPushService $service): int
    {
        $force = (bool) $this->option('force');

        if (!$force) {
            if (!(bool) config('omniful.inventory_push.enabled', false)) {
                $this->info('Inventory push is disabled (omniful.inventory_push.enabled) — skipping.');

                return self::SUCCESS;
            }
            if (!$this->isDue()) {
                $this->info('Inventory push not due yet — skipping.');

                return self::SUCCESS;
            }
        }

        $mode = trim((string) ($this->option('mode') ?? '')) ?: null;
        $result = $service->dispatch('command', $mode);

        if (!empty($result['already_running'])) {
            $this->warn('An inventory push is already running (event #' . $result['event']->id . ') — skipped.');

            return self::SUCCESS;
        }

        $this->info('Inventory push queued (event #' . $result['event']->id . ', mode=' . ($mode ?: config('omniful.inventory_push.mode', 'delta')) . ').');

        return self::SUCCESS;
    }

    /**
     * Due when at least the configured cadence has elapsed since the last run
     * was queued (mirrors the auto-sync cadence gate).
     */
    private function isDue(): bool
    {
        $cadence = max(1, (int) config('omniful.inventory_push.cadence_minutes', 30));

        $last = SapSyncEvent::query()
            ->where('source_type', SapInventoryQtyPushService::SOURCE_TYPE)
            ->latest('id')
            ->value('created_at');

        if ($last === null) {
            return true;
        }

        return Carbon::parse($last)->lte(now()->subMinutes($cadence));
    }
}
