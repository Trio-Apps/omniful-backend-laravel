<?php

namespace App\Filament\Pages;

use App\Models\OmnifulReturnOrderEvent;
use App\Services\Webhooks\WebhookRetryService;
use App\Support\Utf8;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
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

    public array $flowSteps = [];

    public array $flowSummary = [];

    public array $debugPayloads = [];

    public array $sapResponses = [];

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
        $this->payloadJson = Utf8::jsonEncode($model->payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $this->flowSteps = $this->buildFlowSteps();
        $this->flowSummary = $this->buildFlowSummary($this->flowSteps);
        $this->debugPayloads = $this->buildDebugPayloads();
        $this->sapResponses = $this->buildSapResponses();
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('retry')
                ->label('Retry')
                ->icon('heroicon-o-arrow-path')
                ->color('warning')
                ->visible(fn () => !$this->record->isSapFlowComplete())
                ->requiresConfirmation()
                ->modalHeading('Retry return order SAP sync?')
                ->modalDescription('Re-runs the return in SAP and completes any unfinished step (e.g. the COGS reversal). Already-created documents are not duplicated.')
                ->action(function () {
                    $result = app(WebhookRetryService::class)->retryReturnOrderEvent($this->record);

                    Notification::make()
                        ->title($result['ok'] ? 'Retry completed' : 'Retry failed')
                        ->body($result['message'])
                        ->{$result['ok'] ? 'success' : 'danger'}()
                        ->send();

                    $this->redirect(static::getUrl(['record' => $this->record->getKey()]));
                }),
        ];
    }

    public function getTitle(): string
    {
        $displayId = data_get($this->data, 'return_order_id')
            ?? data_get($this->data, 'id');

        return $displayId ? "Return {$displayId}" : 'Return Order Details';
    }

    private function buildFlowSteps(): array
    {
        return [
            $this->makeStep(
                key: 'credit_note',
                title: 'Create AR Credit Memo',
                status: (string) ($this->record->sap_status ?: ''),
                reference: (string) ($this->record->sap_doc_num ?: $this->record->sap_doc_entry ?: ''),
                error: (string) ($this->record->sap_error ?: '')
            ),
            $this->makeStep(
                key: 'cogs_reversal',
                title: 'Create COGS Reversal',
                status: (string) ($this->record->sap_cogs_reversal_status ?: ''),
                reference: (string) ($this->record->sap_cogs_reversal_journal_num ?: $this->record->sap_cogs_reversal_journal_entry ?: ''),
                error: (string) ($this->record->sap_cogs_reversal_error ?: '')
            ),
        ];
    }

    private function makeStep(string $key, string $title, string $status, string $reference, ?string $error): array
    {
        $normalizedStatus = trim($status);

        if ($normalizedStatus === '' && $reference !== '') {
            $normalizedStatus = 'created';
        }

        if ($normalizedStatus === '') {
            $normalizedStatus = 'not_started';
        }

        return [
            'key' => $key,
            'title' => $title,
            'status' => $normalizedStatus,
            'reference' => $reference !== '' ? $reference : '-',
            'error' => trim((string) $error) !== '' ? $error : null,
            'tone' => $this->resolveStepTone($normalizedStatus),
        ];
    }

    private function resolveStepTone(string $status): string
    {
        return match ($status) {
            'created', 'updated', 'logged', 'skipped' => 'success',
            'failed' => 'danger',
            'ignored', 'blocked', 'pending', 'retrying', 'running' => 'warning',
            'not_started' => 'gray',
            default => 'gray',
        };
    }

    private function buildFlowSummary(array $steps): array
    {
        $completedStatuses = ['created', 'updated', 'logged', 'skipped'];
        $issueStatuses = ['failed', 'blocked', 'ignored', 'retrying', 'pending', 'running'];
        $completedCount = count(array_filter($steps, fn (array $step) => in_array($step['status'], $completedStatuses, true)));
        $currentStep = null;

        foreach ($steps as $step) {
            if (in_array($step['status'], $issueStatuses, true)) {
                $currentStep = $step;
                break;
            }
        }

        if ($currentStep === null) {
            foreach ($steps as $step) {
                if ($step['status'] === 'not_started') {
                    $currentStep = $step;
                    break;
                }
            }
        }

        $overallTone = 'success';
        $overallLabel = 'Completed';
        if ($currentStep !== null) {
            $overallTone = in_array($currentStep['status'], ['failed', 'blocked'], true) ? 'danger' : 'warning';
            $overallLabel = in_array($currentStep['status'], ['failed', 'blocked'], true) ? 'Blocked' : 'In Progress';
        }

        return [
            'current_key' => $currentStep['key'] ?? null,
            'current_title' => $currentStep['title'] ?? 'Flow completed',
            'overall_label' => $overallLabel,
            'overall_tone' => $overallTone,
            'completed_count' => $completedCount,
            'relevant_count' => count($steps),
            'progress_percent' => count($steps) > 0 ? (int) round(($completedCount / count($steps)) * 100) : 0,
        ];
    }

    private function buildDebugPayloads(): array
    {
        return [
            [
                'key' => 'credit_note',
                'title' => 'AR Credit Memo Payload',
                'payload' => $this->resolveDisplayedStepPayload('credit_note', $this->buildCreditMemoFallbackPayload()),
            ],
            [
                'key' => 'cogs_reversal',
                'title' => 'COGS Reversal Payload',
                'payload' => $this->resolveDisplayedStepPayload('cogs_reversal', [
                    'credit_memo_doc_entry' => $this->record->sap_doc_entry,
                    'reference' => $this->record->external_id,
                    'memo' => 'COGS reversal from Omniful return ' . (string) ($this->record->external_id ?? ''),
                ]),
            ],
        ];
    }

    private function buildSapResponses(): array
    {
        return [
            [
                'key' => 'credit_note',
                'title' => 'AR Credit Memo SAP Response',
                'payload' => $this->record->sap_response,
                'summary' => [
                    'doc_entry' => $this->record->sap_doc_entry,
                    'doc_num' => $this->record->sap_doc_num,
                    'status' => $this->record->sap_status,
                ],
            ],
            [
                'key' => 'cogs_reversal',
                'title' => 'COGS Reversal SAP Response',
                'payload' => $this->record->sap_cogs_reversal_response,
                'summary' => [
                    'journal_entry' => $this->record->sap_cogs_reversal_journal_entry,
                    'journal_num' => $this->record->sap_cogs_reversal_journal_num,
                    'status' => $this->record->sap_cogs_reversal_status,
                ],
            ],
        ];
    }

    private function resolveDisplayedStepPayload(string $key, array $fallback): array
    {
        $response = match ($key) {
            'credit_note' => $this->record->sap_response,
            'cogs_reversal' => $this->record->sap_cogs_reversal_response,
            default => null,
        };

        if (is_array($response) && is_array($response['request_body'] ?? null)) {
            return $response['request_body'];
        }

        return $fallback;
    }

    private function buildCreditMemoFallbackPayload(): array
    {
        return [
            'external_id' => $this->record->external_id,
            'return_order_id' => data_get($this->data, 'return_order_id'),
            'order_reference_id' => data_get($this->data, 'order_reference_id'),
            'hub_code' => data_get($this->data, 'hub_code'),
            'customer' => [
                'email' => data_get($this->data, 'customer.email'),
                'mobile' => data_get($this->data, 'customer.mobile'),
            ],
            'invoice' => [
                'currency' => data_get($this->data, 'invoice.currency'),
                'subtotal' => $this->roundDisplayAmount(data_get($this->data, 'invoice.subtotal')),
                'tax' => $this->roundDisplayAmount(data_get($this->data, 'invoice.tax')),
                'discount' => $this->roundDisplayAmount(data_get($this->data, 'invoice.discount')),
                'total' => $this->roundDisplayAmount(data_get($this->data, 'invoice.total', data_get($this->data, 'total'))),
            ],
            'items' => collect($this->items)
                ->map(fn (array $item) => [
                    'sku_code' => data_get($item, 'seller_sku.seller_sku_code', data_get($item, 'seller_sku_code', data_get($item, 'sku_code'))),
                    'return_quantity' => data_get($item, 'return_quantity', data_get($item, 'returned_quantity', data_get($item, 'delivered_quantity'))),
                    'unit_price' => $this->roundDisplayAmount(data_get($item, 'unit_price', data_get($item, 'selling_price'))),
                    'tax_percent' => data_get($item, 'tax_percent'),
                    'tax' => $this->roundDisplayAmount(data_get($item, 'tax')),
                    'total' => $this->roundDisplayAmount(data_get($item, 'total')),
                ])
                ->values()
                ->all(),
        ];
    }

    private function roundDisplayAmount(mixed $value): mixed
    {
        return is_numeric($value) ? round((float) $value, 2) : $value;
    }
}
