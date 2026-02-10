<?php

namespace App\Filament\Pages;

use Filament\Actions\Action;
use Filament\Pages\Page;
use App\Models\OmnifulPurchaseOrderEvent;
use App\Filament\Pages\OmnifulPurchaseOrderEventView;
use App\Services\Webhooks\WebhookRetryService;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Illuminate\Database\Eloquent\Builder;

class OmnifulPurchaseOrderEvents extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-clipboard-document-list';

    protected static ?string $navigationLabel = 'Purchase Order Events';

    protected static string | \UnitEnum | null $navigationGroup = 'Monitoring';

    protected string $view = 'filament.pages.omniful-purchase-order-events';

    protected function getTableQuery(): Builder
    {
        return OmnifulPurchaseOrderEvent::query()->orderByDesc('received_at');
    }

    protected function getTableColumns(): array
    {
        return [
            TextColumn::make('external_id')
                ->label('PO ID')
                ->searchable()
                ->toggleable(),
            TextColumn::make('status')
                ->label('Status')
                ->badge()
                ->getStateUsing(fn ($record) => data_get($record->payload, 'data.status'))
                ->toggleable(),
            TextColumn::make('sap_status')
                ->label('SAP')
                ->badge()
                ->getStateUsing(fn ($record) => $record->sap_status)
                ->color(fn ($record) => match ($record->sap_status) {
                    'failed' => 'danger',
                    'created' => 'success',
                    'skipped' => 'gray',
                    default => 'gray',
                })
                ->toggleable(),
            TextColumn::make('sap_doc_num')
                ->label('SAP DocNum')
                ->getStateUsing(fn ($record) => $record->sap_doc_num)
                ->toggleable(),
            TextColumn::make('hub_code')
                ->label('Hub')
                ->getStateUsing(fn ($record) => data_get($record->payload, 'data.hub_code'))
                ->toggleable(),
            TextColumn::make('received_at')
                ->label('Received')
                ->dateTime()
                ->sortable(),
        ];
    }

    protected function getTableActions(): array
    {
        return [
            Action::make('view')
                ->label('View')
                ->icon('heroicon-o-eye')
                ->url(fn ($record) => OmnifulPurchaseOrderEventView::getUrl(['record' => $record])),
            Action::make('sapError')
                ->label('Error')
                ->icon('heroicon-o-exclamation-triangle')
                ->color('danger')
                ->visible(fn ($record) => (bool) $record->sap_error)
                ->modalHeading('SAP Error')
                ->modalSubmitAction(false)
                ->modalCancelActionLabel('Close')
                ->modalContent(fn ($record) => view('filament.pages.omniful-po-sap-error', [
                    'error' => $record->sap_error,
                ])),
            Action::make('retrySap')
                ->label('Retry SAP')
                ->icon('heroicon-o-arrow-path')
                ->color('warning')
                ->action(function ($record) {
                    $result = app(WebhookRetryService::class)->retryPurchaseOrderEvent($record);
                    Notification::make()
                        ->title($result['ok'] ? 'Retry completed' : 'Retry failed')
                        ->body($result['message'])
                        ->{$result['ok'] ? 'success' : 'danger'}()
                        ->send();
                }),
        ];
    }
}
