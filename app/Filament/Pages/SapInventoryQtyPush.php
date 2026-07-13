<?php

namespace App\Filament\Pages;

use App\Models\SapSyncEvent;
use App\Services\SapInventoryQtyPushService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

class SapInventoryQtyPush extends Page
{
    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-arrows-up-down';

    protected static ?string $navigationLabel = 'Inventory Qty Push';

    protected static string | \UnitEnum | null $navigationGroup = 'Master Data';

    protected static ?int $navigationSort = 45;

    protected string $view = 'filament.pages.sap-inventory-qty-push';

    public function getTitle(): string
    {
        return 'SAP → Omniful Inventory Quantity Push';
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('runDelta')
                ->label('Run Now (Delta)')
                ->icon('heroicon-o-arrow-path')
                ->color('primary')
                ->extraAttributes(['wire:loading.attr' => 'disabled'])
                ->action(fn () => $this->runPush('delta')),
            Action::make('runFull')
                ->label('Run Full')
                ->icon('heroicon-o-arrows-pointing-out')
                ->color('gray')
                ->requiresConfirmation()
                ->modalHeading('Run a FULL inventory push')
                ->modalDescription('Pushes every synced item × hub quantity to Omniful, ignoring the delta snapshot. Use after a mismatch; heavier than a delta run.')
                ->action(fn () => $this->runPush('full')),
            Action::make('cancel')
                ->label('Stop')
                ->icon('heroicon-o-stop-circle')
                ->color('danger')
                ->visible(fn () => (bool) ($this->getPanel()['can_stop'] ?? false))
                ->requiresConfirmation()
                ->action(fn () => $this->cancelPush()),
        ];
    }

    public function runPush(string $mode): void
    {
        $result = app(SapInventoryQtyPushService::class)->dispatch('inventory_push_page', $mode);
        $event = $result['event'];

        if (!empty($result['already_running'])) {
            Notification::make()
                ->title('A push is already running')
                ->body($event->event_key)
                ->warning()
                ->send();

            return;
        }

        Notification::make()
            ->title('Inventory push queued')
            ->body('Mode: ' . $mode . ' · ' . $event->event_key)
            ->success()
            ->send();
    }

    public function cancelPush(): void
    {
        $event = SapSyncEvent::query()
            ->where('source_type', SapInventoryQtyPushService::SOURCE_TYPE)
            ->whereIn('sap_status', ['queued', 'running'])
            ->latest('id')
            ->first();

        if ($event === null) {
            Notification::make()
                ->title('No running push')
                ->body('There is no active inventory push to stop.')
                ->warning()
                ->send();

            return;
        }

        $payload = (array) ($event->payload ?? []);
        $event->update([
            'sap_status' => 'cancel_requested',
            'payload' => array_merge($payload, [
                'cancel_requested_at' => now()->toDateTimeString(),
            ]),
        ]);

        Notification::make()
            ->title('Stop requested')
            ->body('The push will stop after the current batch finishes.')
            ->success()
            ->send();
    }

    /**
     * @return array<string,mixed>
     */
    public function getPanel(): array
    {
        $service = app(SapInventoryQtyPushService::class);
        $config = [
            'enabled' => $service->isEnabled(),
            'mode' => $service->defaultMode(),
            'cadence_minutes' => $service->cadenceMinutes(),
            'quantity_source' => (string) config('omniful.inventory_push.quantity_source', 'available'),
        ];

        $event = SapSyncEvent::query()
            ->where('source_type', SapInventoryQtyPushService::SOURCE_TYPE)
            ->latest('id')
            ->first();

        if ($event === null) {
            return [
                'has_event' => false,
                'status' => 'idle',
                'status_label' => 'Idle',
                'tone' => 'gray',
                'can_stop' => false,
                'event_key' => null,
                'requested_at' => null,
                'updated_at' => null,
                'progress' => [],
                'summary_lines' => [],
                'error' => null,
                'config' => $config,
            ];
        }

        $payload = (array) ($event->payload ?? []);
        $summary = (array) ($payload['summary'] ?? []);
        $summaryLines = [];
        foreach ($summary as $key => $value) {
            $summaryLines[] = ucwords(str_replace('_', ' ', (string) $key)) . ': ' . $value;
        }

        $status = (string) ($event->sap_status ?? 'unknown');

        return [
            'has_event' => true,
            'status' => $status,
            'status_label' => ucwords(str_replace('_', ' ', $status)),
            'tone' => $this->statusTone($status),
            'can_stop' => in_array($status, ['queued', 'running', 'cancel_requested'], true),
            'event_key' => $event->event_key,
            'requested_at' => $event->created_at?->toDateTimeString(),
            'updated_at' => $event->updated_at?->toDateTimeString(),
            'progress' => (array) ($payload['progress'] ?? []),
            'summary_lines' => $summaryLines,
            'error' => $event->sap_error,
            'config' => $config,
        ];
    }

    private function statusTone(string $status): string
    {
        return match ($status) {
            'completed' => 'success',
            'queued', 'running', 'cancel_requested' => 'warning',
            'cancelled' => 'gray',
            'failed' => 'danger',
            default => 'gray',
        };
    }
}
