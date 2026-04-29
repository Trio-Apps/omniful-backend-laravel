<?php

namespace App\Services;

use App\Jobs\RunSapSupplierBackgroundSync;
use App\Models\SapSyncEvent;
use Illuminate\Support\Str;

class SapSupplierBackgroundSyncService
{
    /**
     * @return array{queued:bool,already_running:bool,event:\App\Models\SapSyncEvent}
     */
    public function dispatch(?string $triggeredBy = null): array
    {
        $activeEvent = $this->activeEvent();
        if ($activeEvent !== null) {
            if ($this->shouldRequeueStaleEvent($activeEvent)) {
                $this->markRequeued($activeEvent, $triggeredBy);
                RunSapSupplierBackgroundSync::dispatch($activeEvent->id);

                return [
                    'queued' => true,
                    'already_running' => false,
                    'event' => $activeEvent,
                ];
            }

            return [
                'queued' => false,
                'already_running' => true,
                'event' => $activeEvent,
            ];
        }

        $event = SapSyncEvent::create([
            'event_key' => 'sap_supplier_sync_' . (string) Str::ulid(),
            'source_type' => 'sap_suppliers',
            'sap_action' => 'supplier_sync',
            'sap_status' => 'queued',
            'payload' => [
                'requested_at' => now()->toDateTimeString(),
                'triggered_by' => $triggeredBy,
            ],
        ]);

        RunSapSupplierBackgroundSync::dispatch($event->id);

        return [
            'queued' => true,
            'already_running' => false,
            'event' => $event,
        ];
    }

    private function activeEvent(): ?SapSyncEvent
    {
        return SapSyncEvent::query()
            ->where('source_type', 'sap_suppliers')
            ->whereIn('sap_status', ['queued', 'running'])
            ->where('updated_at', '>=', now()->subHours(6))
            ->latest('id')
            ->first();
    }

    private function shouldRequeueStaleEvent(SapSyncEvent $event): bool
    {
        return (string) $event->sap_status === 'queued'
            && $event->updated_at !== null
            && $event->updated_at->lt(now()->subMinutes(5));
    }

    private function markRequeued(SapSyncEvent $event, ?string $triggeredBy): void
    {
        $payload = (array) ($event->payload ?? []);
        $event->update([
            'sap_status' => 'queued',
            'sap_error' => null,
            'payload' => array_merge($payload, [
                'requeued_at' => now()->toDateTimeString(),
                'requeued_by' => $triggeredBy,
            ]),
        ]);
    }
}
