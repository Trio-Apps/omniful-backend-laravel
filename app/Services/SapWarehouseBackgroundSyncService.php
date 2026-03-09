<?php

namespace App\Services;

use App\Jobs\RunSapWarehouseBackgroundSync;
use App\Models\SapSyncEvent;
use Illuminate\Support\Str;

class SapWarehouseBackgroundSyncService
{
    /**
     * @return array{queued:bool,already_running:bool,event:\App\Models\SapSyncEvent}
     */
    public function dispatch(?string $triggeredBy = null): array
    {
        $activeEvent = $this->activeEvent();
        if ($activeEvent !== null) {
            return [
                'queued' => false,
                'already_running' => true,
                'event' => $activeEvent,
            ];
        }

        $event = SapSyncEvent::create([
            'event_key' => 'sap_warehouse_sync_' . (string) Str::ulid(),
            'source_type' => 'sap_warehouses',
            'sap_action' => 'warehouse_sync',
            'sap_status' => 'queued',
            'payload' => [
                'requested_at' => now()->toDateTimeString(),
                'triggered_by' => $triggeredBy,
            ],
        ]);

        RunSapWarehouseBackgroundSync::dispatch($event->id);

        return [
            'queued' => true,
            'already_running' => false,
            'event' => $event,
        ];
    }

    public function latestEvent(): ?SapSyncEvent
    {
        return SapSyncEvent::query()
            ->where('source_type', 'sap_warehouses')
            ->latest('id')
            ->first();
    }

    private function activeEvent(): ?SapSyncEvent
    {
        return SapSyncEvent::query()
            ->where('source_type', 'sap_warehouses')
            ->whereIn('sap_status', ['queued', 'running'])
            ->where('updated_at', '>=', now()->subHours(6))
            ->latest('id')
            ->first();
    }
}
