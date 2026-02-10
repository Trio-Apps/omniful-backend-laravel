<?php

namespace App\Filament\Pages;

use App\Models\OmnifulInventoryEvent;
use App\Filament\Pages\OmnifulInventoryEventView;
use App\Services\Webhooks\WebhookRetryService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Illuminate\Database\Eloquent\Builder;

class OmnifulInventoryEvents extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-archive-box';

    protected static ?string $navigationLabel = 'Inventory Events';

    protected static string | \UnitEnum | null $navigationGroup = 'Monitoring';

    protected string $view = 'filament.pages.omniful-inventory-events';

    protected function getTableQuery(): Builder
    {
        return OmnifulInventoryEvent::query()->orderByDesc('received_at');
    }

    protected function getTableColumns(): array
    {
        return [
            TextColumn::make('event_name')
                ->label('Event')
                ->getStateUsing(fn ($record) => data_get($record->payload, 'event_name'))
                ->toggleable(),
            TextColumn::make('action')
                ->label('Action')
                ->badge()
                ->getStateUsing(fn ($record) => data_get($record->payload, 'action'))
                ->toggleable(),
            TextColumn::make('entity')
                ->label('Entity')
                ->getStateUsing(fn ($record) => data_get($record->payload, 'entity'))
                ->toggleable(),
            TextColumn::make('hub_code')
                ->label('Hub')
                ->getStateUsing(fn ($record) => data_get($record->payload, 'data.hub_code'))
                ->toggleable(),
            TextColumn::make('items_count')
                ->label('Items')
                ->getStateUsing(function ($record) {
                    $items = data_get($record->payload, 'data.hub_inventory_items', data_get($record->payload, 'data.items', []));
                    return is_array($items) ? count($items) : 0;
                })
                ->toggleable(),
            TextColumn::make('sap_status')
                ->label('SAP')
                ->badge()
                ->color(fn ($state) => match ($state) {
                    'created' => 'success',
                    'failed' => 'danger',
                    'ignored' => 'gray',
                    default => 'gray',
                })
                ->toggleable(),
            TextColumn::make('sap_doc_num')
                ->label('SAP DocNum')
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
                ->url(fn ($record) => OmnifulInventoryEventView::getUrl(['record' => $record])),
            Action::make('sapError')
                ->label('Reason')
                ->icon('heroicon-o-exclamation-triangle')
                ->color('warning')
                ->visible(fn ($record) => (bool) $record->sap_error)
                ->modalHeading('SAP / Ignore Reason')
                ->modalSubmitAction(false)
                ->modalCancelActionLabel('Close')
                ->modalContent(fn ($record) => view('filament.pages.omniful-inventory-sap-error', [
                    'error' => $record->sap_error,
                ])),
            Action::make('retrySap')
                ->label('Retry SAP')
                ->icon('heroicon-o-arrow-path')
                ->color('warning')
                ->action(function ($record) {
                    $result = app(WebhookRetryService::class)->retryInventoryEvent($record);
                    Notification::make()
                        ->title($result['ok'] ? 'Retry completed' : 'Retry failed')
                        ->body($result['message'])
                        ->{$result['ok'] ? 'success' : 'danger'}()
                        ->send();
                }),
        ];
    }
}
