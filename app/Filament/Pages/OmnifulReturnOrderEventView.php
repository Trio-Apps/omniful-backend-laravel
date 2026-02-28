<?php

namespace App\Filament\Pages;

use App\Models\OmnifulReturnOrderEvent;
use Filament\Pages\Page;
use Illuminate\Database\Eloquent\ModelNotFoundException;

class OmnifulReturnOrderEventView extends Page
{
    protected static bool $shouldRegisterNavigation = false;

    protected string $view = 'filament.pages.omniful-return-order-event-view';

    public OmnifulReturnOrderEvent $record;

    public array $event = [];

    public array $data = [];

    public array $items = [];

    public string $payloadJson = '';

    public function mount(int|string|null $record = null): void
    {
        $recordId = $record ?? request()->query('record');
        if ($recordId === null || $recordId === '') {
            throw new ModelNotFoundException();
        }

        $model = OmnifulReturnOrderEvent::find($recordId);
        if (!$model) {
            throw new ModelNotFoundException();
        }

        $this->record = $model;
        $this->event = $model->payload ?? [];
        $this->data = data_get($model->payload, 'data', []);
        $this->items = data_get($this->data, 'return_items', data_get($this->data, 'order_items', []));
        $this->payloadJson = json_encode($model->payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) ?: '';
    }

    public function getTitle(): string
    {
        $displayId = data_get($this->data, 'return_order_id')
            ?? data_get($this->data, 'id');

        return $displayId ? "Return {$displayId}" : 'Return Order Details';
    }
}
