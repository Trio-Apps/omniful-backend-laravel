<?php

namespace App\Http\Controllers\Webhooks;

use App\Models\OmnifulReturnOrderEvent;
use App\Services\SapServiceLayerClient;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class OmnifulReturnOrderWebhookController extends OmnifulWebhookBase
{
    public function __invoke(Request $request)
    {
        $result = $this->storeEvent($request, 'return-order', OmnifulReturnOrderEvent::class, true);

        if (isset($result['response'])) {
            return $result['response'];
        }

        /** @var OmnifulReturnOrderEvent $event */
        $event = $result['event'];

        try {
            $this->processSapReturnOrder($event);
        } catch (\Throwable $e) {
            $event->sap_status = 'failed';
            $event->sap_error = $e->getMessage();
            $event->save();

            Log::error('SAP return order sync failed', [
                'event_id' => $event->id,
                'external_id' => $event->external_id,
                'error' => $e->getMessage(),
            ]);
        }

        return response()->json(['status' => 'ok', 'id' => $event->id]);
    }

    private function processSapReturnOrder(OmnifulReturnOrderEvent $event): void
    {
        $payload = $event->payload ?? [];
        $data = data_get($payload, 'data', []);
        $eventName = (string) data_get($payload, 'event_name', '');
        $status = (string) data_get($data, 'status', '');

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

            $hubCode = data_get($data, 'hub_code');
            $remarks = $this->buildReturnOrderRemarks($data, $eventName, $status);

            $client = app(SapServiceLayerClient::class);
            $client->syncInventoryItems($items);
            $result = $client->createInventoryGoodsReceipt($items, $hubCode, $remarks);

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
}
