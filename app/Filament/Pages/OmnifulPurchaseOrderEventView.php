<?php

namespace App\Filament\Pages;

use App\Models\OmnifulPurchaseOrderEvent;
use Filament\Pages\Page;
use Illuminate\Database\Eloquent\ModelNotFoundException;

class OmnifulPurchaseOrderEventView extends Page
{
    protected static bool $shouldRegisterNavigation = false;

    protected string $view = 'filament.pages.omniful-purchase-order-event-view';

    public OmnifulPurchaseOrderEvent $record;

    public array $event = [];

    public array $data = [];

    public array $items = [];

    public function mount(int|string|null $record = null): void
    {
        $recordId = $record ?? request()->query('record');
        if ($recordId === null || $recordId === '') {
            throw new ModelNotFoundException();
        }

        $model = OmnifulPurchaseOrderEvent::find($recordId);
        if (!$model) {
            throw new ModelNotFoundException();
        }

        $this->record = $model;
        $this->event = $model->payload ?? [];
        $this->data = data_get($model->payload, 'data', []);
        $this->items = data_get($this->data, 'purchase_order_items', []);
    }

    public function getTitle(): string
    {
        $displayId = data_get($this->data, 'display_id');
        return $displayId ? "PO {$displayId}" : 'Purchase Order Details';
    }
}
