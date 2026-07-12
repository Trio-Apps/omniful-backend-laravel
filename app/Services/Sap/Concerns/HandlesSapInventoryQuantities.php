<?php

namespace App\Services\Sap\Concerns;

/**
 * Reads on-hand stock quantities from SAP for the SAP -> Omniful Inventory
 * Quantity Push. Pulls every item flagged for the Omniful integration (config
 * omniful.item_integration.item_udf_field, e.g. U_OmnifulSync) together with its
 * per-warehouse figures, and reports InStock, Committed and Available
 * (= InStock - Committed) per item x warehouse.
 *
 * READ-ONLY. This never mutates SAP and is entirely independent of the
 * warehouse master-data sync — it only needs the HTTP + pagination helpers.
 */
trait HandlesSapInventoryQuantities
{
    /**
     * @param array<int,string>|null $warehouseCodes Restrict to these SAP
     *        warehouse codes (null = every warehouse the items carry).
     * @return array<int,array{item_code:string,warehouse_code:string,in_stock:float,committed:float,available:float}>
     */
    public function fetchSyncedItemQuantities(?array $warehouseCodes = null): array
    {
        // Field that flags an item as part of the Omniful integration scope.
        // Sanitised because it is interpolated into the OData path.
        $udf = (string) config('omniful.item_integration.item_udf_field', 'U_OmnifulSync');
        $udf = preg_replace('/[^A-Za-z0-9_]/', '', $udf) ?: 'U_OmnifulSync';

        $filter = "{$udf} ne null and {$udf} ne ''";

        // Prefer a narrow nested $select on the expanded warehouse collection;
        // fall back to broader shapes when a SAP company rejects the nested
        // $select or $expand (fetchAllWithFallback swallows 404/invalid-property).
        $rows = $this->fetchAllWithFallback([
            "/Items?\$select=ItemCode,{$udf}&\$expand=ItemWarehouseInfoCollection(\$select=WarehouseCode,InStock,Committed)&\$filter={$filter}",
            "/Items?\$select=ItemCode,{$udf}&\$expand=ItemWarehouseInfoCollection&\$filter={$filter}",
            "/Items?\$expand=ItemWarehouseInfoCollection&\$filter={$filter}",
        ]);

        $allowed = null;
        if (is_array($warehouseCodes)) {
            $allowed = [];
            foreach ($warehouseCodes as $code) {
                $code = trim((string) $code);
                if ($code !== '') {
                    $allowed[$code] = true;
                }
            }
        }

        $out = [];
        foreach ($rows as $item) {
            if (!is_array($item)) {
                continue;
            }
            $itemCode = trim((string) ($item['ItemCode'] ?? ''));
            if ($itemCode === '') {
                continue;
            }

            foreach ((array) ($item['ItemWarehouseInfoCollection'] ?? []) as $whs) {
                if (!is_array($whs)) {
                    continue;
                }
                $warehouseCode = trim((string) ($whs['WarehouseCode'] ?? ''));
                if ($warehouseCode === '') {
                    continue;
                }
                if ($allowed !== null && !isset($allowed[$warehouseCode])) {
                    continue;
                }

                $inStock = (float) ($whs['InStock'] ?? 0);
                $committed = (float) ($whs['Committed'] ?? 0);

                $out[] = [
                    'item_code' => $itemCode,
                    'warehouse_code' => $warehouseCode,
                    'in_stock' => $inStock,
                    'committed' => $committed,
                    'available' => $inStock - $committed,
                ];
            }
        }

        return $out;
    }
}
