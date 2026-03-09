<?php

namespace App\Services;

use App\Jobs\RunSapItemBackgroundSync;
use App\Models\SapSyncEvent;
use Illuminate\Support\Str;

class SapItemBackgroundSyncService
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
            'event_key' => 'sap_item_sync_' . (string) Str::ulid(),
            'source_type' => 'sap_items',
            'sap_action' => 'item_sync',
            'sap_status' => 'queued',
            'payload' => [
                'requested_at' => now()->toDateTimeString(),
                'triggered_by' => $triggeredBy,
            ],
        ]);

        RunSapItemBackgroundSync::dispatch($event->id);

        return [
            'queued' => true,
            'already_running' => false,
            'event' => $event,
        ];
    }

    private function activeEvent(): ?SapSyncEvent
    {
        return SapSyncEvent::query()
            ->where('source_type', 'sap_items')
            ->whereIn('sap_status', ['queued', 'running'])
            ->where('updated_at', '>=', now()->subHours(6))
            ->latest('id')
            ->first();
    }
}
