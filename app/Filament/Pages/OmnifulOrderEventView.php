<?php

namespace App\Filament\Pages;

use App\Models\OmnifulOrderEvent;
use Filament\Pages\Page;
use Illuminate\Database\Eloquent\ModelNotFoundException;

class OmnifulOrderEventView extends Page
{
    protected static bool $shouldRegisterNavigation = false;

    protected string $view = 'filament.pages.omniful-order-event-view';

    public OmnifulOrderEvent $record;

    public array $event = [];

    public array $data = [];

    public array $items = [];

    public function mount(int|string|null $record = null): void
    {
        $recordId = $record ?? request()->query('record');
        if ($recordId === null || $recordId === '') {
            throw new ModelNotFoundException();
        }

        $model = OmnifulOrderEvent::find($recordId);
        if (!$model) {
            throw new ModelNotFoundException();
        }

        $this->record = $model;
        $this->event = $model->payload ?? [];
        $this->data = data_get($model->payload, 'data', []);
        $this->items = data_get($this->data, 'order_items', []);
    }

    public function getTitle(): string
    {
        $orderId = data_get($this->data, 'order_id');
        return $orderId ? "Order {$orderId}" : 'Order Event';
    }
}
