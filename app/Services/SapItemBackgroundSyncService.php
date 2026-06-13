<?php

namespace App\Services;

use App\Jobs\RunSapItemBackgroundSync;
use App\Models\SapSyncEvent;
use App\Services\IntegrationDirectionService;
use Illuminate\Support\Str;

class SapItemBackgroundSyncService
{
    /**
     * @return array{queued:bool,already_running:bool,disabled:bool,event:?\App\Models\SapSyncEvent}
     */
    public function dispatch(?string $triggeredBy = null): array
    {
        if (!app(IntegrationDirectionService::class)->isDomainEnabled('items')) {
            return [
                'queued' => false,
                'already_running' => false,
                'disabled' => true,
                'event' => null,
            ];
        }

        $activeEvent = $this->activeEvent();
        if ($activeEvent !== null) {
            if ($this->shouldRequeueStaleEvent($activeEvent)) {
                $this->markRequeued($activeEvent, $triggeredBy);
                RunSapItemBackgroundSync::dispatch($activeEvent->id);

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
