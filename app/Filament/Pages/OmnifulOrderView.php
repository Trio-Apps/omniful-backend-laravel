<?php

namespace App\Filament\Pages;

use App\Models\OmnifulOrder;
use App\Models\OmnifulOrderEvent;
use Filament\Pages\Page;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class OmnifulOrderView extends Page
{
    protected static bool $shouldRegisterNavigation = false;

    protected string $view = 'filament.pages.omniful-order-view';

    public OmnifulOrder $record;

    public ?OmnifulOrderEvent $latestEvent = null;

    public array $payload = [];

    public array $data = [];

    public array $items = [];

    public array $flowSteps = [];

    public array $debugPayloads = [];

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
        $this->flowSteps = $this->buildFlowSteps();
        $this->debugPayloads = $this->buildDebugPayloads();
    }

    public function getTitle(): string
    {
        return 'Order ' . ($this->record->external_id ?: '#'.$this->record->id);
    }

    private function buildFlowSteps(): array
    {
        $orderReference = (string) ($this->record->sap_doc_num ?: $this->record->sap_doc_entry ?: '');
        $orderError = $this->resolveOrderStepError($orderReference);
        $deliveryError = $this->resolveStepError(
            primaryError: (string) ($this->record->sap_delivery_error ?: ''),
            fallbackError: (string) ($this->record->sap_error ?: ''),
            keywords: ['delivery', 'delivery note'],
        );

        return [
            $this->makeStep(
                key: 'order',
                title: 'Create SAP Order / AR Reserve Invoice',
                status: $this->resolveOrderStepStatus($orderReference, $orderError),
                reference: $orderReference,
                error: $orderError
            ),
            $this->makeStep(
                key: 'payment',
                title: 'Create Incoming Payment',
                status: (string) ($this->record->sap_payment_status ?: ''),
                reference: (string) ($this->record->sap_payment_doc_num ?: $this->record->sap_payment_doc_entry ?: ''),
                error: (string) ($this->record->sap_payment_error ?: '')
            ),
            $this->makeStep(
                key: 'card_fee',
                title: 'Create Card Fee Journal Entry',
                status: (string) ($this->record->sap_card_fee_status ?: ''),
                reference: (string) ($this->record->sap_card_fee_journal_num ?: $this->record->sap_card_fee_journal_entry ?: ''),
                error: (string) ($this->record->sap_card_fee_error ?: '')
            ),
            $this->makeStep(
                key: 'delivery',
                title: 'Create Delivery Note',
                status: $this->resolveDeliveryStepStatus($deliveryError),
                reference: (string) ($this->record->sap_delivery_doc_num ?: $this->record->sap_delivery_doc_entry ?: ''),
                error: $deliveryError
            ),
            $this->makeStep(
                key: 'cogs',
                title: 'Create COGS Journal Entry',
                status: (string) ($this->record->sap_cogs_status ?: ''),
                reference: (string) ($this->record->sap_cogs_journal_num ?: $this->record->sap_cogs_journal_entry ?: ''),
                error: (string) ($this->record->sap_cogs_error ?: '')
            ),
            $this->makeStep(
                key: 'credit_note',
                title: 'Create Credit Note',
                status: (string) ($this->record->sap_credit_note_status ?: ''),
                reference: (string) ($this->record->sap_credit_note_doc_num ?: $this->record->sap_credit_note_doc_entry ?: ''),
                error: (string) ($this->record->sap_credit_note_error ?: '')
            ),
            $this->makeStep(
                key: 'cancel_cogs',
                title: 'Create Cancel COGS Reversal',
                status: (string) ($this->record->sap_cancel_cogs_status ?: ''),
                reference: (string) ($this->record->sap_cancel_cogs_journal_num ?: $this->record->sap_cancel_cogs_journal_entry ?: ''),
                error: (string) ($this->record->sap_cancel_cogs_error ?: '')
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
            'error' => trim($error) !== '' ? $error : null,
            'tone' => $this->resolveStepTone($normalizedStatus),
        ];
    }

    private function resolveStepTone(string $status): string
    {
        return match ($status) {
            'created', 'updated', 'logged', 'created_mixed', 'received_logged' => 'success',
            'failed' => 'danger',
            'ignored', 'blocked', 'pending', 'retrying' => 'warning',
            'not_started' => 'gray',
            default => 'gray',
        };
    }

    private function buildDebugPayloads(): array
    {
        $items = Collection::make($this->items)
            ->map(fn (array $item) => [
                'sku_code' => data_get($item, 'sku_code'),
                'quantity' => data_get($item, 'quantity'),
                'unit_price' => data_get($item, 'unit_price'),
                'total' => data_get($item, 'total'),
                'tax_percent' => data_get($item, 'tax_percent'),
            ])
            ->values()
            ->all();

        return [
            [
                'key' => 'order',
                'title' => 'Order Create Payload',
                'payload' => [
                    'external_id' => $this->record->external_id,
                    'event_name' => data_get($this->payload, 'event_name'),
                    'status_code' => data_get($this->data, 'status_code'),
                    'document_date' => data_get($this->data, 'order_created_at', data_get($this->data, 'created_at')),
                    'hub_code' => data_get($this->data, 'hub_code'),
                    'customer' => [
                        'email' => data_get($this->data, 'customer.email'),
                        'mobile' => data_get($this->data, 'customer.mobile'),
                    ],
                    'invoice' => [
                        'currency' => data_get($this->data, 'invoice.currency'),
                        'total' => data_get($this->data, 'invoice.total'),
                        'total_paid' => data_get($this->data, 'invoice.total_paid'),
                        'payment_mode' => data_get($this->data, 'invoice.payment_mode'),
                    ],
                    'items' => $items,
                ],
            ],
            [
                'key' => 'payment',
                'title' => 'Incoming Payment Payload',
                'payload' => [
                    'invoice_doc_entry' => $this->record->sap_doc_entry,
                    'reference' => $this->record->external_id,
                    'payment_method' => data_get($this->data, 'payment_method', data_get($this->data, 'invoice.payment_mode')),
                    'amount' => data_get($this->data, 'invoice.total_paid', data_get($this->data, 'invoice.total')),
                    'transfer_date' => data_get($this->data, 'order_created_at', data_get($this->data, 'created_at')),
                ],
            ],
            [
                'key' => 'delivery',
                'title' => 'Delivery Payload',
                'payload' => [
                    'order_doc_entry' => $this->record->sap_doc_entry,
                    'external_id' => $this->record->external_id,
                    'hub_code' => data_get($this->data, 'hub_code'),
                    'shipment' => [
                        'awb_number' => data_get($this->data, 'shipment.awb_number'),
                        'shipping_partner_status' => data_get($this->data, 'shipment.shipping_partner_status'),
                        'shipping_partner_name' => data_get($this->data, 'shipment.shipping_partner_name'),
                    ],
                    'items' => $items,
                ],
            ],
            [
                'key' => 'card_fee',
                'title' => 'Card Fee Journal Payload',
                'payload' => [
                    'reference' => $this->record->external_id,
                    'posting_date' => data_get($this->data, 'order_created_at', data_get($this->data, 'created_at')),
                    'payment_method' => data_get($this->data, 'invoice.payment_mode', data_get($this->data, 'payment_method')),
                    'invoice_total' => data_get($this->data, 'invoice.total'),
                ],
            ],
            [
                'key' => 'cogs',
                'title' => 'COGS Journal Payload',
                'payload' => [
                    'delivery_doc_entry' => $this->record->sap_delivery_doc_entry,
                    'reference' => $this->record->external_id,
                ],
            ],
            [
                'key' => 'credit_note',
                'title' => 'Credit Note Payload',
                'payload' => [
                    'external_id' => $this->record->external_id,
                    'base_delivery_doc_entry' => $this->record->sap_delivery_doc_entry,
                    'base_order_doc_entry' => $this->record->sap_doc_entry,
                    'document_date' => data_get($this->data, 'order_created_at', data_get($this->data, 'created_at')),
                    'items' => $items,
                ],
            ],
            [
                'key' => 'cancel_cogs',
                'title' => 'Cancel COGS Reversal Payload',
                'payload' => [
                    'credit_memo_doc_entry' => $this->record->sap_credit_note_doc_entry,
                    'reference' => $this->record->external_id,
                ],
            ],
        ];
    }

    private function resolveOrderStepStatus(string $reference, ?string $error): string
    {
        if ($reference !== '') {
            return 'created';
        }

        if ($error !== null) {
            return 'failed';
        }

        return (string) ($this->record->sap_status ?: 'not_started');
    }

    private function resolveDeliveryStepStatus(?string $error): string
    {
        $existingStatus = trim((string) ($this->record->sap_delivery_status ?: ''));

        if ($existingStatus !== '') {
            return $existingStatus;
        }

        if ((string) ($this->record->sap_delivery_doc_num ?: $this->record->sap_delivery_doc_entry ?: '') !== '') {
            return 'created';
        }

        if ($error !== null) {
            return 'failed';
        }

        return 'not_started';
    }

    private function resolveOrderStepError(string $reference): ?string
    {
        $generalError = trim((string) ($this->record->sap_error ?: ''));

        if ($generalError === '') {
            return null;
        }

        if ($reference !== '' && $this->isDownstreamError($generalError)) {
            return null;
        }

        return $generalError;
    }

    /**
     * @param array<int,string> $keywords
     */
    private function resolveStepError(string $primaryError, string $fallbackError, array $keywords): ?string
    {
        $primaryError = trim($primaryError);
        if ($primaryError !== '') {
            return $primaryError;
        }

        $fallbackError = trim($fallbackError);
        if ($fallbackError === '') {
            return null;
        }

        $normalized = Str::lower($fallbackError);
        foreach ($keywords as $keyword) {
            if (Str::contains($normalized, Str::lower($keyword))) {
                return $fallbackError;
            }
        }

        return null;
    }

    private function isDownstreamError(string $error): bool
    {
        $normalized = Str::lower($error);

        return Str::contains($normalized, [
            'incoming payment',
            'card fee',
            'delivery',
            'delivery note',
            'cogs',
            'credit note',
            'cancel cogs',
        ]);
    }
}
