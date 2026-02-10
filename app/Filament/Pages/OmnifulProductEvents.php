<?php

namespace App\Filament\Pages;

use App\Models\OmnifulProductEvent;
use App\Services\Webhooks\WebhookRetryService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Illuminate\Database\Eloquent\Builder;

class OmnifulProductEvents extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-cube';

    protected static ?string $navigationLabel = 'Product Events';

    protected static string | \UnitEnum | null $navigationGroup = 'Monitoring';

    protected string $view = 'filament.pages.omniful-product-events';

    protected function getTableQuery(): Builder
    {
        return OmnifulProductEvent::query()->orderByDesc('received_at');
    }

    protected function getTableColumns(): array
    {
        return [
            TextColumn::make('external_id')
                ->label('SKU')
                ->getStateUsing(function ($record) {
                    $data = data_get($record->payload, 'data');
                    $item = is_array($data) ? ($data[0] ?? []) : (is_array($data) ? $data : []);
                    return $record->external_id
                        ?? data_get($item, 'seller_sku_code')
                        ?? data_get($item, 'sku_code')
                        ?? data_get($item, 'id');
                })
                ->searchable(),
            TextColumn::make('event_name')
                ->label('Event')
                ->getStateUsing(fn ($record) => data_get($record->payload, 'event_name'))
                ->toggleable(),
            TextColumn::make('name')
                ->label('Name')
                ->getStateUsing(function ($record) {
                    $data = data_get($record->payload, 'data');
                    $item = is_array($data) ? ($data[0] ?? []) : (is_array($data) ? $data : []);
                    return data_get($item, 'name') ?? data_get($item, 'product.name');
                })
                ->toggleable(),
            TextColumn::make('status')
                ->label('Status')
                ->badge()
                ->getStateUsing(function ($record) {
                    $data = data_get($record->payload, 'data');
                    $item = is_array($data) ? ($data[0] ?? []) : (is_array($data) ? $data : []);
                    return data_get($item, 'status');
                })
                ->toggleable(),
            TextColumn::make('seller_code')
                ->label('Seller')
                ->getStateUsing(function ($record) {
                    $data = data_get($record->payload, 'data');
                    $item = is_array($data) ? ($data[0] ?? []) : (is_array($data) ? $data : []);
                    return data_get($item, 'seller_code');
                })
                ->toggleable(),
            TextColumn::make('sap_status')
                ->label('SAP')
                ->badge()
                ->color(fn ($state) => match ($state) {
                    'created' => 'success',
                    'updated' => 'info',
                    'failed' => 'danger',
                    'skipped' => 'gray',
                    default => 'gray',
                })
                ->toggleable(),
            TextColumn::make('sap_item_code')
                ->label('SAP Item')
                ->toggleable(),
            TextColumn::make('received_at')
                ->label('Received')
                ->dateTime()
                ->sortable(),
        ];
    }

    protected function getTableActions(): array
    {
        return [
            Action::make('view')
                ->label('View')
                ->icon('heroicon-o-eye')
                ->url(fn ($record) => OmnifulProductEventView::getUrl(['record' => $record])),
            Action::make('sapError')
                ->label('SAP Error')
                ->icon('heroicon-o-exclamation-triangle')
                ->color('danger')
                ->visible(fn ($record) => (bool) $record->sap_error)
                ->modalHeading('SAP Error')
                ->modalSubmitAction(false)
                ->modalCancelActionLabel('Close')
                ->modalContent(fn ($record) => view('filament.pages.sap-sync-error', [
                    'error' => $record->sap_error,
                ])),
            Action::make('retrySap')
                ->label('Retry SAP')
                ->icon('heroicon-o-arrow-path')
                ->color('warning')
                ->action(function ($record) {
                    $result = app(WebhookRetryService::class)->retryProductEvent($record);
                    Notification::make()
                        ->title($result['ok'] ? 'Retry completed' : 'Retry failed')
                        ->body($result['message'])
                        ->{$result['ok'] ? 'success' : 'danger'}()
                        ->send();
                }),
        ];
    }
}
