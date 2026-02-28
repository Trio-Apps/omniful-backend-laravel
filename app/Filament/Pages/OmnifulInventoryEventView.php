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

    public string $payloadJson = '';

    public bool $hasSapResultSummary = false;

    public array $sapResultSummary = [];

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
        $rawData = data_get($model->payload, 'data', []);
        if (is_array($rawData) && array_is_list($rawData)) {
            $this->data = [];
            $this->items = $rawData;
        } else {
            $this->data = is_array($rawData) ? $rawData : [];
            $this->items = data_get($this->data, 'hub_inventory_items', data_get($this->data, 'items', []));
        }
        $this->payloadJson = json_encode($model->payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) ?: '';

        $decodedSummary = json_decode((string) ($model->sap_error ?? ''), true);
        if (
            is_array($decodedSummary)
            && $decodedSummary !== []
            && array_filter(array_keys($decodedSummary), fn ($key) => in_array((string) $key, ['gr', 'gi'], true)) !== []
        ) {
            $this->hasSapResultSummary = true;
            $this->sapResultSummary = $decodedSummary;
        }
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
