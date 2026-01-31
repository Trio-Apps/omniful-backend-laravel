<?php

namespace App\Services;

use App\Models\IntegrationSetting;
use Illuminate\Support\Facades\Http;

class SapServiceLayerClient
{
    private string $baseUrl;
    private string $companyDb;
    private string $username;
    private string $password;
    private bool $verifySsl;

    public function __construct()
    {
        $settings = IntegrationSetting::first();

        $baseUrl = rtrim((string) ($settings?->sap_service_layer_url ?? ''), '/');
        if (str_ends_with(strtolower($baseUrl), '/login')) {
            $baseUrl = substr($baseUrl, 0, -6);
        }
        $this->baseUrl = $baseUrl;
        $this->companyDb = (string) ($settings?->sap_company_db ?? '');
        $this->username = (string) ($settings?->sap_username ?? '');
        $this->password = (string) ($settings?->sap_password ?? '');
        $this->verifySsl = (bool) ($settings?->sap_ssl_verify ?? true);
    }

    public function createPurchaseOrderFromOmniful(array $data): array
    {
        $docDate = $this->formatDate(data_get($data, 'created_at'));
        $seriesInfo = $this->resolveSeriesForDocument('22', $docDate);
        $docDate = $seriesInfo['docDate'];
        $dueDate = $docDate;
        $currency = data_get($data, 'currency');
        $hubCode = data_get($data, 'hub_code');
        $displayId = data_get($data, 'display_id');
        $series = $seriesInfo['series'];
        $seriesIndicator = $seriesInfo['indicator'];

        $supplierCode = data_get($data, 'supplier.code');
        if (!$supplierCode) {
            throw new \RuntimeException('Missing supplier code for SAP PO (supplier.code)');
        }

        $this->ensureSupplierExists($supplierCode, $data);

        $lines = [];
        $lineIndex = 0;
        foreach ((array) data_get($data, 'purchase_order_items', []) as $item) {
            $lineIndex++;
            $itemCode = data_get($item, 'sku.seller_sku_code')
                ?? data_get($item, 'sku.seller_sku_id')
                ?? data_get($item, 'sku_code');

            if (!$itemCode) {
                throw new \RuntimeException('Missing item code for SAP PO line');
            }

            $this->ensureItemExists($itemCode, $item, $lineIndex);

            $line = [
                'ItemCode' => $itemCode,
                'Quantity' => (float) (data_get($item, 'quantity') ?? 0),
                'UnitPrice' => (float) (data_get($item, 'unit_price') ?? 0),
            ];

            if ($hubCode) {
                $this->ensureWarehouseExists($hubCode, $lineIndex);
                $line['WarehouseCode'] = $hubCode;
            }

            $lines[] = $line;
        }

        if ($lines === []) {
            throw new \RuntimeException('No purchase_order_items found for SAP PO');
        }

        $comments = $displayId ? ('Omniful PO ' . $displayId) : 'Omniful PO';
        if ($seriesIndicator && $seriesIndicator !== substr($docDate, 0, 4)) {
            $comments .= ' | Series period ' . $seriesIndicator . ', DocDate ' . $docDate;
        }
        if ($currency && !$this->isValidCurrency($currency)) {
            $comments .= ' | Currency ' . $currency . ' not found in SAP; using local currency';
            $currency = null;
        }

        $body = [
            'CardCode' => $supplierCode,
            'DocDate' => $docDate,
            'DocDueDate' => $dueDate,
            'DocumentLines' => $lines,
            'Comments' => $comments,
        ];

        if ($series) {
            $body['Series'] = $series;
        }

        if ($currency) {
            $body['DocCurrency'] = $currency;
        }

        if ($displayId) {
            $body['NumAtCard'] = $displayId;
        }

        $response = $this->post('/PurchaseOrders', $body);

        if (!$response->successful()) {
            throw new \RuntimeException('SAP PO create failed: ' . $response->status() . ' ' . $response->body());
        }

        return $response->json() ?? [];
    }

