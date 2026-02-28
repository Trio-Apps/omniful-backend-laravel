<?php

namespace App\Filament\Pages;

use App\Models\OmnifulProductEvent;
use Filament\Pages\Page;
use Illuminate\Database\Eloquent\ModelNotFoundException;

class OmnifulProductEventView extends Page
{
    protected static bool $shouldRegisterNavigation = false;

    protected string $view = 'filament.pages.omniful-product-event-view';

    public OmnifulProductEvent $record;

    public array $event = [];

    public array $data = [];

    public array $rows = [];

    public string $payloadJson = '';

    public function mount(int|string|null $record = null): void
    {
        $recordId = $record ?? request()->query('record');
        if ($recordId === null || $recordId === '') {
            throw new ModelNotFoundException();
        }

        $model = OmnifulProductEvent::find($recordId);
        if (!$model) {
            throw new ModelNotFoundException();
        }

        $this->record = $model;
        $this->event = $model->payload ?? [];
        $rawData = data_get($model->payload, 'data', []);
        $this->rows = is_array($rawData)
            ? (array_is_list($rawData) ? $rawData : [$rawData])
            : [];
        $this->data = $this->rows[0] ?? [];
        $this->payloadJson = json_encode($model->payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) ?: '';
    }

    public function getTitle(): string
    {
        $sku = data_get($this->data, 'sku_code') ?? data_get($this->data, 'seller_sku_code');
        return $sku ? "Product {$sku}" : 'Product Event';
    }
}
