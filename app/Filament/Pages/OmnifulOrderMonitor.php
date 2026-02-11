<?php

namespace App\Filament\Pages;

use App\Models\OmnifulOrder;
use App\Filament\Pages\OmnifulOrderView;
use App\Services\Webhooks\WebhookRetryService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\Filter;
use Illuminate\Database\Eloquent\Builder;

class OmnifulOrderMonitor extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-rectangle-stack';

    protected static ?string $navigationLabel = 'Omniful Orders';

    protected static string | \UnitEnum | null $navigationGroup = 'Monitoring';

    protected string $view = 'filament.pages.omniful-order-monitor';

    protected function getTableQuery(): Builder
    {
        return OmnifulOrder::query()->orderByDesc('last_event_at');
    }

    protected function getTableColumns(): array
    {
        return [
            TextColumn::make('external_id')
                ->label('Order ID')
                ->searchable(),
            TextColumn::make('omniful_status')
                ->label('Omniful Status')
                ->badge()
                ->toggleable(),
            TextColumn::make('sap_status')
                ->label('SAP Status')
                ->badge()
                ->color(fn ($state) => match ($state) {
                    'created', 'updated', 'logged', 'created_mixed' => 'success',
                    'failed' => 'danger',
                    'ignored', 'blocked', 'pending', 'retrying' => 'warning',
                    default => 'gray',
                })
                ->toggleable(),
            TextColumn::make('sap_doc_num')
                ->label('SAP Order')
                ->toggleable(),
            TextColumn::make('last_event_type')
                ->label('Last Event')
                ->toggleable(),
            TextColumn::make('last_event_at')
                ->label('Last Event At')
                ->dateTime()
                ->sortable(),
        ];
    }

    protected function getTableFilters(): array
    {
        return [
            Filter::make('stuck')
                ->label('SAP Pending')
                ->query(fn (Builder $query) => $query->whereNull('sap_status')),
        ];
    }

    protected function getTableActions(): array
    {
        return [
            Action::make('view')
                ->label('View')
                ->icon('heroicon-o-eye')
                ->url(fn ($record) => OmnifulOrderView::getUrl(['record' => $record])),
            Action::make('retrySap')
                ->label('Retry SAP')
                ->icon('heroicon-o-arrow-path')
                ->color('warning')
                ->visible(fn ($record) => (bool) $record->sap_error || empty($record->sap_status) || in_array((string) $record->sap_status, ['failed', 'pending', 'retrying'], true))
                ->action(function ($record) {
                    $result = app(WebhookRetryService::class)->retryLatestOrderEventForOrder($record);
                    Notification::make()
                        ->title($result['ok'] ? 'Retry completed' : 'Retry failed')
                        ->body($result['message'])
                        ->{$result['ok'] ? 'success' : 'danger'}()
                        ->send();
                }),
            Action::make('sapError')
                ->label('SAP Error')
                ->icon('heroicon-o-exclamation-triangle')
                ->color('danger')
                ->visible(fn ($record) => (bool) $record->sap_error)
                ->modalHeading('SAP Error')
                ->modalSubmitAction(false)
                ->modalCancelActionLabel('Close')
                ->modalContent(fn ($record) => view('filament.pages.sap-sync-error', [
                    'error' => $record->sap_error,
                ])),
        ];
    }
}