    public function appendPurchaseOrderComment(int $docEntry, string $message): void
    {
        $po = $this->getPurchaseOrder($docEntry);
        $existing = trim((string) ($po['Comments'] ?? ''));
        $next = $existing === '' ? $message : ($existing . "\n" . $message);

        $response = $this->patch('/PurchaseOrders(' . $docEntry . ')', [
            'Comments' => $next,
        ]);

        if (!$response->successful()) {
            throw new \RuntimeException('SAP PO update failed: ' . $response->status() . ' ' . $response->body());
        }
    }



    private function ensureSupplierExists(string $cardCode, array $data): void
    {
        $existing = $this->getBusinessPartner($cardCode);
        if ($existing) {
            return;
        }

        $supplier = (array) data_get($data, 'supplier', []);
        $cardName = data_get($supplier, 'name') ?: $cardCode;

        $body = [
            'CardCode' => $cardCode,
            'CardName' => $cardName,
            'CardType' => 'S',
        ];

        $email = data_get($supplier, 'email');
        $phone = data_get($supplier, 'phone');

        if ($email) {
            $body['EmailAddress'] = $email;
        }
        if ($phone) {
            $body['Phone1'] = $phone;
        }

        $response = $this->post('/BusinessPartners', $body);

        if (!$response->successful()) {
            throw new \RuntimeException('SAP vendor create failed: ' . $response->status() . ' ' . $response->body());
        }
    }

    private function getBusinessPartner(string $cardCode): ?array
    {
        $encoded = str_replace("'", "''", $cardCode);
        $response = $this->get("/BusinessPartners('{$encoded}')");

        if ($response->status() === 404) {
            return null;
        }

        if (!$response->successful()) {
            throw new \RuntimeException('SAP vendor lookup failed: ' . $response->status() . ' ' . $response->body());
        }

        return $response->json() ?? null;
    }

    private function isValidCurrency(string $currency): bool
    {
        $encoded = str_replace("'", "''", $currency);
        $response = $this->get("/Currencies('{$encoded}')");

        if ($response->status() === 404) {
            return false;
        }

        if (!$response->successful()) {
            throw new \RuntimeException('SAP currency lookup failed: ' . $response->status() . ' ' . $response->body());
        }

        return true;
    }

    private function isValidItem(string $itemCode): bool
    {
        $encoded = str_replace("'", "''", $itemCode);
        $response = $this->get("/Items('{$encoded}')");

        if ($response->status() === 404) {
            return false;
        }

        if (!$response->successful()) {
            throw new \RuntimeException('SAP item lookup failed: ' . $response->status() . ' ' . $response->body());
        }

        return true;
    }

    private function ensureItemExists(string $itemCode, array $item, int $lineIndex): bool
    {
        if ($this->isValidItem($itemCode)) {
            return false;
        }

        $name = data_get($item, 'sku.name')
            ?? data_get($item, 'name')
            ?? $itemCode;

        $body = [
            'ItemCode' => $itemCode,
            'ItemName' => $name,
            'InventoryItem' => 'tYES',
            'PurchaseItem' => 'tYES',
            'SalesItem' => 'tNO',
        ];

        $response = $this->post('/Items', $body);

        if (!$response->successful()) {
            throw new \RuntimeException('SAP item create failed for line ' . $lineIndex . ' (' . $itemCode . '): ' . $response->status() . ' ' . $response->body());
        }

        return true;
    }

    public function syncProductFromOmniful(array $data, string $eventName = ''): array
    {
        $itemCode = data_get($data, 'seller_sku_code')
            ?? data_get($data, 'sku_code')
            ?? data_get($data, 'seller_sku_id');

        if (!$itemCode) {
            throw new \RuntimeException('Missing item code for SAP product sync');
        }

        $exists = $this->isValidItem($itemCode);
        $isUpdate = str_contains($eventName, 'update');
        $isDelete = str_contains($eventName, 'delete');

        if ($exists) {
            if ($isUpdate) {
                $this->updateSapItem($itemCode, $data);
                return ['status' => 'updated', 'item_code' => $itemCode];
            }

            return ['status' => 'skipped', 'item_code' => $itemCode];
        }

        if ($isUpdate || $isDelete) {
            $this->createSapItem($itemCode, $data);
            return ['status' => 'created', 'item_code' => $itemCode];
        }

        $this->createSapItem($itemCode, $data);

        return ['status' => 'created', 'item_code' => $itemCode];
    }

