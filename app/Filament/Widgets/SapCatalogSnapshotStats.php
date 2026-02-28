<?php

namespace App\Filament\Widgets;

use App\Models\SapBankingDocument;
use App\Models\SapChartOfAccount;
use App\Models\SapFinanceDocument;
use App\Models\SapInventoryDocument;
use App\Models\SapItemGroup;
use App\Models\SapSalesDocument;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class SapCatalogSnapshotStats extends StatsOverviewWidget
{
    protected ?string $heading = 'SAP Catalog Snapshot';

    protected ?string $description = 'Live counts for synchronized SAP snapshot tables';

    protected function getStats(): array
    {
        return [
            Stat::make('Finance Master', number_format(SapChartOfAccount::count()))
                ->description('Chart of accounts rows')
                ->descriptionIcon('heroicon-m-book-open')
                ->color('primary'),
            Stat::make('Finance Docs', number_format(SapFinanceDocument::count()))
                ->description('A/R and A/P document snapshots')
                ->descriptionIcon('heroicon-m-document-duplicate')
                ->color('success'),
            Stat::make('Sales', number_format(SapSalesDocument::count() + SapItemGroup::count()))
                ->description('Sales docs and item groups')
                ->descriptionIcon('heroicon-m-clipboard-document-list')
                ->color('info'),
            Stat::make('Inventory', number_format(SapInventoryDocument::count()))
                ->description('Inventory documents')
                ->descriptionIcon('heroicon-m-archive-box')
                ->color('warning'),
            Stat::make('Banking', number_format(SapBankingDocument::count()))
                ->description('Banking documents')
                ->descriptionIcon('heroicon-m-building-library')
                ->color('gray'),
        ];
    }
}
