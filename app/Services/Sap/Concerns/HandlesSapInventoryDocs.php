<?php

namespace App\Services\Sap\Concerns;

trait HandlesSapInventoryDocs
{
    public function syncInventoryItems(array $items): void
    {
        foreach ($items as $item) {
            $itemCode = data_get($item, 'seller_sku_code')
                ?? data_get($item, 'sku_code')
                ?? data_get($item, 'seller_sku_id');
            if (!$itemCode) {
                continue;
            }

            $this->syncProductFromOmniful([
                'seller_sku_code' => $itemCode,
                'name' => $itemCode,
            ], 'inventory');
        }
    }


    public function createInventoryGoodsReceipt(array $items, ?string $hubCode, string $remarks): array
    {
        $lines = $this->buildInventoryLinesForInventoryDoc($items, $hubCode, false);

        if ($lines === []) {
            throw new \RuntimeException('No inventory lines found for Goods Receipt');
        }

        $docDate = now()->format('Y-m-d');
        $seriesInfo = $this->resolveSeriesForDocument('59', $docDate);
        $docDate = $seriesInfo['docDate'];

        $body = [
            'DocDate' => $docDate,
            'TaxDate' => $docDate,
            'Comments' => $remarks,
            'DocumentLines' => $lines,
        ];

        if ($seriesInfo['series']) {
            $body['Series'] = $seriesInfo['series'];
        }

        $response = $this->post('/InventoryGenEntries', $body);
        if (!$response->successful() && $this->shouldRetryWithoutSeries($response->body())) {
            unset($body['Series']);
            $response = $this->post('/InventoryGenEntries', $body);
        }

        if (!$response->successful()) {
            throw new \RuntimeException(
                'SAP Goods Receipt create failed: ' . $response->status() . ' ' . $response->body()
                . ' | Payload: ' . json_encode($body, JSON_UNESCAPED_UNICODE)
            );
        }

        return $response->json() ?? [];
    }


    public function createInventoryGoodsIssue(array $items, ?string $hubCode, string $remarks): array
    {
        $lines = $this->buildInventoryLinesForInventoryDoc($items, $hubCode, true);

        if ($lines === []) {
            throw new \RuntimeException('No inventory lines found for Goods Issue');
        }

        $docDate = now()->format('Y-m-d');
        $seriesInfo = $this->resolveSeriesForDocument('60', $docDate);
        $docDate = $seriesInfo['docDate'];

        $body = [
            'DocDate' => $docDate,
            'TaxDate' => $docDate,
            'Comments' => $remarks,
            'DocumentLines' => $lines,
        ];

        if ($seriesInfo['series']) {
            $body['Series'] = $seriesInfo['series'];
        }

        $response = $this->post('/InventoryGenExits', $body);
        if (!$response->successful() && $this->shouldRetryWithoutSeries($response->body())) {
            unset($body['Series']);
            $response = $this->post('/InventoryGenExits', $body);
        }

        if (!$response->successful()) {
            throw new \RuntimeException(
                'SAP Goods Issue create failed: ' . $response->status() . ' ' . $response->body()
                . ' | Payload: ' . json_encode($body, JSON_UNESCAPED_UNICODE)
            );
        }

        return $response->json() ?? [];
    }


    private function buildInventoryLinesForInventoryDoc(array $items, ?string $hubCode, bool $isIssue): array
    {
        $lines = [];
        $binAbsEntry = null;
        $binManaged = false;
        if ($hubCode) {
            $this->ensureWarehouseExists($hubCode, 1);
            $binManaged = $this->isWarehouseBinManaged($hubCode);
            if ($binManaged) {
                $binAbsEntry = $this->getFirstBinAbsEntry($hubCode);
                if ($binAbsEntry === null) {
                    throw new \RuntimeException('SAP bin-managed warehouse has no bins (Warehouse=' . $hubCode . ')');
                }
            }
        }

        foreach ($items as $item) {
            $itemCode = data_get($item, 'seller_sku_code')
                ?? data_get($item, 'sku_code')
                ?? data_get($item, 'seller_sku_id');
            $qty = (float) (data_get($item, 'quantity') ?? 0);
            if (!$itemCode || $qty <= 0) {
                continue;
            }

            $master = $this->getItemMaster($itemCode);
            $batchManaged = $this->isBatchManaged($master);
            $serialManaged = $this->isSerialManaged($master);
            if ($hubCode) {
                $this->ensureItemWarehouseExists($itemCode, $hubCode);
            }

            $line = [
                'ItemCode' => $itemCode,
                'Quantity' => $qty,
            ];
            if ($hubCode) {
                $line['WarehouseCode'] = $hubCode;
            }

            if ($batchManaged) {
                if ($isIssue) {
                    $line['BatchNumbers'] = $this->getBatchAllocations($itemCode, $hubCode, $qty);
                } else {
                    $line['BatchNumbers'] = [[
                        'BatchNumber' => $this->generateBatchNumber($itemCode),
                        'Quantity' => $qty,
                    ]];
                }
            }

            if ($serialManaged) {
                if ($qty !== floor($qty)) {
                    throw new \RuntimeException('Serial-managed item requires integer quantity (Item=' . $itemCode . ')');
                }
                $count = (int) $qty;
                if ($isIssue) {
                    $line['SerialNumbers'] = $this->getSerialAllocations($itemCode, $hubCode, $count);
                } else {
                    $serials = [];
                    for ($i = 0; $i < $count; $i++) {
                        $serials[] = ['SerialNumber' => $this->generateSerialNumber($itemCode, $i + 1)];
                    }
                    $line['SerialNumbers'] = $serials;
                }
            }

            if ($binManaged && $binAbsEntry !== null) {
                $line['BinAllocations'] = [[
                    'BinAbsEntry' => $binAbsEntry,
                    'Quantity' => $qty,
                ]];
            }

            $lines[] = $line;
        }

        return $lines;
    }