    private function updateSapItem(string $itemCode, array $data): void
    {
        $name = data_get($data, 'name') ?? data_get($data, 'product.name');

        $body = [];
        if ($name) {
            $body['ItemName'] = $name;
        }

        if ($body === []) {
            return;
        }

        $encoded = str_replace("'", "''", $itemCode);
        $response = $this->patch("/Items('{$encoded}')", $body);

        if (!$response->successful()) {
            throw new \RuntimeException('SAP item update failed: ' . $response->status() . ' ' . $response->body());
        }
    }

    private function createSapItem(string $itemCode, array $data): void
    {
        $name = data_get($data, 'name') ?? data_get($data, 'product.name') ?? $itemCode;

        $body = [
            'ItemCode' => $itemCode,
            'ItemName' => $name,
            'InventoryItem' => 'tYES',
            'PurchaseItem' => 'tYES',
            'SalesItem' => 'tNO',
        ];

        $response = $this->post('/Items', $body);

        if (!$response->successful()) {
            throw new \RuntimeException('SAP item create failed: ' . $response->status() . ' ' . $response->body());
        }
    }

    private function getPurchaseOrder(int $docEntry): array
    {
        $response = $this->get('/PurchaseOrders(' . $docEntry . ')');

        if (!$response->successful()) {
            throw new \RuntimeException('SAP PO fetch failed: ' . $response->status() . ' ' . $response->body());
        }

        return $response->json() ?? [];
    }

    public function createGoodsReceiptPOFromInventory(int $poDocEntry, array $items, ?string $hubCode, ?string $displayId = null): array
    {
        $po = $this->getPurchaseOrder($poDocEntry);
        $lineMap = [];
        foreach ($po['DocumentLines'] ?? [] as $line) {
            $code = $line['ItemCode'] ?? null;
            $lineNum = $line['LineNum'] ?? null;
            if ($code !== null && $lineNum !== null) {
                $lineMap[$code] = $lineNum;
            }
        }

        $lines = [];
        foreach ($items as $item) {
            $itemCode = data_get($item, 'seller_sku_code')
                ?? data_get($item, 'sku_code')
                ?? data_get($item, 'seller_sku_id');

            if (!$itemCode || !array_key_exists($itemCode, $lineMap)) {
                continue;
            }

            $qty = data_get($item, 'quantity_location_pass_inventory_sum');
            if ($qty === null) {
                $qty = data_get($item, 'quantity_on_hand');
            }
            if ($qty === null) {
                $qty = data_get($item, 'quantity');
            }

            $qty = (float) $qty;
            if ($qty <= 0) {
                continue;
            }

            $line = [
                'BaseType' => 22,
                'BaseEntry' => $poDocEntry,
                'BaseLine' => $lineMap[$itemCode],
                'Quantity' => $qty,
            ];

            if ($hubCode) {
                $line['WarehouseCode'] = $hubCode;
            }

            $lines[] = $line;
        }

        if ($lines === []) {
            throw new \RuntimeException('No matching PO lines found for GRPO');
        }

        $comments = 'GRPO from Omniful inventory';
        if ($displayId) {
            $comments .= ' | PO ' . $displayId;
        }

        $body = [
            'CardCode' => $po['CardCode'] ?? null,
            'DocDate' => now()->format('Y-m-d'),
            'DocumentLines' => $lines,
            'Comments' => $comments,
        ];

        $response = $this->post('/PurchaseDeliveryNotes', $body);

        if (!$response->successful()) {
            throw new \RuntimeException('SAP GRPO create failed: ' . $response->status() . ' ' . $response->body());
        }

        return $response->json() ?? [];
    }

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

    private function getDefaultSeries(string $documentCode): ?int
    {
        $response = $this->post('/SeriesService_GetDefaultSeries', [
            'DocumentTypeParams' => [
                'Document' => $documentCode,
            ],
        ]);

        if (!$response->successful()) {
            throw new \RuntimeException('SAP series lookup failed: ' . $response->status() . ' ' . $response->body());
        }

        $payload = $response->json() ?? [];
        $series = $payload['Series'] ?? null;

        return is_numeric($series) ? (int) $series : null;
    }

