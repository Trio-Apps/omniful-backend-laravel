<?php

namespace App\Filament\Pages;

use App\Filament\Pages\Concerns\InteractsWithSapCatalogPage;
use App\Models\SapAccountCategory;
use App\Models\SapBank;
use App\Models\SapBankAccount;
use App\Models\SapBankingDocument;
use App\Models\SapBranch;
use App\Models\SapChartOfAccount;
use App\Models\SapCurrency;
use App\Models\SapCustomerFinance;
use App\Models\SapExchangeRate;
use App\Models\SapFinanceDocument;
use App\Models\SapFinancialPeriod;
use App\Models\SapInventoryDocument;
use App\Models\SapItemGroup;
use App\Models\SapPaymentTerm;
use App\Models\SapProfitCenter;
use App\Models\SapSalesDocument;
use App\Services\MasterData\SapBankingCatalogSyncService;
use App\Services\MasterData\SapFinanceDocumentSyncService;
use App\Services\MasterData\SapFinanceMasterDataSyncService;
use App\Services\MasterData\SapInventoryCatalogSyncService;
use App\Services\MasterData\SapSalesCatalogSyncService;
use App\Services\SapServiceLayerClient;
use Filament\Actions\Action;
use Filament\Pages\Page;

class SapCatalogOverview extends Page
{
    use InteractsWithSapCatalogPage;

    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-squares-plus';

    protected static ?string $navigationLabel = 'SAP Catalog Hub';

    protected static string | \UnitEnum | null $navigationGroup = 'SAP Catalog';

    protected static ?int $navigationSort = 5;

    protected string $view = 'filament.pages.sap-catalog-overview';

    protected function getHeaderActions(): array
    {
        return [
            Action::make('syncFinanceMaster')
                ->label('Sync Finance Master')
                ->icon('heroicon-o-banknotes')
                ->action('syncFinanceMaster'),
            Action::make('syncFinanceDocuments')
                ->label('Sync Finance Docs')
                ->icon('heroicon-o-document-duplicate')
                ->action('syncFinanceDocuments'),
            Action::make('syncSalesCatalog')
                ->label('Sync Sales')
                ->icon('heroicon-o-clipboard-document-list')
                ->action('syncSalesCatalog'),
            Action::make('syncInventoryCatalog')
                ->label('Sync Inventory')
                ->icon('heroicon-o-archive-box')
                ->action('syncInventoryCatalog'),
            Action::make('syncBankingCatalog')
                ->label('Sync Banking')
                ->icon('heroicon-o-building-library')
                ->action('syncBankingCatalog'),
        ];
    }

    public function getModuleCards(): array
    {
        $financeMasterCount = SapChartOfAccount::count()
            + SapAccountCategory::count()
            + SapFinancialPeriod::count()
            + SapBank::count()
            + SapBankAccount::count()
            + SapCurrency::count()
            + SapExchangeRate::count()
            + SapPaymentTerm::count()
            + SapProfitCenter::count()
            + SapBranch::count()
            + SapCustomerFinance::count();

        return [
            [
                'title' => 'Finance Master',
                'value' => $financeMasterCount,
                'hint' => 'Accounts, periods, banks, FX, branches, and customer balances',
            ],
            [
                'title' => 'Finance Documents',
                'value' => SapFinanceDocument::count(),
                'hint' => 'A/R and A/P document snapshots',
            ],
            [
                'title' => 'Sales',
                'value' => SapSalesDocument::count() + SapItemGroup::count(),
                'hint' => 'Quotations, returns, and item grouping data',
            ],
            [
                'title' => 'Inventory',
                'value' => SapInventoryDocument::count(),
                'hint' => 'Transfer, counting, posting, and production snapshots',
            ],
            [
                'title' => 'Banking',
                'value' => SapBankingDocument::count(),
                'hint' => 'Deposits and check management snapshots',
            ],
        ];
    }

