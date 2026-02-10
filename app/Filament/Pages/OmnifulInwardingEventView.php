<?php

namespace App\Filament\Pages;

use App\Models\OmnifulInwardingEvent;
use Filament\Pages\Page;
use Illuminate\Database\Eloquent\ModelNotFoundException;

class OmnifulInwardingEventView extends Page
{
    protected static bool $shouldRegisterNavigation = false;

    protected string $view = 'filament.pages.omniful-inwarding-event-view';

    public OmnifulInwardingEvent $record;

    public array $event = [];

    public array $data = [];

    public string $payloadJson = '';

    public function mount(int|string|null $record = null): void
    {
        $recordId = $record ?? request()->query('record');
        if ($recordId === null || $recordId === '') {
            throw new ModelNotFoundException();
        }

        $model = OmnifulInwardingEvent::find($recordId);
        if (!$model) {
            throw new ModelNotFoundException();
        }

        $this->record = $model;
        $this->event = $model->payload ?? [];
        $rawData = data_get($model->payload, 'data', []);
        $this->data = is_array($rawData) ? $rawData : [];
        $this->payloadJson = json_encode($model->payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) ?: '';
    }

    public function getTitle(): string
    {
        $reference = $this->record->external_id ?: data_get($this->event, 'event_name');
        return $reference ? "Inwarding {$reference}" : 'Inwarding Event';
    }
}
