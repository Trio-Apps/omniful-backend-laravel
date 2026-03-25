<?php

namespace App\Services;

use App\Jobs\RunSapSupplierBackgroundPush;
use App\Models\SapSyncEvent;
use Illuminate\Support\Str;

class SapSupplierBackgroundPushService
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
            'event_key' => 'omniful_supplier_push_' . (string) Str::ulid(),
            'source_type' => 'omniful_suppliers_push',
            'sap_action' => 'supplier_push',
            'sap_status' => 'queued',
            'payload' => [
                'requested_at' => now()->toDateTimeString(),
                'triggered_by' => $triggeredBy,
            ],
        ]);

        RunSapSupplierBackgroundPush::dispatch($event->id);

        return [
            'queued' => true,
            'already_running' => false,
            'event' => $event,
        ];
    }

    private function activeEvent(): ?SapSyncEvent
    {
        return SapSyncEvent::query()
            ->where('source_type', 'omniful_suppliers_push')
            ->whereIn('sap_status', ['queued', 'running', 'cancel_requested'])
            ->where('updated_at', '>=', now()->subHours(6))
            ->latest('id')
            ->first();
    }
}
