<?php

namespace App\Filament\Pages;

use App\Models\OmnifulOrderEvent;
use App\Filament\Pages\OmnifulOrderEventView;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Illuminate\Database\Eloquent\Builder;

class OmnifulOrderEvents extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-bell';

    protected static ?string $navigationLabel = 'Order Events';

    protected static string | \UnitEnum | null $navigationGroup = 'Monitoring';

    protected string $view = 'filament.pages.omniful-order-events';

    protected function getTableQuery(): Builder
    {
        return OmnifulOrderEvent::query()->orderByDesc('received_at');
    }

    protected function getTableColumns(): array
    {
        return [
            TextColumn::make('external_id')
                ->label('Order ID')
                ->getStateUsing(fn ($record) => $record->external_id
                    ?? data_get($record->payload, 'data.order_id')
                    ?? data_get($record->payload, 'data.id'))
                ->searchable(),
            TextColumn::make('event_name')
                ->label('Event')
                ->getStateUsing(fn ($record) => data_get($record->payload, 'event_name'))
                ->toggleable(),
            TextColumn::make('status')
                ->label('Status')
                ->badge()
                ->getStateUsing(fn ($record) => data_get($record->payload, 'data.status_code'))
                ->toggleable(),
            TextColumn::make('seller_code')
                ->label('Seller')
                ->getStateUsing(fn ($record) => data_get($record->payload, 'data.seller_code'))
                ->toggleable(),
            TextColumn::make('hub_code')
                ->label('Hub')
                ->getStateUsing(fn ($record) => data_get($record->payload, 'data.hub_code'))
                ->toggleable(),
            TextColumn::make('payment_method')
                ->label('Payment')
                ->getStateUsing(fn ($record) => data_get($record->payload, 'data.payment_method'))
                ->toggleable(),
            TextColumn::make('total')
                ->label('Total')
                ->getStateUsing(fn ($record) => data_get($record->payload, 'data.invoice.total'))
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
                ->url(fn ($record) => OmnifulOrderEventView::getUrl(['record' => $record])),
        ];
    }
}
