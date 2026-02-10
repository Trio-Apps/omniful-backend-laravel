<?php

namespace App\Services\Webhooks;

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
        $status = (string) data_get($data, 'status', '');
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
                $event->save();
                return;
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
            $result = $client->createArCreditMemoFromReturnOrder($data, [
                'external_id' => (string) ($event->external_id ?? ''),
                'base_delivery_doc_entry' => (int) ($order->sap_delivery_doc_entry ?? 0),
                'base_order_doc_entry' => (int) ($order->sap_doc_entry ?? 0),
            ]);

            if (($result['ignored'] ?? false) === true) {
                $event->sap_status = 'ignored';
                $event->sap_error = (string) ($result['reason'] ?? 'Ignored: return cannot be converted to AR credit memo');
                $event->save();
                return;
            }

            $event->sap_status = 'created';
            $event->sap_doc_entry = $result['DocEntry'] ?? null;
            $event->sap_doc_num = $result['DocNum'] ?? null;
            $event->sap_error = null;
            $event->save();
        } else {
            $event->sap_status = $event->sap_status ?: 'logged';
            $event->save();
        }
    }

    private function buildReturnOrderItems(array $data): array
    {
        $items = data_get($data, 'order_items', []);
        $lines = [];

        foreach ((array) $items as $item) {
            $itemCode = data_get($item, 'seller_sku.seller_sku_code')
                ?? data_get($item, 'seller_sku.seller_sku_id')
                ?? data_get($item, 'seller_sku_code')
                ?? data_get($item, 'sku_code');

            if (!$itemCode) {
                continue;
            }

            $qty = data_get($item, 'return_quantity');
            if ($qty === null) {
                $qty = data_get($item, 'returned_quantity');
            }
            if ($qty === null) {
                $qty = data_get($item, 'delivered_quantity');
            }

            $qty = (float) ($qty ?? 0);
            if ($qty <= 0) {
                continue;
            }

            $lines[] = [
                'seller_sku_code' => $itemCode,
                'quantity' => $qty,
            ];
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
            data_get($data, 'reference_id'),
            data_get($payload, 'order_reference_id'),
            data_get($payload, 'order_id'),
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
}
