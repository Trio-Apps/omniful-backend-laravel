<?php

namespace App\Filament\Pages;

use App\Models\OmnifulOrder;
use Filament\Pages\Page;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\Filter;
use Illuminate\Database\Eloquent\Builder;

class OmnifulOrderMonitor extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-rectangle-stack';

    protected static ?string $navigationLabel = 'Omniful Orders';

    protected static string | \UnitEnum | null $navigationGroup = 'Monitoring';

    protected string $view = 'filament.pages.omniful-order-monitor';

    protected function getTableQuery(): Builder
    {
        return OmnifulOrder::query()->orderByDesc('last_event_at');
    }

    protected function getTableColumns(): array
    {
        return [
            TextColumn::make('external_id')
                ->label('Order ID')
                ->searchable(),
            TextColumn::make('omniful_status')
                ->label('Omniful Status')
                ->badge()
                ->toggleable(),
            TextColumn::make('sap_status')
                ->label('SAP Status')
                ->badge()
                ->toggleable(),
            TextColumn::make('last_event_type')
                ->label('Last Event')
                ->toggleable(),
            TextColumn::make('last_event_at')
                ->label('Last Event At')
                ->dateTime()
                ->sortable(),
        ];
    }

    protected function getTableFilters(): array
    {
        return [
            Filter::make('stuck')
                ->label('SAP Pending')
                ->query(fn (Builder $query) => $query->whereNull('sap_status')),
        ];
    }
}
