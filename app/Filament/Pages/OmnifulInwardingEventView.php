<?php

namespace App\Filament\Pages;

use App\Models\OmnifulInwardingEvent;
use App\Models\OmnifulPurchaseOrderEvent;
use Filament\Pages\Page;
use Illuminate\Database\Eloquent\ModelNotFoundException;

class OmnifulInwardingEventView extends Page
{
    protected static bool $shouldRegisterNavigation = false;

    protected string $view = 'filament.pages.omniful-inwarding-event-view';

    public OmnifulInwardingEvent $record;

    public array $event = [];

    public array $data = [];

    public array $grnItems = [];

    public string $payloadJson = '';

    public array $flowSteps = [];

    public array $flowSummary = [];

    public ?OmnifulPurchaseOrderEvent $purchaseOrderEvent = null;

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
        $this->grnItems = data_get($this->data, 'grn_details.skus', []);
        $this->payloadJson = json_encode($model->payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) ?: '';
        $this->purchaseOrderEvent = $this->resolvePurchaseOrderEvent();
        $this->flowSteps = $this->buildFlowSteps();
        $this->flowSummary = $this->buildFlowSummary($this->flowSteps);
    }

    public function getTitle(): string
    {
        $reference = $this->record->external_id ?: data_get($this->event, 'event_name');
        return $reference ? "Inwarding {$reference}" : 'Inwarding Event';
    }

    private function resolvePurchaseOrderEvent(): ?OmnifulPurchaseOrderEvent
    {
        $displayId = trim((string) data_get($this->data, 'entity_id', ''));
        if ($displayId === '') {
            return null;
        }

        return OmnifulPurchaseOrderEvent::query()
            ->where('external_id', $displayId)
            ->latest()
            ->first();
    }

    private function buildFlowSteps(): array
    {
        $poMatched = $this->purchaseOrderEvent && $this->purchaseOrderEvent->sap_doc_entry;
        $sapError = trim((string) ($this->record->sap_error ?? ''));
        $poFailed = str_contains(strtolower($sapError), 'sap po not found');

        $matchStatus = $poMatched ? 'created' : ($poFailed ? 'failed' : 'pending');
        $grpoStatus = match ((string) ($this->record->sap_status ?? '')) {
            'created' => 'created',
            'failed' => 'failed',
            'ignored' => 'ignored',
            'skipped' => 'ignored',
            default => $poMatched ? 'pending' : 'not_started',
        };

        return [
            [
                'key' => 'po_match',
                'title' => 'Match SAP Purchase Order',
                'status' => $matchStatus,
                'reference' => $this->purchaseOrderEvent?->sap_doc_num
                    ?: ($this->purchaseOrderEvent?->external_id ?: trim((string) data_get($this->data, 'entity_id', ''))),
                'error' => $poFailed ? $sapError : '',
            ],
            [
                'key' => 'grpo',
                'title' => 'Create Goods Receipt PO',
                'status' => $grpoStatus,
                'reference' => $this->record->sap_doc_num ?: '',
                'error' => !$poFailed ? $sapError : '',
            ],
        ];
    }

    private function buildFlowSummary(array $steps): array
    {
        $completedStatuses = ['created', 'updated', 'logged'];
        $issueStatuses = ['failed', 'blocked', 'ignored', 'pending', 'running'];
        $completedCount = count(array_filter($steps, fn (array $step) => in_array($step['status'], $completedStatuses, true)));

        $currentKey = null;
        foreach ($steps as $step) {
            if (in_array($step['status'], $issueStatuses, true)) {
                $currentKey = (string) $step['key'];
                break;
            }
        }

        if ($currentKey === null) {
            foreach ($steps as $step) {
                if ((string) $step['status'] === 'not_started') {
                    $currentKey = (string) $step['key'];
                    break;
                }
            }
        }

        $overallTone = 'success';
        $overallLabel = 'Completed';
        if ($currentKey !== null) {
            $currentStep = collect($steps)->firstWhere('key', $currentKey);
            $currentStatus = (string) ($currentStep['status'] ?? 'not_started');
            $overallTone = in_array($currentStatus, ['failed', 'blocked'], true) ? 'danger' : 'warning';
            $overallLabel = in_array($currentStatus, ['failed', 'blocked'], true) ? 'Blocked' : 'In Progress';
        }

        return [
            'current_key' => $currentKey,
            'overall_label' => $overallLabel,
            'overall_tone' => $overallTone,
            'completed_count' => $completedCount,
            'relevant_count' => count($steps),
            'progress_percent' => count($steps) > 0 ? (int) round(($completedCount / count($steps)) * 100) : 0,
            'current_title' => $currentKey !== null
                ? (string) (collect($steps)->firstWhere('key', $currentKey)['title'] ?? '-')
                : 'Flow completed',
        ];
    }
}
