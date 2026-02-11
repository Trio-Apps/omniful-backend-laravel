<?php

namespace App\Filament\Pages;

use App\Models\OmnifulOrder;
use App\Models\OmnifulOrderEvent;
use Filament\Pages\Page;
use Illuminate\Database\Eloquent\ModelNotFoundException;

class OmnifulOrderView extends Page
{
    protected static bool $shouldRegisterNavigation = false;

    protected string $view = 'filament.pages.omniful-order-view';

    public OmnifulOrder $record;

    public ?OmnifulOrderEvent $latestEvent = null;

    public array $payload = [];

    public array $data = [];

    public array $items = [];

    public function mount(int|string|null $record = null): void
    {
        $recordId = $record ?? request()->query('record');
        if ($recordId === null || $recordId === '') {
            throw new ModelNotFoundException();
        }

        $model = OmnifulOrder::find($recordId);
        if (!$model) {
            throw new ModelNotFoundException();
        }

        $this->record = $model;
        $this->payload = $model->last_payload ?? [];
        $this->data = (array) data_get($this->payload, 'data', []);
        $this->items = (array) data_get($this->data, 'order_items', []);
        $this->latestEvent = OmnifulOrderEvent::where('external_id', (string) $model->external_id)
            ->latest('received_at')
            ->first();
    }

    public function getTitle(): string
    {
        return 'Order ' . ($this->record->external_id ?: '#'.$this->record->id);
    }
}

