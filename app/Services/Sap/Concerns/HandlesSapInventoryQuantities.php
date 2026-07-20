<?php

namespace App\Services\Sap\Concerns;

/**
 * Reads on-hand stock quantities from SAP for the SAP -> Omniful Inventory
 * Quantity Push. Reports InStock (OnHand), Committed and Available
 * (= InStock - Committed) per item x warehouse, for items already integrated in
 * Omniful (a SKU exists there) and only warehouses that actually carry stock.
 *
 * Stock is read through a SAP HANA **SQLQuery** on OITW (item-warehouse) joined
 * to OITM, NOT through the Items entity: this SAP Service Layer rejects $expand
 * and nested/path $select on ItemWarehouseInfoCollection, and a plain item fetch
 * returns the full collection for ~4.5k items x ~250 warehouses (~3GB, times
 * out). The SQLQuery returns just ItemCode/WhsCode/OnHand/IsCommited, filtered to
 * integrated items with non-zero stock — a few MB, paginated. Create it once:
 *
 *   POST /SQLQueries {"SqlCode":"OMNIFUL_QtyPush","SqlName":"...","SqlText":
 *     "SELECT T0.\"ItemCode\", T0.\"WhsCode\", T0.\"OnHand\", T0.\"IsCommited\"
 *      FROM \"OITW\" T0 INNER JOIN \"OITM\" T1 ON T1.\"ItemCode\" = T0.\"ItemCode\"
 *      WHERE T1.\"U_omInt\" = 'Y' AND (T0.\"OnHand\" <> 0 OR T0.\"IsCommited\" <> 0)"}
 *
 * READ-ONLY. Never mutates SAP.
 */
trait HandlesSapInventoryQuantities
{
    /**
     * @param array<int,string>|null $warehouseCodes Restrict to these SAP
     *        warehouse codes (null = every warehouse the query returns).
     * @return array<int,array{item_code:string,warehouse_code:string,in_stock:float,committed:float,available:float}>
     */
    public function fetchSyncedItemQuantities(?array $warehouseCodes = null): array
    {
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

        // SqlCode is sanitised because it is interpolated into the OData path.
        $sqlCode = (string) config('omniful.inventory_push.sql_query_code', 'OMNIFUL_QtyPush');
        $sqlCode = preg_replace('/[^A-Za-z0-9_]/', '', $sqlCode) ?: 'OMNIFUL_QtyPush';

        $rows = $this->fetchAll("/SQLQueries('{$sqlCode}')/List");

        $out = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $itemCode = trim((string) ($row['ItemCode'] ?? ''));
            $warehouseCode = trim((string) ($row['WhsCode'] ?? ''));
            if ($itemCode === '' || $warehouseCode === '') {
                continue;
            }
            if ($allowed !== null && !isset($allowed[$warehouseCode])) {
                continue;
            }

            $inStock = (float) ($row['OnHand'] ?? 0);
            $committed = (float) ($row['IsCommited'] ?? 0);

            $out[] = [
                'item_code' => $itemCode,
                'warehouse_code' => $warehouseCode,
                'in_stock' => $inStock,
                'committed' => $committed,
                'available' => $inStock - $committed,
            ];
        }

        return $out;
    }
}
