<?php

namespace App\Filament\Pages;

use App\Filament\Pages\Concerns\InteractsWithSapCatalogPage;
use App\Models\SapInventoryDocument;
use App\Services\MasterData\SapInventoryCatalogSyncService;
use App\Services\SapServiceLayerClient;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Illuminate\Database\Eloquent\Builder;

class SapInventoryCatalog extends Page implements HasTable
{
    use InteractsWithSapCatalogPage;
    use InteractsWithTable;

    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-archive-box';

    protected static ?string $navigationLabel = 'SAP Inventory Catalog';

    protected static string | \UnitEnum | null $navigationGroup = 'SAP Catalog';

    protected static ?int $navigationSort = 50;

    protected string $view = 'filament.pages.sap-catalog-table';

    protected function getTableQuery(): Builder
    {
        return SapInventoryDocument::query()
            ->orderByDesc('synced_at')
            ->orderBy('document_type')
            ->orderByDesc('doc_date');
    }

    protected function getTableColumns(): array
    {
        return [
            TextColumn::make('document_type')
                ->label('Type')
                ->badge()
                ->formatStateUsing(fn ($state) => ucwords(str_replace('_', ' ', (string) $state))),
            TextColumn::make('doc_num')->label('Doc #')->searchable(),
            TextColumn::make('doc_entry')->label('Entry')->searchable(),
            TextColumn::make('reference_code')->label('Reference')->searchable()->limit(32),
            TextColumn::make('doc_date')->label('Doc Date')->date(),
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

    protected function getTableFilters(): array
    {
        return [
            SelectFilter::make('document_type')
                ->label('Document Type')
                ->options([
                    'inventory_transfer_request' => 'Transfer Request',
                    'inventory_counting' => 'Inventory Counting',
                    'inventory_posting' => 'Inventory Posting',
                    'production_order' => 'Production Order',
                ]),
            SelectFilter::make('status')
                ->label('Status')
                ->options([
                    'synced' => 'Synced',
                    'failed' => 'Failed',
                ]),
        ];
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('syncInventoryCatalog')
                ->label('Sync Inventory Catalog')
                ->icon('heroicon-o-arrow-path')
                ->extraAttributes([
                    'wire:loading.attr' => 'disabled',
                    'wire:loading.class' => 'opacity-70',
                ])
                ->action('syncInventoryCatalog'),
        ];
    }

    public function getStats(): array
    {
        return [
            ['label' => 'Transfer Requests', 'value' => SapInventoryDocument::query()->where('document_type', 'inventory_transfer_request')->count(), 'hint' => 'Request documents'],
            ['label' => 'Inventory Counting', 'value' => SapInventoryDocument::query()->where('document_type', 'inventory_counting')->count(), 'hint' => 'Physical counts'],
            ['label' => 'Inventory Posting', 'value' => SapInventoryDocument::query()->where('document_type', 'inventory_posting')->count(), 'hint' => 'Adjustment postings'],
            ['label' => 'Production Orders', 'value' => SapInventoryDocument::query()->where('document_type', 'production_order')->count(), 'hint' => 'Production impact snapshots'],
        ];
    }

    public function getQuickLinks(): array
    {
        return [
            ['label' => 'SAP Catalog Hub', 'description' => 'Back to central SAP workspace', 'url' => SapCatalogOverview::getUrl()],
            ['label' => 'Sales Catalog', 'description' => 'Sales-side documents and item groups', 'url' => SapSalesCatalog::getUrl()],
            ['label' => 'SAP Items', 'description' => 'Item sync workspace', 'url' => SapItems::getUrl()],
            ['label' => 'SAP Warehouses', 'description' => 'Warehouse sync workspace', 'url' => SapWarehouses::getUrl()],
        ];
    }

    public function syncInventoryCatalog(SapServiceLayerClient $client): void
    {
        @ini_set('max_execution_time', '0');
        @set_time_limit(0);

        try {
            $summary = app(SapInventoryCatalogSyncService::class)->syncFromSap($client);
            $this->sendSyncSuccessNotification('Inventory catalog synced', $summary);
        } catch (\Throwable $exception) {
            $this->sendSyncFailureNotification($exception);
        }
    }
}
