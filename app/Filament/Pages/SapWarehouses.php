<?php

namespace App\Filament\Pages;

use App\Models\SapWarehouse;
use App\Models\SapSyncEvent;
use App\Services\IntegrationDirectionService;
use App\Services\MasterData\SapWarehouseSyncService;
use App\Services\OmnifulApiClient;
use App\Services\SapServiceLayerClient;
use App\Services\SapWarehouseBackgroundSyncService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
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
            Action::make('error')
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
            Action::make('omnifulError')
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
        return [
            Action::make('openCatalog')
                ->label('Open SAP Catalog')
                ->icon('heroicon-o-table-cells')
                ->color('gray')
                ->url(SapCatalogOverview::getUrl()),
            Action::make('syncWarehouses')
                ->label(fn () => app(IntegrationDirectionService::class)->isSapToOmniful('warehouses')
                    ? 'Sync SAP Warehouses'
                    : 'Sync Omniful Warehouses to SAP')
                ->icon('heroicon-o-arrow-path')
                ->extraAttributes([
                    'wire:loading.attr' => 'disabled',
                    'wire:loading.class' => 'opacity-70',
                ])
                ->action('queueWarehouseSync'),
            Action::make('pushWarehouses')
                ->label('Push to Omniful')
                ->icon('heroicon-o-cloud-arrow-up')
                ->color('primary')
                ->disabled(fn () => app(IntegrationDirectionService::class)->isOmnifulToSap('warehouses'))
                ->extraAttributes([
                    'wire:loading.attr' => 'disabled',
                    'wire:loading.class' => 'opacity-70',
                ])
                ->action('pushWarehouses'),
        ];
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

    public function pushWarehouses(OmnifulApiClient $client): void
    {
        @ini_set('max_execution_time', '0');
        @set_time_limit(0);
        if (app(IntegrationDirectionService::class)->isOmnifulToSap('warehouses')) {
            Notification::make()
                ->title('Action blocked')
                ->body('Warehouses direction is Omniful -> SAP. Use Sync action instead.')
                ->warning()
                ->send();
            return;
        }

        $result = app(SapWarehouseSyncService::class)->pushToOmniful($client);
        $ok = (int) ($result['ok'] ?? 0);
        $failed = (int) ($result['failed'] ?? 0);
        $errors = (array) ($result['errors'] ?? []);

        $body = 'Synced: ' . $ok . ' | Failed: ' . $failed;
        if ($failed > 0) {
            $body .= "\n" . implode("\n", array_slice($errors, 0, 5));
        }

        Notification::make()
            ->title('Omniful push finished')
            ->body($body)
            ->{$failed > 0 ? 'warning' : 'success'}()
            ->send();
    }

    public function getWarehouseSyncPanel(): array
    {
        $event = SapSyncEvent::query()
            ->where('source_type', 'sap_warehouses')
            ->latest('id')
            ->first();

        if ($event === null) {
            return [
                'has_event' => false,
                'status' => 'idle',
                'status_label' => 'Idle',
                'tone' => 'gray',
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
            'queued', 'running' => 'warning',
            'failed' => 'danger',
            default => 'gray',
        };
    }
}