    private function getItemMaster(string $itemCode): array
    {
        $encoded = str_replace("'", "''", $itemCode);
        $response = $this->get("/Items('{$encoded}')?\$select=ItemCode,ManageBatchNumbers,ManageSerialNumbers,InventoryItem");

        if (!$response->successful()) {
            throw new \RuntimeException('SAP item fetch failed: ' . $response->status() . ' ' . $response->body());
        }

        return $response->json() ?? [];
    }


    private function ensureItemWarehouseExists(string $itemCode, string $warehouseCode): void
    {
        $warehouses = $this->getItemWarehouses($itemCode);
        foreach ($warehouses as $row) {
            if (($row['WarehouseCode'] ?? null) === $warehouseCode) {
                return;
            }
        }

        $updated = $warehouses;
        $updated[] = ['WarehouseCode' => $warehouseCode];

        $encoded = str_replace("'", "''", $itemCode);
        $response = $this->patch("/Items('{$encoded}')", [
            'ItemWarehouseInfoCollection' => $updated,
        ]);

        if (!$response->successful()) {
            throw new \RuntimeException('SAP item warehouse assign failed: ' . $response->status() . ' ' . $response->body());
        }
    }


    private function getItemWarehouses(string $itemCode): array
    {
        $encoded = str_replace("'", "''", $itemCode);
        $response = $this->get("/Items('{$encoded}')");
        if ($response->successful()) {
            $payload = $response->json() ?? [];
            $collection = $payload['ItemWarehouseInfoCollection'] ?? null;
            if (is_array($collection)) {
                return $collection;
            }
        }

        $response = $this->get("/Items('{$encoded}')?\$select=ItemCode,ItemWarehouseInfoCollection");
        if ($response->successful()) {
            $payload = $response->json() ?? [];
            $collection = $payload['ItemWarehouseInfoCollection'] ?? null;
            if (is_array($collection)) {
                return $collection;
            }
        }

        $response = $this->get("/Items('{$encoded}')?\$select=ItemCode&\$expand=ItemWarehouseInfoCollection");
        if ($response->successful()) {
            $payload = $response->json() ?? [];
            $collection = $payload['ItemWarehouseInfoCollection'] ?? null;
            if (is_array($collection)) {
                return $collection;
            }
        }

        $response = $this->get("/ItemWarehouseInfoCollection?\$filter=ItemCode eq '{$encoded}'");
        if ($response->successful()) {
            return $response->json()['value'] ?? [];
        }

        throw new \RuntimeException('SAP item warehouse fetch failed: ' . $response->status() . ' ' . $response->body());
    }


    private function isBatchManaged(array $item): bool
    {
        return (($item['ManageBatchNumbers'] ?? null) === 'tYES');
    }


    private function isSerialManaged(array $item): bool
    {
        return (($item['ManageSerialNumbers'] ?? null) === 'tYES');
    }


    private function isWarehouseBinManaged(string $warehouseCode): bool
    {
        $encoded = str_replace("'", "''", $warehouseCode);
        $response = $this->get("/Warehouses('{$encoded}')?\$select=WarehouseCode,EnableBinLocations");

        if (!$response->successful()) {
            throw new \RuntimeException('SAP warehouse fetch failed: ' . $response->status() . ' ' . $response->body());
        }

        $payload = $response->json() ?? [];
        return (($payload['EnableBinLocations'] ?? null) === 'tYES');
    }


    private function getFirstBinAbsEntry(string $warehouseCode): ?int
    {
        $encoded = str_replace("'", "''", $warehouseCode);
        $response = $this->get("/BinLocations?\$filter=Warehouse eq '{$encoded}'&\$top=1&\$select=AbsEntry");

        if (!$response->successful()) {
            throw new \RuntimeException('SAP bin lookup failed: ' . $response->status() . ' ' . $response->body());
        }

        $value = $response->json()['value'][0]['AbsEntry'] ?? null;
        return is_numeric($value) ? (int) $value : null;
    }