    private function getDocumentSeries(string $documentCode): array
    {
        $response = $this->post('/SeriesService_GetDocumentSeries', [
            'DocumentTypeParams' => [
                'Document' => $documentCode,
            ],
        ]);

        if (!$response->successful()) {
            throw new \RuntimeException('SAP series list failed: ' . $response->status() . ' ' . $response->body());
        }

        return $response->json()['value'] ?? [];
    }

    /**
     * @return array<int,array>
     */
    public function fetchWarehouses(): array
    {
        return $this->fetchAll('/Warehouses?$select=WarehouseCode,WarehouseName,EnableBinLocations');
    }

    /**
     * @return array<int,array>
     */
    public function fetchSuppliers(): array
    {
        return $this->fetchAllWithFallback([
            "/BusinessPartners?\$filter=CardType%20eq%20'S'&\$select=CardCode,CardName,EmailAddress,Phone1",
            "/BusinessPartners?\$filter=CardType%20eq%20'S'",
            "/BusinessPartners",
        ]);
    }

    /**
     * @return array<int,array>
     */
    public function fetchItems(): array
    {
        return $this->fetchAll('/Items?$select=ItemCode,ItemName,UoMGroupEntry,InventoryItem,PurchaseItem,SalesItem');
    }

    /**
     * @return array<int,array>
     */
    private function fetchAll(string $path): array
    {
        $items = [];
        $next = $path;

        while ($next) {
            if (!str_starts_with($next, '/')) {
                $next = '/' . ltrim($next, '/');
            }
            $response = $this->get($next);
            if (!$response->successful()) {
                throw new \RuntimeException('SAP fetch failed: ' . $response->status() . ' ' . $response->body() . ' | Path: ' . $next);
            }

            $payload = $response->json() ?? [];
            $items = array_merge($items, $payload['value'] ?? []);

            $nextLink = $payload['odata.nextLink'] ?? $payload['@odata.nextLink'] ?? null;
            if ($nextLink) {
                $next = $this->normalizeNextLink($nextLink);
                continue;
            }

            $next = null;
        }

        return $items;
    }

    private function normalizeNextLink(string $nextLink): string
    {
        $base = $this->baseUrl;
        if (str_starts_with($nextLink, $base)) {
            $nextLink = substr($nextLink, strlen($base));
        }

        $parsed = parse_url($nextLink);
        $path = $parsed['path'] ?? $nextLink;
        $query = isset($parsed['query']) ? ('?' . $parsed['query']) : '';

        if ($path === '' || $path === null) {
            $path = $nextLink;
        }

        if (!str_starts_with($path, '/')) {
            $path = '/' . ltrim($path, '/');
        }

        return $path . $query;
    }

    /**
     * @param array<int,string> $paths
     * @return array<int,array>
     */
    private function fetchAllWithFallback(array $paths): array
    {
        $lastError = null;
        foreach ($paths as $path) {
            try {
                return $this->fetchAll($path);
            } catch (\Throwable $e) {
                $lastError = $e;
                $message = $e->getMessage();
                if (!str_contains($message, ' 404 ')) {
                    throw $e;
                }
            }
        }

        if ($lastError) {
            throw $lastError;
        }

        return [];
    }

    /**
     * @return array{series:?int,docDate:string,indicator:?string}
     */
    private function resolveSeriesForDocument(string $documentCode, string $docDate): array
    {
        $year = substr($docDate, 0, 4);
        $seriesList = $this->getDocumentSeries($documentCode);

        $force2025 = $this->pickSeriesByIndicator($seriesList, '2025', true);
        if ($force2025) {
            $seriesId = isset($force2025['Series']) ? (int) $force2025['Series'] : null;
            return ['series' => $seriesId, 'docDate' => $docDate, 'indicator' => '2025'];
        }

        $pick = $this->pickSeriesByIndicator($seriesList, $year, true)
            ?? $this->pickSeriesByIndicator($seriesList, 'Default', true)
            ?? $this->pickFirstUnlockedSeries($seriesList, true);

        if (!$pick) {
            return ['series' => $this->getDefaultSeries($documentCode), 'docDate' => $docDate, 'indicator' => null];
        }

        $indicator = (string) ($pick['PeriodIndicator'] ?? '');
        $seriesId = isset($pick['Series']) ? (int) $pick['Series'] : null;

        if ($indicator !== '' && $indicator !== 'Default' && $indicator !== $year && preg_match('/^\\d{4}$/', $indicator)) {
            $docDate = $indicator . '-01-01';
        }

        return ['series' => $seriesId, 'docDate' => $docDate, 'indicator' => $indicator ?: null];
    }

