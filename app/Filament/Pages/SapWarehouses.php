<?php

namespace App\Filament\Pages;

use App\Models\SapWarehouse;
use App\Models\SapSyncEvent;
use App\Services\SapWarehouseBackgroundSyncService;
use Filament\Actions\Action as HeaderAction;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Tables\Actions\Action as TableAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Illuminate\Database\Eloquent\Builder;

class SapWarehouses extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-building-storefront';

    protected static ?string $navigationLabel = 'SAP Warehouses';

    protected static string | \UnitEnum | null $navigationGroup = 'Master Data';

    protected static ?int $navigationSort = 10;

    protected string $view = 'filament.pages.sap-warehouses';

    protected function getTableQuery(): Builder
    {
        return SapWarehouse::query()->orderBy('code');
    }

    protected function getTableColumns(): array
    {
        return [
            TextColumn::make('code')->label('Code')->searchable(),
            TextColumn::make('name')->label('Name')->searchable(),
            TextColumn::make('status')
                ->label('Status')
                ->badge()
                ->color(fn ($state) => match ($state) {
                    'synced' => 'success',
                    'failed' => 'danger',
                    default => 'gray',
                }),
            TextColumn::make('omniful_status')
                ->label('Omniful')
                ->badge()
                ->color(fn ($state) => match ($state) {
                    'synced' => 'success',
                    'failed' => 'danger',
                    'syncing' => 'warning',
                    'pending' => 'warning',
                    default => 'gray',
                }),
            TextColumn::make('synced_at')->label('Synced')->dateTime(),
        ];
    }

    protected function getTableActions(): array
    {
        return [
            TableAction::make('error')
                ->label('Reason')
                ->icon('heroicon-o-exclamation-triangle')
                ->color('warning')
                ->visible(fn ($record) => (bool) $record->error)
                ->modalHeading('Sync Error')
                ->modalSubmitAction(false)
                ->modalCancelActionLabel('Close')
                ->modalContent(fn ($record) => view('filament.pages.sap-sync-error', [
                    'error' => $record->error,
                ])),
            TableAction::make('omnifulError')
                ->label('Omniful Error')
                ->icon('heroicon-o-exclamation-triangle')
                ->color('warning')
                ->visible(fn ($record) => (bool) $record->omniful_error)
                ->modalHeading('Omniful Sync Error')
                ->modalSubmitAction(false)
                ->modalCancelActionLabel('Close')
                ->modalContent(fn ($record) => view('filament.pages.sap-sync-error', [
                    'error' => $record->omniful_error,
                ])),
        ];
    }

    protected function getHeaderActions(): array
    {
        $actions = [
            HeaderAction::make('openCatalog')
                ->label('Open SAP Catalog')
                ->icon('heroicon-o-table-cells')
                ->color('gray')
                ->url(SapCatalogOverview::getUrl()),
        ];

        $actions[] = HeaderAction::make('syncWarehouses')
            ->label('Sync from SAP')
            ->icon('heroicon-o-arrow-path')
            ->extraAttributes([
                'wire:loading.attr' => 'disabled',
                'wire:loading.class' => 'opacity-70',
            ])
            ->action('queueWarehouseSync');

        return $actions;
    }

    public function queueWarehouseSync(SapWarehouseBackgroundSyncService $dispatcher): void
    {
        $result = $dispatcher->dispatch('sap_warehouses_page');
        $event = $result['event'];

        if ((bool) $result['already_running']) {
            Notification::make()
                ->title('Warehouse sync already queued')
                ->body('Current event: ' . $event->event_key)
                ->warning()
                ->send();

            return;
        }

        Notification::make()
            ->title('Warehouse sync queued')
            ->body('Background job queued: ' . $event->event_key)
            ->success()
            ->send();
    }

    public function getWarehouseSyncPanel(): array
    {
        return $this->buildPanel('sap_warehouses');
    }

    public function getWarehousePushPanel(): array
    {
        return $this->buildPanel('omniful_warehouses_push');
    }

    public function cancelWarehousePush(): void
    {
        $event = SapSyncEvent::query()
            ->where('source_type', 'omniful_warehouses_push')
            ->whereIn('sap_status', ['queued', 'running'])
            ->latest('id')
            ->first();

        if ($event === null) {
            Notification::make()
                ->title('No running warehouse push')
                ->body('There is no active background warehouse push to stop.')
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
            ->body('The current warehouse push will stop after the current record finishes.')
            ->success()
            ->send();
    }

    private function buildPanel(string $sourceType): array
    {
        $event = SapSyncEvent::query()
            ->where('source_type', $sourceType)
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
                'summary_lines' => [],
                'error' => null,
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
            'summary_lines' => $summaryLines,
            'error' => $event->sap_error,
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
