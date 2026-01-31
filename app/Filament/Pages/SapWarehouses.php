<?php

namespace App\Filament\Pages;

use App\Models\SapWarehouse;
use App\Services\OmnifulApiClient;
use App\Services\SapServiceLayerClient;
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
            Action::make('syncWarehouses')
                ->label('Sync SAP Warehouses')
                ->icon('heroicon-o-arrow-path')
                ->extraAttributes([
                    'wire:loading.attr' => 'disabled',
                    'wire:loading.class' => 'opacity-70',
                ])
                ->action('syncWarehouses'),
            Action::make('pushWarehouses')
                ->label('Push to Omniful')
                ->icon('heroicon-o-cloud-arrow-up')
                ->color('primary')
                ->extraAttributes([
                    'wire:loading.attr' => 'disabled',
                    'wire:loading.class' => 'opacity-70',
                ])
                ->action('pushWarehouses'),
        ];
    }

    public function syncWarehouses(SapServiceLayerClient $client): void
    {
        @ini_set('max_execution_time', '0');
        @set_time_limit(0);
        try {
            $rows = $client->fetchWarehouses();
            foreach ($rows as $row) {
                $code = $row['WarehouseCode'] ?? null;
                if (!$code) {
                    continue;
                }
                SapWarehouse::updateOrCreate(
                    ['code' => $code],
                    [
                        'name' => $row['WarehouseName'] ?? null,
                        'payload' => $row,
                        'synced_at' => now(),
                        'status' => 'synced',
                        'error' => null,
                    ]
                );
                $record = SapWarehouse::where('code', $code)->first();
                if ($record && !$record->omniful_status) {
                    $record->omniful_status = 'pending';
                    $record->save();
                }
            }

            Notification::make()
                ->title('Warehouses synced')
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

    public function pushWarehouses(OmnifulApiClient $client): void
    {
        @ini_set('max_execution_time', '0');
        @set_time_limit(0);
        $records = SapWarehouse::query()
            ->whereNull('omniful_status')
            ->orWhere('omniful_status', '!=', 'synced')
            ->orderBy('code')
            ->get();

        $ok = 0;
        $failed = 0;
        $errors = [];

        foreach ($records as $record) {
            $record->omniful_status = 'syncing';
            $record->omniful_error = null;
            $record->save();

            try {
                $payload = [
                    'code' => $record->code,
                    'name' => $record->name,
                ];

                $response = $client->upsert('warehouses', $record->code, $payload);
                if (!$response['ok']) {
                    throw new \RuntimeException('HTTP ' . $response['status'] . ' ' . $response['body']);
                }

                $record->omniful_status = 'synced';
                $record->omniful_error = null;
                $record->omniful_synced_at = now();
                $record->save();
                $ok++;
            } catch (\Throwable $e) {
                $record->omniful_status = 'failed';
                $record->omniful_error = $e->getMessage();
                $record->save();
                $failed++;
                $errors[] = $record->code . ': ' . $e->getMessage();
            }
        }

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
