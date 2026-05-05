<?php

namespace App\Filament\Pages;

use App\Models\OmnifulStockTransferEvent;
use Filament\Pages\Page;
use Illuminate\Database\Eloquent\ModelNotFoundException;

class OmnifulStockTransferEventView extends Page
{
    protected static bool $shouldRegisterNavigation = false;

    protected string $view = 'filament.pages.omniful-stock-transfer-event-view';

    public OmnifulStockTransferEvent $record;

    public array $event = [];

    public array $data = [];

    public array $items = [];

    public string $payloadJson = '';

    public bool $hasSapResultSummary = false;

    public array $sapResultSummary = [];

    public function mount(int|string|null $record = null): void
    {
        $recordId = $record ?? request()->query('record');
        if ($recordId === null || $recordId === '') {
            throw new ModelNotFoundException();
        }

        $model = OmnifulStockTransferEvent::find($recordId);
        if (!$model) {
            throw new ModelNotFoundException();
        }

        $this->record = $model;
        $this->event = $model->payload ?? [];
        $this->data = data_get($model->payload, 'data', []);
        $this->items = $this->extractTransferItems($model->payload ?? []);
        $this->payloadJson = json_encode($model->payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) ?: '';

        $decodedSummary = json_decode((string) ($model->sap_error ?? ''), true);
        if (is_array($decodedSummary) && ($decodedSummary['mode'] ?? '') === 'two_step_in_transit') {
            $this->hasSapResultSummary = true;
            $this->sapResultSummary = $decodedSummary;
        }
    }

    public function getTitle(): string
    {
        return 'Stock Transfer ' . ($this->record->external_id ?: 'Event');
    }

    /**
     * @param array<string,mixed> $payload
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
}
