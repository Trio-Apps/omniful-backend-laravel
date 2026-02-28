<?php

namespace App\Filament\Pages;

use App\Filament\Pages\Concerns\InteractsWithSapCatalogPage;
use App\Models\SapCurrency;
use App\Services\MasterData\SapFinanceMasterDataSyncService;
use App\Services\SapServiceLayerClient;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Illuminate\Database\Eloquent\Builder;

class SapCurrenciesCatalog extends Page implements HasTable
{
    use InteractsWithSapCatalogPage;
    use InteractsWithTable;

    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-currency-dollar';

    protected static ?string $navigationLabel = 'SAP Currencies';

    protected static string | \UnitEnum | null $navigationGroup = 'SAP Catalog';

    protected static ?int $navigationSort = 16;

    protected string $view = 'filament.pages.sap-catalog-table';

    protected function getTableQuery(): Builder
    {
        return SapCurrency::query()->orderBy('code');
    }

    protected function getTableColumns(): array
    {
        return [
            TextColumn::make('code')->label('Code')->searchable(),
            TextColumn::make('name')->label('Name')->searchable(),
            TextColumn::make('status')
                ->label('Status')
                ->badge()
                ->color(fn ($state) => $state === 'synced' ? 'success' : 'gray'),
            TextColumn::make('synced_at')->label('Synced')->dateTime(),
        ];
    }

    protected function getTableActions(): array
    {
        return $this->getCatalogTableActions();
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('syncFinanceMaster')
                ->label('Sync Finance Master')
                ->icon('heroicon-o-arrow-path')
                ->extraAttributes([
                    'wire:loading.attr' => 'disabled',
                    'wire:loading.class' => 'opacity-70',
                ])
                ->action('syncFinanceMaster'),
        ];
    }

    public function getStats(): array
    {
        return [
            ['label' => 'Currencies', 'value' => SapCurrency::count(), 'hint' => 'Currency master rows'],
        ];
    }

    public function syncFinanceMaster(SapServiceLayerClient $client): void
    {
        @ini_set('max_execution_time', '0');
        @set_time_limit(0);

        try {
            $summary = app(SapFinanceMasterDataSyncService::class)->syncFromSap($client);
            $this->sendSyncSuccessNotification('Finance master synced', $summary);
        } catch (\Throwable $exception) {
            $this->sendSyncFailureNotification($exception);
        }
    }
}