    public function getLinkGroups(): array
    {
        return [
            [
                'title' => 'Finance',
                'links' => [
                    ['label' => 'Finance Master', 'url' => SapFinanceMasterData::getUrl()],
                    ['label' => 'Chart Of Accounts', 'url' => SapChartOfAccountsCatalog::getUrl()],
                    ['label' => 'Account Categories', 'url' => SapAccountCategoriesCatalog::getUrl()],
                    ['label' => 'Financial Periods', 'url' => SapFinancialPeriodsCatalog::getUrl()],
                    ['label' => 'Banks', 'url' => SapBanksCatalog::getUrl()],
                    ['label' => 'Bank Accounts', 'url' => SapBankAccountsCatalog::getUrl()],
                    ['label' => 'Currencies', 'url' => SapCurrenciesCatalog::getUrl()],
                    ['label' => 'Exchange Rates', 'url' => SapExchangeRatesCatalog::getUrl()],
                    ['label' => 'Payment Terms', 'url' => SapPaymentTermsCatalog::getUrl()],
                    ['label' => 'Profit Centers', 'url' => SapProfitCentersCatalog::getUrl()],
                    ['label' => 'Branches', 'url' => SapBranchesCatalog::getUrl()],
                    ['label' => 'Customer Finance', 'url' => SapCustomerFinanceCatalog::getUrl()],
                    ['label' => 'Finance Documents', 'url' => SapFinanceDocuments::getUrl()],
                ],
            ],
            [
                'title' => 'Sales And Inventory',
                'links' => [
                    ['label' => 'Sales Catalog', 'url' => SapSalesCatalog::getUrl()],
                    ['label' => 'Item Groups', 'url' => SapItemGroupsCatalog::getUrl()],
                    ['label' => 'Inventory Catalog', 'url' => SapInventoryCatalog::getUrl()],
                    ['label' => 'SAP Items', 'url' => SapItems::getUrl()],
                    ['label' => 'SAP Warehouses', 'url' => SapWarehouses::getUrl()],
                    ['label' => 'SAP Suppliers', 'url' => SapSuppliers::getUrl()],
                ],
            ],
            [
                'title' => 'Banking',
                'links' => [
                    ['label' => 'Banking Catalog', 'url' => SapBankingCatalog::getUrl()],
                    ['label' => 'Banks', 'url' => SapBanksCatalog::getUrl()],
                    ['label' => 'Bank Accounts', 'url' => SapBankAccountsCatalog::getUrl()],
                    ['label' => 'Finance Documents', 'url' => SapFinanceDocuments::getUrl()],
                ],
            ],
        ];
    }

    public function syncFinanceMaster(SapServiceLayerClient $client): void
    {
        $this->runSync(
            fn () => app(SapFinanceMasterDataSyncService::class)->syncFromSap($client),
            'Finance master synced'
        );
    }

    public function syncFinanceDocuments(SapServiceLayerClient $client): void
    {
        $this->runSync(
            fn () => app(SapFinanceDocumentSyncService::class)->syncFromSap($client),
            'Finance documents synced'
        );
    }

    public function syncSalesCatalog(SapServiceLayerClient $client): void
    {
        $this->runSync(
            fn () => app(SapSalesCatalogSyncService::class)->syncFromSap($client),
            'Sales catalog synced'
        );
    }

    public function syncInventoryCatalog(SapServiceLayerClient $client): void
    {
        $this->runSync(
            fn () => app(SapInventoryCatalogSyncService::class)->syncFromSap($client),
            'Inventory catalog synced'
        );
    }

    public function syncBankingCatalog(SapServiceLayerClient $client): void
    {
        $this->runSync(
            fn () => app(SapBankingCatalogSyncService::class)->syncFromSap($client),
            'Banking catalog synced'
        );
    }

    private function runSync(callable $callback, string $title): void
    {
        @ini_set('max_execution_time', '0');
        @set_time_limit(0);

        try {
            $summary = $callback();
            $this->sendSyncSuccessNotification($title, is_array($summary) ? $summary : []);
        } catch (\Throwable $exception) {
            $this->sendSyncFailureNotification($exception);
        }
    }
}
