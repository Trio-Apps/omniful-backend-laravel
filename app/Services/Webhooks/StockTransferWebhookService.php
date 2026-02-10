<?php

namespace App\Services\Webhooks;

use App\Models\OmnifulInventoryEvent;
use App\Services\SapServiceLayerClient;

class StockTransferWebhookService
{
    public function process(OmnifulInventoryEvent $event): void
    {
        $payload = (array) ($event->payload ?? []);
        $data = (array) data_get($payload, 'data', []);

        $fromWarehouse = $this->extractFromWarehouse($data, $payload);
        $toWarehouse = $this->extractToWarehouse($data, $payload);

        if ($fromWarehouse === '' || $toWarehouse === '') {
            $event->sap_status = 'ignored';
            $event->sap_error = 'Ignored: missing source or destination warehouse';
            $event->save();
            return;
        }

        if ($fromWarehouse === $toWarehouse) {
            $event->sap_status = 'ignored';
            $event->sap_error = 'Ignored: source and destination warehouse are identical';
            $event->save();
            return;
        }

        $lines = $this->extractTransferLines($data, $payload);
        if ($lines === []) {
            $event->sap_status = 'ignored';
            $event->sap_error = 'Ignored: no stock transfer lines found';
            $event->save();
            return;
        }

        $client = app(SapServiceLayerClient::class);
        $client->syncInventoryItems($lines);

        $eventName = (string) data_get($payload, 'event_name', 'stock_transfer');
        $result = $client->createStockTransfer(
            $lines,
            $fromWarehouse,
            $toWarehouse,
            trim('Omniful stock transfer | ' . $eventName . ' | ' . (string) ($event->external_id ?? ''))
        );

        if (($result['ignored'] ?? false) === true) {
            $event->sap_status = 'ignored';
            $event->sap_error = (string) ($result['reason'] ?? 'Stock transfer ignored');
            $event->save();
            return;
        }

        $event->sap_status = 'created';
        $event->sap_doc_entry = (string) ($result['DocEntry'] ?? '');
        $event->sap_doc_num = (string) ($result['DocNum'] ?? '');
        $event->sap_error = null;
        $event->save();
    }

    private function extractFromWarehouse(array $data, array $payload): string
    {
        $candidates = [
            data_get($data, 'from_hub_code'),
            data_get($data, 'source_hub_code'),
            data_get($data, 'source_warehouse_code'),
            data_get($data, 'from_warehouse_code'),
            data_get($data, 'origin_hub_code'),
            data_get($payload, 'from_hub_code'),
            data_get($payload, 'source_hub_code'),
        ];

        foreach ($candidates as $value) {
            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
            if (is_numeric($value)) {
                return (string) $value;
            }
        }

        return '';
    }

    private function extractToWarehouse(array $data, array $payload): string
    {
        $candidates = [
            data_get($data, 'to_hub_code'),
            data_get($data, 'destination_hub_code'),
            data_get($data, 'destination_warehouse_code'),
            data_get($data, 'to_warehouse_code'),
            data_get($payload, 'to_hub_code'),
            data_get($payload, 'destination_hub_code'),
        ];

        foreach ($candidates as $value) {
            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
            if (is_numeric($value)) {
                return (string) $value;
            }
        }

        return '';
    }

    /**
     * @return array<int,array{seller_sku_code:string,quantity:float}>
     */
    private function extractTransferLines(array $data, array $payload): array
    {
        $sources = [
            data_get($data, 'stock_transfer_items', []),
            data_get($data, 'transfer_items', []),
            data_get($data, 'items', []),
            data_get($payload, 'stock_transfer_items', []),
            data_get($payload, 'items', []),
        ];

        foreach ($sources as $source) {
            $lines = [];
            foreach ((array) $source as $item) {
                $itemCode = data_get($item, 'seller_sku_code')
                    ?? data_get($item, 'sku_code')
                    ?? data_get($item, 'item_code')
                    ?? data_get($item, 'seller_sku.seller_sku_code')
                    ?? data_get($item, 'seller_sku_id');
                if (!$itemCode) {
                    continue;
                }

                $qty = data_get($item, 'transfer_quantity');
                if ($qty === null) {
                    $qty = data_get($item, 'requested_quantity');
                }
                if ($qty === null) {
                    $qty = data_get($item, 'quantity');
                }

                $qty = (float) ($qty ?? 0);
                if ($qty <= 0) {
                    continue;
                }

                $lines[] = [
                    'seller_sku_code' => (string) $itemCode,
                    'quantity' => $qty,
                ];
            }

            if ($lines !== []) {
                return $lines;
            }
        }

        return [];
    }
}

