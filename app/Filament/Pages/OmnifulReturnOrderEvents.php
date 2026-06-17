<?php

namespace App\Filament\Pages;

use Filament\Actions\Action;
use App\Models\OmnifulReturnOrderEvent;
use App\Services\Webhooks\WebhookRetryService;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use App\Filament\Pages\OmnifulReturnOrderEventView;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Illuminate\Database\Eloquent\Builder;

class OmnifulReturnOrderEvents extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-arrow-uturn-left';

    protected static ?string $navigationLabel = 'Return Order Events';

    protected static string | \UnitEnum | null $navigationGroup = 'Monitoring';

    protected static ?int $navigationSort = 70;

    protected string $view = 'filament.pages.omniful-return-order-events';

    protected function getTableQuery(): Builder
    {
        return OmnifulReturnOrderEvent::query()->orderByDesc('received_at');
    }

    protected function getTableColumns(): array
    {
        return [
            TextColumn::make('return_order_id')
                ->label('Return ID')
                ->getStateUsing(fn ($record) => data_get($record->payload, 'data.return_order_id'))
                ->searchable(query: fn (Builder $query, string $search) => $query->where('external_id', 'like', "%{$search}%"))
                ->toggleable(),
            TextColumn::make('order_reference_id')
                ->label('Order Ref')
                ->getStateUsing(fn ($record) => data_get($record->payload, 'data.order_reference_id', data_get($record->payload, 'data.order_id')))
                ->searchable(query: fn (Builder $query, string $search) => $query->where('payload', 'like', "%{$search}%"))
                ->toggleable(),
            TextColumn::make('event_name')
                ->label('Event')
                ->getStateUsing(fn ($record) => data_get($record->payload, 'event_name', '-'))
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
            IconColumn::make('signature_valid')
                ->label('Signature')
                ->boolean()
                ->toggleable(),
            TextColumn::make('received_at')
                ->label('Received')
                ->dateTime()
                ->sortable(),
        ];
    }

    protected function getTableFilters(): array
    {
        return [
            SelectFilter::make('sap_status')
                ->label('SAP Status')
                ->options([
                    'created' => 'Created',
                    'failed' => 'Failed',
                    'ignored' => 'Ignored',
                    'skipped' => 'Skipped',
                ]),
        ];
    }

    protected function getTableActions(): array
    {
        return [
            Action::make('view')
                ->label('View')
                ->icon('heroicon-o-eye')
                ->url(fn ($record) => OmnifulReturnOrderEventView::getUrl(['record' => $record])),
            Action::make('sapError')
                ->label('Error')
                ->icon('heroicon-o-exclamation-triangle')
                ->color('danger')
                ->visible(fn ($record) => (bool) $record->sap_error)
                ->modalHeading('SAP Error')
                ->modalSubmitAction(false)
                ->modalCancelActionLabel('Close')
                ->modalContent(fn ($record) => view('filament.pages.omniful-return-order-sap-error', [
                    'error' => $record->sap_error,
                ])),
            Action::make('retrySap')
                ->label('Retry')
                ->icon('heroicon-o-arrow-path')
                ->color('warning')
                ->visible(fn ($record) => !$record->isSapFlowComplete())
                ->requiresConfirmation()
                ->modalHeading('Retry return order SAP sync?')
                ->modalDescription('Re-runs the return in SAP and completes any unfinished step (e.g. the COGS reversal). Already-created documents are not duplicated.')
                ->action(function ($record) {
                    $result = app(WebhookRetryService::class)->retryReturnOrderEvent($record);
                    Notification::make()
                        ->title($result['ok'] ? 'Retry completed' : 'Retry failed')
                        ->body($result['message'])
                        ->{$result['ok'] ? 'success' : 'danger'}()
                        ->send();
                }),
        ];
    }
}
