<?php

namespace App\Filament\Pages;

use App\Models\OmnifulInwardingEvent;
use Filament\Pages\Page;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Illuminate\Database\Eloquent\Builder;

class OmnifulInwardingEvents extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-truck';

    protected static ?string $navigationLabel = 'Inwarding Events';

    protected static string | \UnitEnum | null $navigationGroup = 'Monitoring';

    protected string $view = 'filament.pages.omniful-inwarding-events';

    protected function getTableQuery(): Builder
    {
        return OmnifulInwardingEvent::query()->orderByDesc('received_at');
    }

    protected function getTableColumns(): array
    {
        return [
            TextColumn::make('external_id')
                ->label('Reference ID')
                ->searchable(),
            IconColumn::make('signature_valid')
                ->label('Signature')
                ->boolean()
                ->toggleable(),
            TextColumn::make('received_at')
                ->label('Received')
                ->dateTime()
                ->sortable(),
            TextColumn::make('payload')
                ->label('Payload')
                ->formatStateUsing(fn ($state) => is_array($state) ? json_encode($state) : (string) $state)
                ->limit(120)
                ->toggleable(),
        ];
    }
}
