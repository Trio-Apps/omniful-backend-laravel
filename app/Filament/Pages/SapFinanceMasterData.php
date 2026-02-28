<?php

namespace App\Filament\Pages;

use App\Filament\Pages\Concerns\InteractsWithSapCatalogPage;
use App\Models\SapAccountCategory;
use App\Models\SapBank;
use App\Models\SapBankAccount;
use App\Models\SapBranch;
use App\Models\SapChartOfAccount;
use App\Models\SapCurrency;
use App\Models\SapCustomerFinance;
use App\Models\SapExchangeRate;
use App\Models\SapFinancialPeriod;
use App\Models\SapPaymentTerm;
use App\Models\SapProfitCenter;
use App\Services\MasterData\SapFinanceMasterDataSyncService;
use App\Services\SapServiceLayerClient;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Illuminate\Database\Eloquent\Builder;

class SapFinanceMasterData extends Page implements HasTable
{
    use InteractsWithSapCatalogPage;
    use InteractsWithTable;

    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-banknotes';

    protected static ?string $navigationLabel = 'SAP Finance Master';

    protected static string | \UnitEnum | null $navigationGroup = 'SAP Catalog';

    protected static ?int $navigationSort = 10;

    protected string $view = 'filament.pages.sap-catalog-table';

    protected function getTableQuery(): Builder
    {
        return SapChartOfAccount::query()->orderBy('code');
    }

    protected function getTableColumns(): array
    {
        return [
            TextColumn::make('code')->label('Code')->searchable(),
            TextColumn::make('name')->label('Name')->searchable()->limit(40),
            TextColumn::make('group_mask')->label('Group')->toggleable(),
            TextColumn::make('is_active')
                ->label('Active')
                ->badge()
                ->formatStateUsing(fn ($state) => $state ? 'Yes' : 'No')
                ->color(fn ($state) => $state ? 'success' : 'gray'),
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
            ['label' => 'Accounts', 'value' => SapChartOfAccount::count(), 'hint' => 'Chart of accounts rows'],
            ['label' => 'Categories', 'value' => SapAccountCategory::count(), 'hint' => 'Account categories'],
            ['label' => 'Periods', 'value' => SapFinancialPeriod::count(), 'hint' => 'Financial periods'],
            ['label' => 'Banks', 'value' => SapBank::count(), 'hint' => 'Bank masters'],
            ['label' => 'Bank Accounts', 'value' => SapBankAccount::count(), 'hint' => 'House bank accounts'],
            ['label' => 'Currencies', 'value' => SapCurrency::count(), 'hint' => 'Currency masters'],
            ['label' => 'Exchange Rates', 'value' => SapExchangeRate::count(), 'hint' => 'FX rate snapshots'],
            ['label' => 'Payment Terms', 'value' => SapPaymentTerm::count(), 'hint' => 'Payment term groups'],
            ['label' => 'Profit Centers', 'value' => SapProfitCenter::count(), 'hint' => 'Cost accounting dimensions'],
            ['label' => 'Branches', 'value' => SapBranch::count(), 'hint' => 'Business places'],
            ['label' => 'Customer Finance', 'value' => SapCustomerFinance::count(), 'hint' => 'A/R balance snapshots'],
        ];
    }

    public function getQuickLinks(): array
    {
        return [
            ['label' => 'Chart Of Accounts', 'description' => 'Full account list', 'url' => SapChartOfAccountsCatalog::getUrl()],
            ['label' => 'Account Categories', 'description' => 'Grouping and reporting categories', 'url' => SapAccountCategoriesCatalog::getUrl()],
            ['label' => 'Financial Periods', 'description' => 'Posting period snapshots', 'url' => SapFinancialPeriodsCatalog::getUrl()],
            ['label' => 'Banks', 'description' => 'Bank master records', 'url' => SapBanksCatalog::getUrl()],
            ['label' => 'Bank Accounts', 'description' => 'House bank accounts', 'url' => SapBankAccountsCatalog::getUrl()],
            ['label' => 'Currencies', 'description' => 'Currency master rows', 'url' => SapCurrenciesCatalog::getUrl()],
            ['label' => 'Exchange Rates', 'description' => 'FX rate snapshots', 'url' => SapExchangeRatesCatalog::getUrl()],
            ['label' => 'Payment Terms', 'description' => 'Payment term groups', 'url' => SapPaymentTermsCatalog::getUrl()],
            ['label' => 'Profit Centers', 'description' => 'Cost accounting dimensions', 'url' => SapProfitCentersCatalog::getUrl()],
            ['label' => 'Branches', 'description' => 'Business place snapshots', 'url' => SapBranchesCatalog::getUrl()],
            ['label' => 'Customer Finance', 'description' => 'A/R balances by customer', 'url' => SapCustomerFinanceCatalog::getUrl()],
            ['label' => 'Finance Documents', 'description' => 'A/R and A/P documents', 'url' => SapFinanceDocuments::getUrl()],
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
