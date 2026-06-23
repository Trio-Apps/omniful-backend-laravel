<?php

namespace App\Services\Webhooks;

use App\Exceptions\SapRequestException;
use App\Models\IntegrationSetting;
use App\Models\OmnifulOrder;
use App\Models\OmnifulReturnOrderEvent;
use App\Services\SapServiceLayerClient;

class ReturnOrderWebhookService
{
    public function process(OmnifulReturnOrderEvent $event): void
    {
        $mapper = app(WebhookStatusMapper::class);
        $payload = $event->payload ?? [];
        $data = data_get($payload, 'data', []);
        $eventName = (string) data_get($payload, 'event_name', '');
        $status = $this->extractReturnStatus((array) $data);
        $validation = $mapper->validateReturnOrder($eventName, $status);

        if (!($validation['allowed'] ?? false)) {
            $event->sap_status = 'ignored';
            $event->sap_error = (string) ($validation['reason'] ?? 'Ignored: return-order status/event not allowed by mapping');
            $event->save();
            return;
        }

        $returnOrderId = data_get($data, 'return_order_id')
            ?? data_get($data, 'id')
            ?? data_get($payload, 'return_order_id')
            ?? data_get($payload, 'id');

        if ($returnOrderId && $event->external_id !== $returnOrderId) {
            $event->external_id = (string) $returnOrderId;
            $event->save();
        }

        if ($event->external_id) {
            $existing = OmnifulReturnOrderEvent::where('external_id', $event->external_id)
                ->where('id', '!=', $event->id)
                ->whereNotNull('sap_doc_entry')
                ->first();
            if ($existing) {
                $event->sap_status = 'skipped';
                $event->sap_doc_entry = $existing->sap_doc_entry;
                $event->sap_doc_num = $existing->sap_doc_num;
                $event->sap_error = $existing->sap_error;
                $event->sap_response = $existing->sap_response;
                $event->sap_cogs_reversal_status = $existing->sap_cogs_reversal_status;
                $event->sap_cogs_reversal_journal_entry = $existing->sap_cogs_reversal_journal_entry;
                $event->sap_cogs_reversal_journal_num = $existing->sap_cogs_reversal_journal_num;
                $event->sap_cogs_reversal_error = $existing->sap_cogs_reversal_error;
                $event->sap_cogs_reversal_response = $existing->sap_cogs_reversal_response;
                $event->save();
            }
        }

        if (!$event->sap_doc_entry) {
            $items = $this->buildReturnOrderItems($data);
            if ($items === []) {
                $event->sap_status = 'ignored';
                $event->sap_error = 'Ignored: no return items found';
                $event->save();
                return;
            }

            $orderReferenceId = $this->extractOrderReferenceId($data, $payload);
            $order = $orderReferenceId
                ? OmnifulOrder::where('external_id', (string) $orderReferenceId)->first()
                : null;

            $client = app(SapServiceLayerClient::class);
            try {
                $result = $client->createArCreditMemoFromReturnOrder($data, [
                    'external_id' => (string) ($event->external_id ?? ''),
                    'base_delivery_doc_entry' => (int) ($order->sap_delivery_doc_entry ?? 0),
                    'base_order_doc_entry' => (int) ($order->sap_doc_entry ?? 0),
                    'parsed_items' => $items,
                ]);
            } catch (SapRequestException $e) {
                $event->sap_status = 'failed';
                $event->sap_error = $e->getMessage();
                $event->sap_response = [
                    'request_body' => $e->requestBody,
                    'error_response_body' => $e->responseBody,
                    'status_code' => $e->statusCode,
                ];
                $event->save();

                throw $e;
            }

            if (($result['ignored'] ?? false) === true) {
                $event->sap_status = 'ignored';
                $event->sap_error = (string) ($result['reason'] ?? 'Ignored: return cannot be converted to AR credit memo');
                $event->sap_response = $result;
                $event->save();
                return;
            }

            $event->sap_status = 'created';
            $event->sap_doc_entry = $result['DocEntry'] ?? null;
            $event->sap_doc_num = $result['DocNum'] ?? null;
            $event->sap_error = null;
            $event->sap_response = $result;
            $event->save();
        } else {
            $event->sap_status = $event->sap_status ?: 'logged';
            $event->save();
        }

        $this->createReturnCogsReversalIfEligible($event);
    }

