<?php

namespace App\Filament\Widgets;

use App\Filament\Pages\SapBankingCatalog;
use App\Filament\Pages\SapCatalogOverview;
use App\Filament\Pages\SapFinanceDocuments;
use App\Filament\Pages\SapFinanceMasterData;
use App\Filament\Pages\SapInventoryCatalog;
use App\Filament\Pages\SapSalesCatalog;
use Filament\Widgets\Widget;

class SapCatalogQuickLinks extends Widget
{
    protected static string $view = 'filament.widgets.sap-catalog-quick-links';

    protected int | string | array $columnSpan = 'full';

    protected function getViewData(): array
    {
        return [
            'links' => [
                ['label' => 'SAP Catalog Hub', 'hint' => 'Central SAP workspace', 'url' => SapCatalogOverview::getUrl()],
                ['label' => 'Finance Master', 'hint' => 'Accounts, banks, periods, FX', 'url' => SapFinanceMasterData::getUrl()],
                ['label' => 'Finance Documents', 'hint' => 'A/R and A/P snapshots', 'url' => SapFinanceDocuments::getUrl()],
                ['label' => 'Sales Catalog', 'hint' => 'Quotations, returns, item groups', 'url' => SapSalesCatalog::getUrl()],
                ['label' => 'Inventory Catalog', 'hint' => 'Transfers, counts, production', 'url' => SapInventoryCatalog::getUrl()],
                ['label' => 'Banking Catalog', 'hint' => 'Deposits and checks', 'url' => SapBankingCatalog::getUrl()],
            ],
        ];
    }
}
