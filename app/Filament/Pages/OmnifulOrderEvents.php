<?php

namespace App\Filament\Pages;

use App\Models\OmnifulOrderEvent;
use App\Models\OmnifulOrder;
use App\Filament\Pages\OmnifulOrderEventView;
use App\Services\Webhooks\WebhookRetryService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Illuminate\Database\Eloquent\Builder;

class OmnifulOrderEvents extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-bell';

    protected static ?string $navigationLabel = 'Order Events';

    protected static string | \UnitEnum | null $navigationGroup = 'Monitoring';

    protected static ?int $navigationSort = 20;

    protected string $view = 'filament.pages.omniful-order-events';

    /** @var array<string, OmnifulOrder|null> */
    protected array $orderCache = [];

    protected function getTableQuery(): Builder
    {
        return OmnifulOrderEvent::query()->orderByDesc('received_at');
    }

    protected function getTableColumns(): array
    {
        return [
            TextColumn::make('external_id')
                ->label('Order ID')
                ->getStateUsing(fn ($record) => $record->external_id
                    ?? data_get($record->payload, 'data.order_id')
                    ?? data_get($record->payload, 'data.id'))
                ->searchable(),
            TextColumn::make('event_name')
                ->label('Event')
                ->getStateUsing(fn ($record) => data_get($record->payload, 'event_name'))
                ->toggleable(),
            IconColumn::make('signature_valid')
                ->label('Signature')
                ->boolean()
                ->toggleable(),
            TextColumn::make('status')
                ->label('Status')
                ->badge()
                ->getStateUsing(fn ($record) => data_get($record->payload, 'data.status_code'))
                ->toggleable(),
            TextColumn::make('seller_code')
                ->label('Seller')
                ->getStateUsing(fn ($record) => data_get($record->payload, 'data.seller_code'))
                ->toggleable(),
            TextColumn::make('hub_code')
                ->label('Hub')
                ->getStateUsing(fn ($record) => data_get($record->payload, 'data.hub_code'))
                ->toggleable(),
            TextColumn::make('payment_method')
                ->label('Payment')
                ->getStateUsing(fn ($record) => data_get($record->payload, 'data.payment_method'))
                ->toggleable(),
            TextColumn::make('total')
                ->label('Total')
                ->getStateUsing(fn ($record) => data_get($record->payload, 'data.invoice.total'))
                ->toggleable(),
            TextColumn::make('received_at')
                ->label('Received')
                ->dateTime()
                ->sortable(),
            TextColumn::make('sap_status')
                ->label('SAP')
                ->badge()
                ->getStateUsing(fn ($record) => $this->resolveOrder($record)?->sap_status ?: '-')
                ->color(fn ($state) => match ($state) {
                    'created', 'updated', 'logged', 'created_mixed' => 'success',
                    'failed' => 'danger',
                    'ignored', 'blocked', 'pending', 'retrying' => 'warning',
                    default => 'gray',
                })
                ->toggleable(),
            TextColumn::make('sap_doc_num')
                ->label('SAP Order')
                ->getStateUsing(fn ($record) => $this->resolveOrder($record)?->sap_doc_num ?: '-')
                ->toggleable(),
            TextColumn::make('sap_credit_note_status')
                ->label('Credit Note')
                ->badge()
                ->getStateUsing(fn ($record) => $this->resolveOrder($record)?->sap_credit_note_status ?: '-')
                ->color(fn ($state) => match ($state) {
                    'created', 'updated', 'logged' => 'success',
                    'failed' => 'danger',
                    'ignored', 'blocked', 'pending', 'retrying' => 'warning',
                    default => 'gray',
                })
                ->toggleable(),
        ];
    }

    protected function getTableActions(): array
    {
        return [
            Action::make('view')
                ->label('View')
                ->icon('heroicon-o-eye')
                ->url(fn ($record) => OmnifulOrderEventView::getUrl(['record' => $record])),
            Action::make('retrySap')
                ->label('Retry SAP')
                ->icon('heroicon-o-arrow-path')
                ->color('warning')
                ->action(function ($record) {
                    $result = app(WebhookRetryService::class)->retryOrderEvent($record);
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
                ->visible(fn ($record) => (bool) $this->resolveOrder($record)?->sap_error)
                ->modalHeading('SAP Error')
                ->modalSubmitAction(false)
                ->modalCancelActionLabel('Close')
                ->modalContent(fn ($record) => view('filament.pages.sap-sync-error', [
                    'error' => $this->resolveOrder($record)?->sap_error,
                ])),
        ];
    }

    protected function resolveOrder(object $record): ?OmnifulOrder
    {
        $externalId = (string) ($record->external_id ?? '');
        if ($externalId === '') {
            return null;
        }

        if (! array_key_exists($externalId, $this->orderCache)) {
            $this->orderCache[$externalId] = OmnifulOrder::where('external_id', $externalId)->first();
        }

        return $this->orderCache[$externalId];
    }
}