    private function pickSeriesByIndicator(array $seriesList, string $indicator, bool $requireUsable): ?array
    {
        foreach ($seriesList as $series) {
            if (($series['PeriodIndicator'] ?? null) === $indicator && ($series['Locked'] ?? 'tNO') !== 'tYES') {
                if ($requireUsable && !$this->isSeriesUsable($series)) {
                    continue;
                }
                return $series;
            }
        }

        return null;
    }

    private function pickFirstUnlockedSeries(array $seriesList, bool $requireUsable): ?array
    {
        foreach ($seriesList as $series) {
            if (($series['Locked'] ?? 'tNO') !== 'tYES') {
                if ($requireUsable && !$this->isSeriesUsable($series)) {
                    continue;
                }
                return $series;
            }
        }

        return null;
    }

    private function isSeriesUsable(array $series): bool
    {
        $last = $series['LastNumber'] ?? null;
        $next = $series['NextNumber'] ?? null;

        if ($last === null || $last === '') {
            return true;
        }

        if ($next === null || $next === '') {
            return false;
        }

        return (int) $next <= (int) $last;
    }

    private function get(string $path)
    {
        $cookies = $this->login();

        $client = Http::timeout(30)->acceptJson();
        if (!$this->verifySsl) {
            $client = $client->withoutVerifying();
        }

        $response = $client->withHeaders([
            'Cookie' => $cookies,
        ])->get($this->baseUrl . $path);

        $this->logout($cookies);

        return $response;
    }

    private function post(string $path, array|object $body)
    {
        $cookies = $this->login();

        $client = Http::timeout(30)->acceptJson();
        if (!$this->verifySsl) {
            $client = $client->withoutVerifying();
        }

        $response = $client->withHeaders([
            'Cookie' => $cookies,
        ])->post($this->baseUrl . $path, $body);

        $this->logout($cookies);

        return $response;
    }

    private function patch(string $path, array $body)
    {
        $cookies = $this->login();

        $client = Http::timeout(30)->acceptJson();
        if (!$this->verifySsl) {
            $client = $client->withoutVerifying();
        }

        $response = $client->withHeaders([
            'Cookie' => $cookies,
        ])->patch($this->baseUrl . $path, $body);

        $this->logout($cookies);

        return $response;
    }

    private function login(): string
    {
        if ($this->baseUrl === '' || $this->companyDb === '' || $this->username === '' || $this->password === '') {
            throw new \RuntimeException('SAP credentials are incomplete');
        }

        $client = Http::timeout(20)->acceptJson();
        if (!$this->verifySsl) {
            $client = $client->withoutVerifying();
        }

        $response = $client->post($this->baseUrl . '/Login', [
            'CompanyDB' => $this->companyDb,
            'UserName' => $this->username,
            'Password' => $this->password,
        ]);

        if (!$response->successful()) {
            throw new \RuntimeException('SAP login failed: ' . $response->status() . ' ' . $response->body());
        }

        $payload = $response->json() ?? [];
        $sessionId = $payload['SessionId'] ?? null;
        $routeId = $payload['RouteId'] ?? null;

        if (!$sessionId) {
            throw new \RuntimeException('SAP login failed: missing SessionId');
        }

        $cookie = 'B1SESSION=' . $sessionId;
        if ($routeId) {
            $cookie .= '; ROUTEID=' . $routeId;
        }

        return $cookie;
    }

    private function logout(string $cookie): void
    {
        $client = Http::timeout(10)->acceptJson();
        if (!$this->verifySsl) {
            $client = $client->withoutVerifying();
        }

        try {
            $client->withHeaders(['Cookie' => $cookie])->post($this->baseUrl . '/Logout');
        } catch (\Throwable) {
            // ignore logout errors
        }
    }

    private function formatDate(?string $value): string
    {
        if (!$value) {
            return now()->format('Y-m-d');
        }

        try {
            return \Illuminate\Support\Carbon::parse($value)->format('Y-m-d');
        } catch (\Throwable) {
            return now()->format('Y-m-d');
        }
    }
}
