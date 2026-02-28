<?php

namespace App\Filament\Pages;

use App\Filament\Pages\Concerns\InteractsWithSapCatalogPage;
use App\Models\SapBankAccount;
use App\Services\MasterData\SapFinanceMasterDataSyncService;
use App\Services\SapServiceLayerClient;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Illuminate\Database\Eloquent\Builder;

class SapBankAccountsCatalog extends Page implements HasTable
{
    use InteractsWithSapCatalogPage;
    use InteractsWithTable;

    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-credit-card';

    protected static ?string $navigationLabel = 'SAP Bank Accounts';

    protected static string | \UnitEnum | null $navigationGroup = 'SAP Catalog';

    protected static ?int $navigationSort = 15;

    protected string $view = 'filament.pages.sap-catalog-table';

    protected function getTableQuery(): Builder
    {
        return SapBankAccount::query()->orderBy('bank_code')->orderBy('account_code');
    }

    protected function getTableColumns(): array
    {
        return [
            TextColumn::make('bank_code')->label('Bank Code')->searchable(),
            TextColumn::make('account_code')->label('Account Code')->searchable(),
            TextColumn::make('account_number')->label('Account #')->searchable(),
            TextColumn::make('branch')->label('Branch')->searchable()->toggleable(),
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
            ['label' => 'Bank Accounts', 'value' => SapBankAccount::count(), 'hint' => 'House bank account rows'],
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
