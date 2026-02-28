<?php

namespace App\Filament\Pages;

use App\Filament\Pages\Concerns\InteractsWithSapCatalogPage;
use App\Models\SapBankingDocument;
use App\Services\MasterData\SapBankingCatalogSyncService;
use App\Services\SapServiceLayerClient;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Illuminate\Database\Eloquent\Builder;

class SapBankingCatalog extends Page implements HasTable
{
    use InteractsWithSapCatalogPage;
    use InteractsWithTable;

    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-building-library';

    protected static ?string $navigationLabel = 'SAP Banking Catalog';

    protected static string | \UnitEnum | null $navigationGroup = 'SAP Catalog';

    protected static ?int $navigationSort = 60;

    protected string $view = 'filament.pages.sap-catalog-table';

    protected function getTableQuery(): Builder
    {
        return SapBankingDocument::query()
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
                    'deposit' => 'Deposit',
                    'check_for_payment' => 'Check For Payment',
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
            Action::make('syncBankingCatalog')
                ->label('Sync Banking Catalog')
                ->icon('heroicon-o-arrow-path')
                ->extraAttributes([
                    'wire:loading.attr' => 'disabled',
                    'wire:loading.class' => 'opacity-70',
                ])
                ->action('syncBankingCatalog'),
        ];
    }

    public function getStats(): array
    {
        return [
            ['label' => 'Deposits', 'value' => SapBankingDocument::query()->where('document_type', 'deposit')->count(), 'hint' => 'Bank deposit snapshots'],
            ['label' => 'Checks For Payment', 'value' => SapBankingDocument::query()->where('document_type', 'check_for_payment')->count(), 'hint' => 'Bank check handling'],
        ];
    }

    public function getQuickLinks(): array
    {
        return [
            ['label' => 'SAP Catalog Hub', 'description' => 'Back to central SAP workspace', 'url' => SapCatalogOverview::getUrl()],
            ['label' => 'Banks', 'description' => 'Bank master records', 'url' => SapBanksCatalog::getUrl()],
            ['label' => 'Bank Accounts', 'description' => 'House bank account details', 'url' => SapBankAccountsCatalog::getUrl()],
            ['label' => 'Finance Documents', 'description' => 'Incoming and outgoing payment snapshots', 'url' => SapFinanceDocuments::getUrl()],
        ];
    }

    public function syncBankingCatalog(SapServiceLayerClient $client): void
    {
        @ini_set('max_execution_time', '0');
        @set_time_limit(0);

        try {
            $summary = app(SapBankingCatalogSyncService::class)->syncFromSap($client);
            $this->sendSyncSuccessNotification('Banking catalog synced', $summary);
        } catch (\Throwable $exception) {
            $this->sendSyncFailureNotification($exception);
        }
    }
}
