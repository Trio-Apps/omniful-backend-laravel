<?php

namespace App\Filament\Pages;

use App\Models\SapItem;
use App\Services\IntegrationDirectionService;
use App\Services\MasterData\SapItemSyncService;
use App\Services\OmnifulApiClient;
use App\Services\SapServiceLayerClient;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Illuminate\Database\Eloquent\Builder;

class SapItems extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-squares-2x2';

    protected static ?string $navigationLabel = 'SAP Items';

    protected static string | \UnitEnum | null $navigationGroup = 'Master Data';

    protected static ?int $navigationSort = 30;

    protected string $view = 'filament.pages.sap-items';

    protected function getTableQuery(): Builder
    {
        return SapItem::query()->orderBy('code');
    }

    protected function getTableColumns(): array
    {
        return [
            TextColumn::make('code')->label('Code')->searchable(),
            TextColumn::make('name')->label('Name')->searchable(),
            TextColumn::make('uom_group_entry')->label('UoM Group'),
            TextColumn::make('status')
                ->label('SAP')
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
            Action::make('syncItems')
                ->label(fn () => app(IntegrationDirectionService::class)->isSapToOmniful('items')
                    ? 'Sync SAP Items'
                    : 'Sync Omniful Items to SAP')
                ->icon('heroicon-o-arrow-path')
                ->extraAttributes([
                    'wire:loading.attr' => 'disabled',
                    'wire:loading.class' => 'opacity-70',
                ])
                ->action('syncItems'),
            Action::make('pushItems')
                ->label('Push to Omniful')
                ->icon('heroicon-o-cloud-arrow-up')
                ->color('primary')
                ->disabled(fn () => app(IntegrationDirectionService::class)->isOmnifulToSap('items'))
                ->extraAttributes([
                    'wire:loading.attr' => 'disabled',
                    'wire:loading.class' => 'opacity-70',
                ])
                ->action('pushItems'),
        ];
    }

    public function syncItems(SapServiceLayerClient $client): void
    {
        @ini_set('max_execution_time', '0');
        @set_time_limit(0);
        try {
            $direction = app(IntegrationDirectionService::class);
            if ($direction->isSapToOmniful('items')) {
                app(SapItemSyncService::class)->syncFromSap($client);
            } else {
                app(SapItemSyncService::class)->syncFromOmniful(app(OmnifulApiClient::class), $client);
            }

            Notification::make()
                ->title('Items synced')
                ->success()
                ->send();
        } catch (\Throwable $e) {
            Notification::make()
                ->title('SAP sync failed')
                ->body($e->getMessage())
                ->danger()
                ->send();
        }
    }

    public function pushItems(OmnifulApiClient $client): void
    {
        @ini_set('max_execution_time', '0');
        @set_time_limit(0);
        if (app(IntegrationDirectionService::class)->isOmnifulToSap('items')) {
            Notification::make()
                ->title('Action blocked')
                ->body('Items direction is Omniful -> SAP. Use Sync action instead.')
                ->warning()
                ->send();
            return;
        }

        $result = app(SapItemSyncService::class)->pushToOmniful($client);
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
}
