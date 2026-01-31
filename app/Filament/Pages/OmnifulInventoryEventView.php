<?php

namespace App\Filament\Pages;

use App\Models\OmnifulInventoryEvent;
use Filament\Pages\Page;
use Illuminate\Database\Eloquent\ModelNotFoundException;

class OmnifulInventoryEventView extends Page
{
    protected static bool $shouldRegisterNavigation = false;

    protected string $view = 'filament.pages.omniful-inventory-event-view';

    public OmnifulInventoryEvent $record;

    public array $event = [];

    public array $data = [];

    public array $items = [];

    public function mount(int|string|null $record = null): void
    {
        $recordId = $record ?? request()->query('record');
        if ($recordId === null || $recordId === '') {
            throw new ModelNotFoundException();
        }

        $model = OmnifulInventoryEvent::find($recordId);
        if (!$model) {
            throw new ModelNotFoundException();
        }

        $this->record = $model;
        $this->event = $model->payload ?? [];
        $this->data = data_get($model->payload, 'data', []);
        $this->items = data_get($this->data, 'hub_inventory_items', data_get($this->data, 'items', []));
    }

    public function getTitle(): string
    {
        $hub = data_get($this->data, 'hub_code');
        $action = data_get($this->event, 'action');
        if ($hub && $action) {
            return "Inventory {$action} ({$hub})";
        }

        return 'Inventory Event';
    }
}
