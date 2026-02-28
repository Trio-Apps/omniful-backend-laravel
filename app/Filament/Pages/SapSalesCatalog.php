<?php

namespace App\Filament\Pages;

use App\Filament\Pages\Concerns\InteractsWithSapCatalogPage;
use App\Models\SapItemGroup;
use App\Models\SapSalesDocument;
use App\Services\MasterData\SapSalesCatalogSyncService;
use App\Services\SapServiceLayerClient;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Illuminate\Database\Eloquent\Builder;

class SapSalesCatalog extends Page implements HasTable
{
    use InteractsWithSapCatalogPage;
    use InteractsWithTable;

    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-clipboard-document-list';

    protected static ?string $navigationLabel = 'SAP Sales Catalog';

    protected static string | \UnitEnum | null $navigationGroup = 'SAP Catalog';

    protected static ?int $navigationSort = 40;

    protected string $view = 'filament.pages.sap-catalog-table';

    protected function getTableQuery(): Builder
    {
        return SapSalesDocument::query()
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
            TextColumn::make('card_code')->label('Customer')->searchable(),
            TextColumn::make('doc_date')->label('Doc Date')->date(),
            TextColumn::make('amount')
                ->label('Amount')
                ->alignEnd()
                ->formatStateUsing(fn ($state) => $state !== null ? number_format((float) $state, 2) : '-'),
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
                    'quotation' => 'Quotation',
                    'return' => 'Return',
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
            Action::make('syncSalesCatalog')
                ->label('Sync Sales Catalog')
                ->icon('heroicon-o-arrow-path')
                ->extraAttributes([
                    'wire:loading.attr' => 'disabled',
                    'wire:loading.class' => 'opacity-70',
                ])
                ->action('syncSalesCatalog'),
        ];
    }

    public function getStats(): array
    {
        return [
            ['label' => 'Item Groups', 'value' => SapItemGroup::count(), 'hint' => 'Item grouping master data'],
            ['label' => 'Quotations', 'value' => SapSalesDocument::query()->where('document_type', 'quotation')->count(), 'hint' => 'Sales quotations'],
            ['label' => 'Returns', 'value' => SapSalesDocument::query()->where('document_type', 'return')->count(), 'hint' => 'A/R returns snapshots'],
        ];
    }

    public function syncSalesCatalog(SapServiceLayerClient $client): void
    {
        @ini_set('max_execution_time', '0');
        @set_time_limit(0);

        try {
            $summary = app(SapSalesCatalogSyncService::class)->syncFromSap($client);
            $this->sendSyncSuccessNotification('Sales catalog synced', $summary);
        } catch (\Throwable $exception) {
            $this->sendSyncFailureNotification($exception);
        }
    }
}
