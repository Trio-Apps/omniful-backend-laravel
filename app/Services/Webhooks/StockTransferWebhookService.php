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
        $remarks = trim('Omniful stock transfer | ' . $eventName . ' | ' . (string) ($event->external_id ?? ''));
        $inTransitWarehouse = $this->extractInTransitWarehouse($data, $payload);
        $useInTransit = $this->shouldUseInTransit($data, $payload, $inTransitWarehouse);

        if ($useInTransit) {
            $result = $client->createStockTransferViaTransit(
                $lines,
                $fromWarehouse,
                $toWarehouse,
                $inTransitWarehouse,
                $remarks
            );
        } else {
            $result = $client->createStockTransfer(
                $lines,
                $fromWarehouse,
                $toWarehouse,
                $remarks
            );
        }

        if (($result['ignored'] ?? false) === true) {
            $event->sap_status = 'ignored';
            $event->sap_error = (string) ($result['reason'] ?? 'Stock transfer ignored');
            $event->save();
            return;
        }

        $event->sap_status = (($result['mode'] ?? '') === 'two_step_in_transit') ? 'created_via_transit' : 'created';
        $event->sap_doc_entry = (string) ($result['DocEntry'] ?? '');
        $event->sap_doc_num = (string) ($result['DocNum'] ?? '');
        if (($result['mode'] ?? '') === 'two_step_in_transit') {
            $event->sap_error = json_encode([
                'mode' => 'two_step_in_transit',
                'leg1' => $result['leg1'] ?? null,
                'leg2' => $result['leg2'] ?? null,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        } else {
            $event->sap_error = null;
        }
        $event->save();
    }

    private function extractFromWarehouse(array $data, array $payload): string
    {
        $candidates = [
            data_get($data, 'from_hub_code'),
            data_get($data, 'source_hub_code'),
            data_get($data, 'source_hub.code'),
            data_get($data, 'source_warehouse_code'),
            data_get($data, 'from_warehouse_code'),
            data_get($data, 'origin_hub_code'),
            data_get($payload, 'from_hub_code'),
            data_get($payload, 'source_hub_code'),
            data_get($payload, 'source_hub.code'),
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
            data_get($data, 'destination_hub.code'),
            data_get($data, 'destination_warehouse_code'),
            data_get($data, 'to_warehouse_code'),
            data_get($payload, 'to_hub_code'),
            data_get($payload, 'destination_hub_code'),
            data_get($payload, 'destination_hub.code'),
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
            data_get($data, 'order_items', []),
            data_get($data, 'items', []),
            data_get($payload, 'stock_transfer_items', []),
            data_get($payload, 'order_items', []),
            data_get($payload, 'items', []),
        ];

        foreach ($sources as $source) {
            $lines = [];
            foreach ((array) $source as $item) {
                $itemCode = $this->extractTransferItemCode((array) $item);
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

    private function extractTransferItemCode(array $item): string
    {
        $candidates = [
            data_get($item, 'seller_sku_code'),
            data_get($item, 'sku_code'),
            data_get($item, 'item_code'),
            data_get($item, 'seller_sku.seller_sku_code'),
            data_get($item, 'seller_sku.seller_sku_id'),
            data_get($item, 'sku.seller_sku_code'),
            data_get($item, 'sku.seller_sku_id'),
            data_get($item, 'seller_sku_id'),
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

    private function extractInTransitWarehouse(array $data, array $payload): string
    {
        $candidates = [
            data_get($data, 'in_transit_hub_code'),
            data_get($data, 'transit_hub_code'),
            data_get($data, 'in_transit_warehouse_code'),
            data_get($payload, 'in_transit_hub_code'),
            data_get($payload, 'transit_hub_code'),
            config('omniful.stock_transfer.in_transit_warehouse', ''),
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

    private function shouldUseInTransit(array $data, array $payload, string $inTransitWarehouse): bool
    {
        if (!(bool) config('omniful.stock_transfer.in_transit_enabled', false)) {
            return false;
        }

        if ($inTransitWarehouse === '') {
            return false;
        }

        if ((bool) config('omniful.stock_transfer.force_in_transit', false)) {
            return true;
        }

        $flags = [
            data_get($data, 'use_in_transit'),
            data_get($data, 'via_in_transit'),
            data_get($payload, 'use_in_transit'),
            data_get($payload, 'via_in_transit'),
        ];

        foreach ($flags as $flag) {
            if (is_bool($flag) && $flag === true) {
                return true;
            }
            if (is_string($flag) && in_array(strtolower(trim($flag)), ['1', 'true', 'yes', 'y'], true)) {
                return true;
            }
            if (is_numeric($flag) && (int) $flag === 1) {
                return true;
            }
        }

        $transferType = strtolower(trim((string) (data_get($data, 'transfer_type') ?? data_get($payload, 'transfer_type') ?? '')));
        return in_array($transferType, ['main_to_branch_via_transit', 'branch_to_branch_via_transit', 'via_transit'], true);
    }
}