    private function getBatchAllocations(string $itemCode, ?string $warehouseCode, float $qty): array
    {
        if (!$warehouseCode) {
            throw new \RuntimeException('Batch-managed item requires warehouse code');
        }

        $encodedItem = str_replace("'", "''", $itemCode);
        $encodedWhs = str_replace("'", "''", $warehouseCode);
        $response = $this->get("/BatchNumberDetails?\$filter=ItemCode eq '{$encodedItem}' and WarehouseCode eq '{$encodedWhs}' and AvailableQuantity gt 0&\$orderby=InDate asc");

        if (!$response->successful()) {
            throw new \RuntimeException('SAP batch lookup failed: ' . $response->status() . ' ' . $response->body());
        }

        $rows = $response->json()['value'] ?? [];
        $remaining = $qty;
        $batches = [];
        foreach ($rows as $row) {
            $available = (float) ($row['AvailableQuantity'] ?? 0);
            $batchNumber = $row['BatchNumber'] ?? null;
            if (!$batchNumber || $available <= 0) {
                continue;
            }
            $use = min($remaining, $available);
            if ($use <= 0) {
                continue;
            }
            $batches[] = [
                'BatchNumber' => $batchNumber,
                'Quantity' => $use,
            ];
            $remaining -= $use;
            if ($remaining <= 0.0001) {
                break;
            }
        }

        if ($remaining > 0.0001) {
            throw new \RuntimeException('Not enough batch quantity in SAP (Item=' . $itemCode . ', Warehouse=' . $warehouseCode . ')');
        }

        return $batches;
    }


    private function getSerialAllocations(string $itemCode, ?string $warehouseCode, int $count): array
    {
        if (!$warehouseCode) {
            throw new \RuntimeException('Serial-managed item requires warehouse code');
        }

        $encodedItem = str_replace("'", "''", $itemCode);
        $encodedWhs = str_replace("'", "''", $warehouseCode);
        $response = $this->get("/SerialNumberDetails?\$filter=ItemCode eq '{$encodedItem}' and WarehouseCode eq '{$encodedWhs}'&\$top={$count}");

        if (!$response->successful()) {
            throw new \RuntimeException('SAP serial lookup failed: ' . $response->status() . ' ' . $response->body());
        }

        $rows = $response->json()['value'] ?? [];
        $serials = [];
        foreach ($rows as $row) {
            $number = $row['SerialNumber'] ?? null;
            if ($number) {
                $serials[] = ['SerialNumber' => $number];
            }
            if (count($serials) >= $count) {
                break;
            }
        }

        if (count($serials) < $count) {
            throw new \RuntimeException('Not enough serial numbers in SAP (Item=' . $itemCode . ', Warehouse=' . $warehouseCode . ')');
        }

        return $serials;
    }


    private function generateBatchNumber(string $itemCode): string
    {
        return 'OMN-' . date('Ymd-His') . '-' . $itemCode;
    }


    private function generateSerialNumber(string $itemCode, int $index): string
    {
        return 'OMN-' . date('Ymd-His') . '-' . $itemCode . '-' . str_pad((string) $index, 4, '0', STR_PAD_LEFT);
    }


    private function shouldRetryWithoutSeries(string $body): bool
    {
        if ($body === '') {
            return false;
        }

        return str_contains($body, 'Failed to initialize object data')
            || str_contains($body, '"code" : -1')
            || str_contains($body, '"code": -1');
    }


    public function getWarehouseOnHand(string $itemCode, ?string $warehouseCode): float
    {
        if (!$warehouseCode) {
            return 0.0;
        }

        $rows = $this->getItemWarehouses($itemCode);
        foreach ($rows as $row) {
            if (($row['WarehouseCode'] ?? null) === $warehouseCode) {
                return (float) ($row['InStock'] ?? 0);
            }
        }

        $this->ensureItemWarehouseExists($itemCode, $warehouseCode);
        $rows = $this->getItemWarehouses($itemCode);
        foreach ($rows as $row) {
            if (($row['WarehouseCode'] ?? null) === $warehouseCode) {
                return (float) ($row['InStock'] ?? 0);
            }
        }

        return 0.0;
    }


    private function isValidWarehouse(string $warehouseCode): bool
    {
        $encoded = str_replace("'", "''", $warehouseCode);
        $response = $this->get("/Warehouses('{$encoded}')");

        if ($response->status() === 404) {
            return false;
        }

        if (!$response->successful()) {
            throw new \RuntimeException('SAP warehouse lookup failed: ' . $response->status() . ' ' . $response->body());
        }

        return true;
    }


    private function ensureWarehouseExists(string $warehouseCode, int $lineIndex): void
    {
        if ($this->isValidWarehouse($warehouseCode)) {
            return;
        }

        $body = [
            'WarehouseCode' => $warehouseCode,
            'WarehouseName' => $warehouseCode,
        ];

        $response = $this->post('/Warehouses', $body);

        if (!$response->successful()) {
            throw new \RuntimeException('SAP warehouse create failed for line ' . $lineIndex . ' (' . $warehouseCode . '): ' . $response->status() . ' ' . $response->body());
        }
    }

}