    private function buildReturnOrderItems(array $data): array
    {
        $items = data_get($data, 'order_items', data_get($data, 'return_items', []));
        $lines = [];
        $totals = [];

        foreach ((array) $items as $item) {
            $itemCode = data_get($item, 'seller_sku.seller_sku_code')
                ?? data_get($item, 'seller_sku.seller_sku_id')
                ?? data_get($item, 'seller_sku_code')
                ?? data_get($item, 'sku_code')
                ?? data_get($item, 'code');

            if (!$itemCode) {
                continue;
            }

            $qty = data_get($item, 'return_quantity');
            if ($qty === null) {
                $qty = data_get($item, 'returned_quantity');
            }
            if ($qty === null) {
                $qty = data_get($item, 'refunded_quantity');
            }
            if ($qty === null) {
                $qty = data_get($item, 'delivered_quantity');
            }

            $qty = (float) ($qty ?? 0);
            if ($qty <= 0) {
                continue;
            }

            if (!isset($totals[$itemCode])) {
                $totals[$itemCode] = [
                    'item_code' => (string) $itemCode,
                    'quantity' => 0.0,
                    'unit_price' => 0.0,
                    'tax_percent' => null,
                ];
            }

            $totals[$itemCode]['quantity'] += $qty;

            $unitPrice = data_get($item, 'unit_price');
            if ($unitPrice === null) {
                $unitPrice = data_get($item, 'price');
            }
            if ($unitPrice === null) {
                $unitPrice = data_get($item, 'selling_price');
            }
            if ($unitPrice === null) {
                $unitPrice = data_get($item, 'display_price');
            }

            if (is_numeric($unitPrice) && (float) $unitPrice > 0 && (float) $totals[$itemCode]['unit_price'] <= 0) {
                $totals[$itemCode]['unit_price'] = (float) $unitPrice;
            }

            $taxPercent = data_get($item, 'tax_percent');
            if (is_numeric($taxPercent)) {
                $totals[$itemCode]['tax_percent'] = (float) $taxPercent;
            }
        }

        foreach ($totals as $line) {
            $lines[] = $line;
        }

        return $lines;
    }

    private function buildReturnOrderRemarks(array $data, string $eventName, string $status): string
    {
        $returnOrderId = data_get($data, 'return_order_id') ?? data_get($data, 'id');
        $referenceId = data_get($data, 'order_reference_id');

        $parts = ['Omniful Return Order'];
        if ($returnOrderId) {
            $parts[] = $returnOrderId;
        }
        if ($referenceId) {
            $parts[] = 'Ref ' . $referenceId;
        }
        if ($eventName) {
            $parts[] = $eventName;
        }
        if ($status) {
            $parts[] = $status;
        }

        return implode(' | ', $parts);
    }

    private function extractOrderReferenceId(array $data, array $payload): ?string
    {
        $candidates = [
            data_get($data, 'order_reference_id'),
            data_get($data, 'order_id'),
            data_get($data, 'omniful_order_id'),
            data_get($data, 'reference_id'),
            data_get($payload, 'order_reference_id'),
            data_get($payload, 'order_id'),
            data_get($payload, 'omniful_order_id'),
            data_get($payload, 'reference_id'),
        ];

        foreach ($candidates as $value) {
            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
            if (is_numeric($value)) {
                return (string) $value;
            }
        }

        return null;
    }

