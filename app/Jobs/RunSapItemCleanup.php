<?php

namespace App\Jobs;

use App\Models\SapSyncEvent;
use App\Services\SapItemCleanupService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class RunSapItemCleanup implements ShouldQueue
{
    use Queueable;

    public int $timeout = 3600;

    public int $tries = 1;

    public function __construct(
        public int $syncEventId
    ) {
    }

    public function handle(SapItemCleanupService $cleanup): void
    {
        $event = SapSyncEvent::find($this->syncEventId);
        if ($event === null) {
            return;
        }

        $basePayload = (array) ($event->payload ?? []);
        if ($event->sap_status === 'cancel_requested') {
            $event->update([
                'sap_status' => 'cancelled',
                'sap_error' => 'Cleanup stopped by user request.',
                'payload' => array_merge($basePayload, [
                    'finished_at' => now()->toDateTimeString(),
                ]),
            ]);

            return;
        }

        $event->update([
            'sap_status' => 'running',
            'sap_error' => null,
            'payload' => array_merge($basePayload, [
                'started_at' => now()->toDateTimeString(),
            ]),
        ]);

        try {
            if ((string) ($basePayload['action'] ?? '') === 'scan') {
                $details = $cleanup->runScan($event);
                $summary = [
                    'action' => 'scan',
                    'found' => (int) ($details['found'] ?? 0),
                    'added' => (int) ($details['added'] ?? 0),
                    'updated' => (int) ($details['updated'] ?? 0),
                ];
            } else {
                $details = $cleanup->runBulk($event);
                $summary = [
                    'action' => (string) ($details['action'] ?? ''),
                    'total' => (int) ($details['total'] ?? 0),
                    'done' => (int) ($details['done'] ?? 0),
                    'failed' => (int) ($details['failed'] ?? 0),
                    'requeued' => (int) ($details['requeued'] ?? 0),
                ];
            }

            $finalStatus = !empty($details['cancelled']) ? 'cancelled' : 'completed';

            $event->update([
                'sap_status' => $finalStatus,
                'sap_error' => !empty($details['cancelled']) ? 'Cleanup stopped by user request.' : null,
                'payload' => array_merge($basePayload, [
                    'started_at' => $basePayload['started_at'] ?? now()->toDateTimeString(),
                    'finished_at' => now()->toDateTimeString(),
                    'summary' => $summary,
                    'details' => $details,
                ]),
            ]);
        } catch (\Throwable $exception) {
            $event->update([
                'sap_status' => 'failed',
                'sap_error' => $exception->getMessage(),
                'payload' => array_merge($basePayload, [
                    'finished_at' => now()->toDateTimeString(),
                ]),
            ]);

            throw $exception;
        }
    }

    public function failed(\Throwable $exception): void
    {
        $event = SapSyncEvent::find($this->syncEventId);
        if ($event === null || $event->sap_status === 'failed') {
            return;
        }

        $payload = (array) ($event->payload ?? []);
        $event->update([
            'sap_status' => 'failed',
            'sap_error' => $exception->getMessage(),
            'payload' => array_merge($payload, [
                'finished_at' => now()->toDateTimeString(),
            ]),
        ]);
    }
}
