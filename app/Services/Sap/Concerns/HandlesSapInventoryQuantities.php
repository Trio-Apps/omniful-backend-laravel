<?php

namespace App\Services\Sap\Concerns;

/**
 * Reads on-hand stock quantities from SAP for the SAP -> Omniful Inventory
 * Quantity Push. Pulls every item already integrated in Omniful (the flag SAP
 * sets after the SKU is created — config omniful.item_integration
 * .integrated_udf_field = integrated_value, e.g. U_omInt = 'Y') together with
 * its per-warehouse figures, and reports InStock, Committed and Available
 * (= InStock - Committed) per item x warehouse. SAP-only items (no Omniful SKU)
 * are excluded, so the push never fails on "SKU not found".
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
        // Only items ACTUALLY integrated in Omniful (a SKU exists there), using
        // the flag SAP sets after the SKU is created (U_omInt = 'Y'). This
        // deliberately excludes SAP-only items that have no Omniful SKU, so the
        // push never generates "SKU not found" failures for them. Field/value are
        // sanitised because they are interpolated into the OData path.
        $field = (string) config('omniful.item_integration.integrated_udf_field', 'U_omInt');
        $field = preg_replace('/[^A-Za-z0-9_]/', '', $field) ?: 'U_omInt';
        $value = str_replace("'", "''", (string) config('omniful.item_integration.integrated_value', 'Y'));

        $filter = "{$field} eq '{$value}'";

        // This SAP Service Layer rejects $expand on ItemWarehouseInfoCollection
        // (HTTP 400 code 201 "Cannot expand invalid navigation property") even
        // though the collection IS accessible — the plain item list already
        // returns it INLINE. So read it WITHOUT $expand/$select first; the
        // (lighter) nested-select expand shapes stay as fallbacks for SAP
        // companies that do support them. fetchAllWithFallback swallows a
        // 404/invalid-property and moves to the next shape.
        $rows = $this->fetchAllWithFallback([
            // $select=ItemCode,ItemWarehouseInfoCollection returns the warehouse
            // collection inline while EXCLUDING the item's other heavy child
            // collections (prices, vendors, …) — a plain "/Items?$filter" pulls
            // them all and times out. The nested-select expand shapes stay as
            // fallbacks for SAP companies that support $expand on the collection.
            "/Items?\$select=ItemCode,ItemWarehouseInfoCollection&\$filter={$filter}",
            "/Items?\$select=ItemCode,{$field}&\$expand=ItemWarehouseInfoCollection(\$select=WarehouseCode,InStock,Committed)&\$filter={$filter}",
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
