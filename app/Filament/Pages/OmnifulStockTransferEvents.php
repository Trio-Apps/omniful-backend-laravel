<?php

namespace App\Filament\Pages;

use App\Filament\Pages\OmnifulStockTransferEventView;
use App\Models\OmnifulStockTransferEvent;
use App\Services\Webhooks\WebhookRetryService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Illuminate\Database\Eloquent\Builder;

class OmnifulStockTransferEvents extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-arrows-right-left';

    protected static ?string $navigationLabel = 'Stock Transfer Events';

    protected static string | \UnitEnum | null $navigationGroup = 'Monitoring';

    protected static ?int $navigationSort = 55;

    protected string $view = 'filament.pages.omniful-stock-transfer-events';

    protected function getTableQuery(): Builder
    {
        return OmnifulStockTransferEvent::query()->orderByDesc('received_at');
    }

    protected function getTableColumns(): array
    {
        return [
            TextColumn::make('external_id')
                ->label('Reference')
                ->searchable()
                ->toggleable(),
            TextColumn::make('event_name')
                ->label('Event')
                ->getStateUsing(fn ($record) => data_get($record->payload, 'event_name'))
                ->toggleable(),
            TextColumn::make('status')
                ->label('Status')
                ->badge()
                ->getStateUsing(fn ($record) => data_get($record->payload, 'data.status', data_get($record->payload, 'status')))
                ->toggleable(),
            TextColumn::make('from_warehouse')
                ->label('From')
                ->getStateUsing(fn ($record) => $this->extractFromWarehouse((array) ($record->payload ?? [])))
                ->toggleable(),
            TextColumn::make('to_warehouse')
                ->label('To')
                ->getStateUsing(fn ($record) => $this->extractToWarehouse((array) ($record->payload ?? [])))
                ->toggleable(),
            TextColumn::make('items_count')
                ->label('Items')
                ->getStateUsing(fn ($record) => count($this->extractTransferItems((array) ($record->payload ?? []))))
                ->toggleable(),
            TextColumn::make('sap_status')
                ->label('SAP')
                ->badge()
                ->color(fn ($state) => match ($state) {
                    'created', 'created_via_transit' => 'success',
                    'failed' => 'danger',
                    'ignored', 'skipped' => 'gray',
                    default => 'gray',
                })
                ->toggleable(),
            TextColumn::make('sap_doc_num')
                ->label('SAP DocNum')
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
                ->url(fn ($record) => OmnifulStockTransferEventView::getUrl(['record' => $record])),
            Action::make('sapError')
                ->label('Reason')
                ->icon('heroicon-o-exclamation-triangle')
                ->color('warning')
                ->visible(fn ($record) => (bool) $record->sap_error)
                ->modalHeading('SAP / Ignore Reason')
                ->modalSubmitAction(false)
                ->modalCancelActionLabel('Close')
                ->modalContent(fn ($record) => view('filament.pages.omniful-inventory-sap-error', [
                    'error' => $record->sap_error,
                ])),
            Action::make('retrySap')
                ->label('Retry SAP')
                ->icon('heroicon-o-arrow-path')
                ->color('warning')
                ->visible(fn ($record) => (bool) $record->sap_error || in_array((string) $record->sap_status, ['failed', 'retrying', 'pending'], true))
                ->action(function ($record) {
                    $result = app(WebhookRetryService::class)->retryStockTransferEvent($record);
                    Notification::make()
                        ->title($result['ok'] ? 'Retry completed' : 'Retry failed')
                        ->body($result['message'])
                        ->{$result['ok'] ? 'success' : 'danger'}()
                        ->send();
                }),
        ];
    }

    private function extractFromWarehouse(array $payload): string
    {
        return $this->firstPayloadValue($payload, [
            'data.from_hub_code',
            'data.source_hub_code',
            'data.source_hub.code',
            'data.source_warehouse_code',
            'data.from_warehouse_code',
            'data.origin_hub_code',
            'from_hub_code',
            'source_hub_code',
            'source_hub.code',
        ]);
    }

    private function extractToWarehouse(array $payload): string
    {
        return $this->firstPayloadValue($payload, [
            'data.to_hub_code',
            'data.destination_hub_code',
            'data.destination_hub.code',
            'data.destination_warehouse_code',
            'data.to_warehouse_code',
            'to_hub_code',
            'destination_hub_code',
            'destination_hub.code',
        ]);
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function extractTransferItems(array $payload): array
    {
        $sources = [
            data_get($payload, 'data.stock_transfer_items', []),
            data_get($payload, 'data.transfer_items', []),
            data_get($payload, 'data.order_items', []),
            data_get($payload, 'data.items', []),
            data_get($payload, 'stock_transfer_items', []),
            data_get($payload, 'order_items', []),
            data_get($payload, 'items', []),
        ];

        foreach ($sources as $source) {
            if (is_array($source) && $source !== []) {
                return $source;
            }
        }

        return [];
    }

    /**
     * @param array<int,string> $paths
     */
    private function firstPayloadValue(array $payload, array $paths): string
    {
        foreach ($paths as $path) {
            $value = data_get($payload, $path);
            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
            if (is_numeric($value)) {
                return (string) $value;
            }
        }

        return '-';
    }
}
