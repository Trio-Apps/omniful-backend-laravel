<?php

namespace App\Filament\Pages;

use App\Filament\Pages\Concerns\InteractsWithSapCatalogPage;
use App\Models\SapFinanceDocument;
use App\Services\MasterData\SapFinanceDocumentSyncService;
use App\Services\SapServiceLayerClient;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Illuminate\Database\Eloquent\Builder;

class SapFinanceDocuments extends Page implements HasTable
{
    use InteractsWithSapCatalogPage;
    use InteractsWithTable;

    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-document-duplicate';

    protected static ?string $navigationLabel = 'SAP Finance Documents';

    protected static string | \UnitEnum | null $navigationGroup = 'SAP Catalog';

    protected static ?int $navigationSort = 30;

    protected string $view = 'filament.pages.sap-catalog-table';

    protected function getTableQuery(): Builder
    {
        return SapFinanceDocument::query()
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
            TextColumn::make('card_code')->label('BP Code')->searchable(),
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
                    'ar_invoice' => 'A/R Invoice',
                    'ar_credit_note' => 'A/R Credit Note',
                    'ar_down_payment' => 'A/R Down Payment',
                    'incoming_payment' => 'Incoming Payment',
                    'ap_invoice' => 'A/P Invoice',
                    'ap_credit_note' => 'A/P Credit Note',
                    'ap_down_payment' => 'A/P Down Payment',
                    'vendor_payment' => 'Vendor Payment',
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
            Action::make('syncFinanceDocuments')
                ->label('Sync Finance Documents')
                ->icon('heroicon-o-arrow-path')
                ->extraAttributes([
                    'wire:loading.attr' => 'disabled',
                    'wire:loading.class' => 'opacity-70',
                ])
                ->action('syncFinanceDocuments'),
        ];
    }

    public function getStats(): array
    {
        return [
            ['label' => 'A/R Invoices', 'value' => SapFinanceDocument::query()->where('document_type', 'ar_invoice')->count(), 'hint' => 'Revenue and receivable snapshots'],
            ['label' => 'A/R Credit Notes', 'value' => SapFinanceDocument::query()->where('document_type', 'ar_credit_note')->count(), 'hint' => 'A/R credit memo snapshots'],
            ['label' => 'A/R Down Payments', 'value' => SapFinanceDocument::query()->where('document_type', 'ar_down_payment')->count(), 'hint' => 'Customer down payments'],
            ['label' => 'Incoming Payments', 'value' => SapFinanceDocument::query()->where('document_type', 'incoming_payment')->count(), 'hint' => 'Customer receipts'],
            ['label' => 'A/P Invoices', 'value' => SapFinanceDocument::query()->where('document_type', 'ap_invoice')->count(), 'hint' => 'Vendor payable snapshots'],
            ['label' => 'A/P Credit Notes', 'value' => SapFinanceDocument::query()->where('document_type', 'ap_credit_note')->count(), 'hint' => 'A/P credit memo snapshots'],
            ['label' => 'A/P Down Payments', 'value' => SapFinanceDocument::query()->where('document_type', 'ap_down_payment')->count(), 'hint' => 'Vendor down payments'],
            ['label' => 'Vendor Payments', 'value' => SapFinanceDocument::query()->where('document_type', 'vendor_payment')->count(), 'hint' => 'Outgoing payments'],
        ];
    }

    public function syncFinanceDocuments(SapServiceLayerClient $client): void
    {
        @ini_set('max_execution_time', '0');
        @set_time_limit(0);

        try {
            $summary = app(SapFinanceDocumentSyncService::class)->syncFromSap($client);
            $this->sendSyncSuccessNotification('Finance documents synced', $summary);
        } catch (\Throwable $exception) {
            $this->sendSyncFailureNotification($exception);
        }
    }
}