    private function extractReturnStatus(array $data): string
    {
        $candidates = [
            data_get($data, 'status'),
            data_get($data, 'status_code'),
            data_get($data, 'return_status'),
            data_get($data, 'refund_status'),
            data_get($data, 'shipment.status'),
        ];

        foreach ($candidates as $value) {
            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        return '';
    }

    /**
     * Whether COGS reversal on returns is enabled. Controlled by the Integration
     * Settings toggle (return_cogs_reversal_enabled); falls back to the env/config
     * flag only when the setting has never been stored.
     */
    private function returnCogsReversalEnabled(): bool
    {
        $value = IntegrationSetting::query()->first()?->return_cogs_reversal_enabled;
        if ($value !== null) {
            return (bool) $value;
        }

        return (bool) config('omniful.order_accounting.return_cogs_reversal_enabled', false);
    }

    /**
     * Resolve a COGS GL account: prefer the value configured on the Integration
     * Settings page (same accounts used by the order COGS journal), falling back
     * to the env/config value so existing env-based setups keep working.
     */
    private function resolveCogsAccount(string $settingField, string $configKey): string
    {
        $settings = IntegrationSetting::query()->first();
        $value = $settings?->{$settingField};
        if (is_string($value) && trim($value) !== '') {
            return trim($value);
        }

        return (string) config($configKey, '');
    }

    private function createReturnCogsReversalIfEligible(OmnifulReturnOrderEvent $event): void
    {
        if (!$this->returnCogsReversalEnabled()) {
            return;
        }

        // Already reversed once — bind it and stop. Service Layer JournalEntries
        // have no TransId (they key on JdtNum), so the journal NUMBER is the
        // reliable identifier; a re-post would hit "(1000) ... already
        // integrated". Treat a populated entry OR number as done, and clear any
        // stale failure left by a previous re-post. (Per-event field, so a
        // separate return event still creates its own reversal.)
        if (!empty($event->sap_cogs_reversal_journal_entry) || !empty($event->sap_cogs_reversal_journal_num)) {
            if ((string) $event->sap_cogs_reversal_status !== 'created') {
                $event->sap_cogs_reversal_status = 'created';
                $event->sap_cogs_reversal_error = null;
                $event->save();
            }
            return;
        }

        $creditMemoDocEntry = (int) ($event->sap_doc_entry ?? 0);
        if ($creditMemoDocEntry <= 0) {
            return;
        }

        $payload = (array) ($event->payload ?? []);
        $data = (array) data_get($payload, 'data', []);
        $orderReference = (string) ($this->extractOrderReferenceId($data, $payload) ?? '');

        $client = app(SapServiceLayerClient::class);
        try {
            $result = $client->createCogsReversalJournalForCreditMemo([
                'credit_memo_doc_entry' => $creditMemoDocEntry,
                'reference' => (string) ($event->external_id ?? ''),
                // Original order reference, used to look up the posted order COGS
                // when the credit-memo line has no stock cost (bundles/kits).
                'cogs_order_reference' => $orderReference,
                'memo' => 'COGS reversal from Omniful return ' . (string) ($event->external_id ?? ''),
                'expense_account' => $this->resolveCogsAccount('order_cogs_expense_account', 'omniful.order_accounting.cogs_expense_account'),
                'offset_account' => $this->resolveCogsAccount('order_cogs_inventory_offset_account', 'omniful.order_accounting.inventory_offset_account'),
            ]);
        } catch (SapRequestException $e) {
            $event->sap_cogs_reversal_status = 'failed';
            $event->sap_cogs_reversal_error = $e->getMessage();
            $event->sap_cogs_reversal_response = [
                'request_body' => $e->requestBody,
                'error_response_body' => $e->responseBody,
                'status_code' => $e->statusCode,
            ];
            $event->save();

            throw $e;
        }

        if (($result['ignored'] ?? false) === true) {
            $event->sap_cogs_reversal_status = 'ignored';
            $event->sap_cogs_reversal_error = (string) ($result['reason'] ?? 'COGS reversal ignored');
            $event->sap_cogs_reversal_response = $result;
            $event->save();
            return;
        }

        $event->sap_cogs_reversal_status = 'created';
        // JournalEntries have no TransId in the Service Layer — fall back to the
        // JdtNum so the entry field is populated and the guard above skips a
        // re-post on the next resend.
        $event->sap_cogs_reversal_journal_entry = (string) ($result['TransId'] ?? $result['JdtNum'] ?? '');
        $event->sap_cogs_reversal_journal_num = (string) ($result['Number'] ?? $result['JdtNum'] ?? '');
        $event->sap_cogs_reversal_error = null;
        $event->sap_cogs_reversal_response = $result;
        $event->save();
    }
}
