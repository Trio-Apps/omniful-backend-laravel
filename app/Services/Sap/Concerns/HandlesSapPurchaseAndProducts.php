<?php

namespace App\Services\Sap\Concerns;

trait HandlesSapPurchaseAndProducts
{
    public function createArReserveInvoiceFromOmnifulOrder(array $data, string $externalId): array
    {
        $docDate = $this->formatDate((string) (data_get($data, 'order_created_at') ?? data_get($data, 'created_at') ?? null));
        $currency = data_get($data, 'invoice.currency') ?? data_get($data, 'currency');
        $hubCode = data_get($data, 'hub_code');
        $seriesInfo = $this->resolveSeriesForDocument('17', $docDate);
        $docDate = $seriesInfo['docDate'];

        $customerCode = data_get($data, 'customer.code');
        if (!$customerCode) {
            $customerCode = $this->buildCustomerCode($data, $externalId);
        }
        $this->ensureCustomerExists((string) $customerCode, $data, $externalId);

        $lines = [];
        $lineIndex = 0;
        $items = data_get($data, 'order_items', data_get($data, 'items', []));
        foreach ((array) $items as $item) {
            $lineIndex++;
            $itemCode = data_get($item, 'sku_code')
                ?? data_get($item, 'seller_sku_code')
                ?? data_get($item, 'seller_sku.seller_sku_code')
                ?? data_get($item, 'seller_sku.seller_sku_id');

            if (!$itemCode) {
                continue;
            }

            $this->ensureItemExists((string) $itemCode, (array) $item, $lineIndex);

            $qty = (float) (data_get($item, 'quantity') ?? 0);
            if ($qty <= 0) {
                continue;
            }

            $unitPrice = data_get($item, 'unit_price');
            if ($unitPrice === null) {
                $unitPrice = data_get($item, 'price');
            }

            $line = [
                'ItemCode' => (string) $itemCode,
                'Quantity' => $qty,
                'UnitPrice' => (float) ($unitPrice ?? 0),
            ];

            if ($hubCode) {
                $this->ensureWarehouseExists((string) $hubCode, $lineIndex);
                $line['WarehouseCode'] = (string) $hubCode;
            }

            $lines[] = $line;
        }

        if ($lines === []) {
            return [
                'ignored' => true,
                'reason' => 'No order lines found for AR reserve invoice',
            ];
        }

        $comments = 'AR Reserve Invoice from Omniful order ' . $externalId;
        if ($currency && !$this->isValidCurrency((string) $currency)) {
            $comments .= ' | Currency ' . $currency . ' not found in SAP; using local currency';
            $currency = null;
        }

        $body = [
            'CardCode' => (string) $customerCode,
            'DocDate' => $docDate,
            'DocDueDate' => $docDate,
            'DocumentLines' => $lines,
            'NumAtCard' => $externalId,
            'Comments' => $comments,
            // SAP B1 AR Reserve Invoice via Orders with ReserveInvoice = tYES
            'ReserveInvoice' => 'tYES',
        ];

        if ($seriesInfo['series']) {
            $body['Series'] = $seriesInfo['series'];
        }

        if ($currency) {
            $body['DocCurrency'] = $currency;
        }

        $response = $this->post('/Orders', $body);

        if (!$response->successful()) {
            throw new \RuntimeException('SAP AR reserve invoice create failed: ' . $response->status() . ' ' . $response->body());
        }

        $payload = $response->json() ?? [];
        $payload['ignored'] = false;

        return $payload;
    }

    public function createIncomingPaymentForInvoice(array $data): array
    {
        $invoiceDocEntry = (int) ($data['invoice_doc_entry'] ?? 0);
        if ($invoiceDocEntry <= 0) {
            throw new \RuntimeException('Missing invoice doc entry for incoming payment');
        }

        $transferAccount = trim((string) ($data['transfer_account'] ?? config('omniful.order_payment.transfer_account', '')));
        if ($transferAccount === '') {
            return [
                'ignored' => true,
                'reason' => 'Missing incoming payment transfer account',
            ];
        }

        $invoiceTypeCandidates = $this->normalizeInvoiceTypeCandidates(
            $data['invoice_type_candidates'] ?? config('omniful.order_payment.invoice_type_candidates', [17, 13])
        );
        if ($invoiceTypeCandidates === []) {
            $invoiceTypeCandidates = [17, 13];
        }

        $salesDoc = $this->getSalesOrder($invoiceDocEntry);
        $cardCode = (string) ($data['card_code'] ?? ($salesDoc['CardCode'] ?? ''));
        if ($cardCode === '') {
            throw new \RuntimeException('Missing CardCode for incoming payment');
        }

        $sumApplied = (float) ($data['sum_applied'] ?? ($salesDoc['DocTotal'] ?? 0));
        if ($sumApplied <= 0) {
            return [
                'ignored' => true,
                'reason' => 'Incoming payment skipped: non-positive amount',
            ];
        }

        $transferDate = $this->formatDate((string) ($data['transfer_date'] ?? now()->format('Y-m-d')));
        $attemptErrors = [];

        foreach ($invoiceTypeCandidates as $invoiceType) {
            $body = [
                'CardCode' => $cardCode,
                'DocType' => 'rCustomer',
                'TransferAccount' => $transferAccount,
                'TransferDate' => $transferDate,
                'TransferSum' => $sumApplied,
                'PaymentInvoices' => [
                    [
                        'DocEntry' => $invoiceDocEntry,
                        'InvoiceType' => $invoiceType,
                        'SumApplied' => $sumApplied,
                    ],
                ],
                'Remarks' => 'Incoming payment from Omniful prepaid order',
            ];

            $response = $this->post('/IncomingPayments', $body);
            if ($response->successful()) {
                $payload = $response->json() ?? [];
                $payload['ignored'] = false;
                $payload['invoice_type_used'] = $invoiceType;
                return $payload;
            }

            $attemptErrors[] = 'invoice_type=' . $invoiceType . ': ' . $response->status() . ' ' . $response->body();
        }

        throw new \RuntimeException(
            'SAP incoming payment create failed for all invoice types: ' . implode(' | ', $attemptErrors)
        );
    }

    public function createCardFeeJournalEntryForOrder(array $data): array
    {
        $amount = (float) ($data['amount'] ?? 0);
        if ($amount <= 0) {
            return [
                'ignored' => true,
                'reason' => 'Card fee amount is not positive',
            ];
        }

        $expenseAccount = trim((string) ($data['expense_account'] ?? config('omniful.order_payment.card_fee_expense_account', '')));
        $offsetAccount = trim((string) ($data['offset_account'] ?? config('omniful.order_payment.card_fee_offset_account', '')));
        if ($expenseAccount === '' || $offsetAccount === '') {
            return [
                'ignored' => true,
                'reason' => 'Missing card fee journal accounts',
            ];
        }

        $referenceDate = $this->formatDate((string) ($data['posting_date'] ?? now()->format('Y-m-d')));
        $reference = trim((string) ($data['reference'] ?? ''));
        $memo = trim((string) ($data['memo'] ?? 'Card fee journal from Omniful prepaid order'));

        $body = [
            'ReferenceDate' => $referenceDate,
            'DueDate' => $referenceDate,
            'TaxDate' => $referenceDate,
            'Memo' => $memo,
            'JournalEntryLines' => [
                [
                    'AccountCode' => $expenseAccount,
                    'Debit' => $amount,
                ],
                [
                    'AccountCode' => $offsetAccount,
                    'Credit' => $amount,
                ],
            ],
        ];

        if ($reference !== '') {
            $body['Reference'] = $reference;
            $body['Reference2'] = $reference;
            $body['Reference3'] = $reference;
        }

        $response = $this->post('/JournalEntries', $body);
        if (!$response->successful()) {
            throw new \RuntimeException('SAP card-fee journal create failed: ' . $response->status() . ' ' . $response->body());
        }

        $payload = $response->json() ?? [];
        $payload['ignored'] = false;

        return $payload;
    }

    public function createDeliveryFromReserveOrder(array $data): array
    {
        $orderDocEntry = (int) ($data['order_doc_entry'] ?? 0);
        if ($orderDocEntry <= 0) {
            throw new \RuntimeException('Missing SAP order doc entry for delivery');
        }

        $salesDoc = $this->getSalesOrder($orderDocEntry);
        $hubCode = (string) ($data['hub_code'] ?? '');
        $externalId = (string) ($data['external_id'] ?? '');
        $docDate = $this->formatDate((string) now()->format('Y-m-d'));
        $seriesInfo = $this->resolveSeriesForDocument('15', $docDate);
        $docDate = $seriesInfo['docDate'];

        $lines = [];
        foreach ((array) ($salesDoc['DocumentLines'] ?? []) as $line) {
            $lineNum = $line['LineNum'] ?? null;
            if (!is_numeric($lineNum)) {
                continue;
            }

            $openQty = $this->extractOpenOrderLineQuantity($line);
            if ($openQty <= 0) {
                continue;
            }

            $deliveryLine = [
                'BaseType' => 17,
                'BaseEntry' => $orderDocEntry,
                'BaseLine' => (int) $lineNum,
                'Quantity' => $openQty,
            ];

            if ($hubCode !== '') {
                $this->ensureWarehouseExists($hubCode, ((int) $lineNum) + 1);
                $deliveryLine['WarehouseCode'] = $hubCode;
            }

            $lines[] = $deliveryLine;
        }

        if ($lines === []) {
            return [
                'ignored' => true,
                'reason' => 'No open quantity found for delivery',
            ];
        }

        $body = [
            'CardCode' => (string) ($salesDoc['CardCode'] ?? ''),
            'DocDate' => $docDate,
            'DocDueDate' => $docDate,
            'Comments' => 'Delivery from Omniful order ' . $externalId,
            'DocumentLines' => $lines,
        ];

        if ($seriesInfo['series']) {
            $body['Series'] = $seriesInfo['series'];
        }

        $response = $this->post('/DeliveryNotes', $body);
        if (!$response->successful()) {
            throw new \RuntimeException('SAP delivery create failed: ' . $response->status() . ' ' . $response->body());
        }

        $payload = $response->json() ?? [];
        $payload['ignored'] = false;

        return $payload;
    }

    public function createCogsJournalEntryForDelivery(array $data): array
    {
        $deliveryDocEntry = (int) ($data['delivery_doc_entry'] ?? 0);
        if ($deliveryDocEntry <= 0) {
            throw new \RuntimeException('Missing SAP delivery doc entry for COGS journal');
        }

        $expenseAccount = trim((string) ($data['expense_account'] ?? config('omniful.order_accounting.cogs_expense_account', '')));
        $offsetAccount = trim((string) ($data['offset_account'] ?? config('omniful.order_accounting.inventory_offset_account', '')));
        if ($expenseAccount === '' || $offsetAccount === '') {
            return [
                'ignored' => true,
                'reason' => 'Missing COGS journal accounts',
            ];
        }

        $delivery = $this->getDeliveryNote($deliveryDocEntry);
        $amount = (float) ($data['amount'] ?? $this->extractDeliveryCogsAmount($delivery));
        if ($amount <= 0) {
            return [
                'ignored' => true,
                'reason' => 'COGS amount is not available from delivery lines',
            ];
        }

        $referenceDate = $this->formatDate((string) (($delivery['DocDate'] ?? null) ?: now()->format('Y-m-d')));
        $reference = trim((string) ($data['reference'] ?? ($delivery['NumAtCard'] ?? '')));
        $memo = trim((string) ($data['memo'] ?? ('COGS journal for Delivery ' . ($delivery['DocNum'] ?? $deliveryDocEntry))));

        $body = [
            'ReferenceDate' => $referenceDate,
            'DueDate' => $referenceDate,
            'TaxDate' => $referenceDate,
            'Memo' => $memo,
            'JournalEntryLines' => [
                [
                    'AccountCode' => $expenseAccount,
                    'Debit' => $amount,
                ],
                [
                    'AccountCode' => $offsetAccount,
                    'Credit' => $amount,
                ],
            ],
        ];

        if ($reference !== '') {
            $body['Reference'] = $reference;
            $body['Reference2'] = (string) ($delivery['DocNum'] ?? $reference);
            $body['Reference3'] = $reference;
        }

        $response = $this->post('/JournalEntries', $body);
        if (!$response->successful()) {
            throw new \RuntimeException('SAP COGS journal create failed: ' . $response->status() . ' ' . $response->body());
        }

        $payload = $response->json() ?? [];
        $payload['ignored'] = false;

        return $payload;
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
        $linesByItem = [];
        foreach ($po['DocumentLines'] ?? [] as $line) {
            $code = $line['ItemCode'] ?? null;
            $lineNum = $line['LineNum'] ?? null;
            if ($code !== null && $lineNum !== null) {
                $openQty = $line['RemainingOpenQuantity'] ?? $line['OpenQuantity'] ?? null;
                if ($openQty === null) {
                    $orderedQty = (float) ($line['Quantity'] ?? 0);
                    $receivedQty = (float) ($line['ReceivedQuantity'] ?? 0);
                    $openQty = max(0.0, $orderedQty - $receivedQty);
                }
                $linesByItem[$code][] = [
                    'line_num' => (int) $lineNum,
                    'open_qty' => max(0.0, (float) $openQty),
                    'warehouse' => (string) ($line['WarehouseCode'] ?? ''),
                ];
            }
        }

        $lines = [];
        $matchedItems = 0;
        $fullyReceivedItems = 0;
        foreach ($items as $item) {
            $itemCode = data_get($item, 'seller_sku_code')
                ?? data_get($item, 'sku_code')
                ?? data_get($item, 'seller_sku_id');

            if (!$itemCode || !array_key_exists($itemCode, $linesByItem)) {
                continue;
            }
            $matchedItems++;

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

            $remainingToAllocate = $qty;
            $allocatedAny = false;
            foreach ($linesByItem[$itemCode] as &$poLine) {
                if ($remainingToAllocate <= 0) {
                    break;
                }

                if ($hubCode && $poLine['warehouse'] !== '' && $poLine['warehouse'] !== $hubCode) {
                    continue;
                }

                $lineOpenQty = (float) ($poLine['open_qty'] ?? 0);
                if ($lineOpenQty <= 0) {
                    continue;
                }

                $allocQty = min($remainingToAllocate, $lineOpenQty);
                if ($allocQty <= 0) {
                    continue;
                }

                $line = [
                    'BaseType' => 22,
                    'BaseEntry' => $poDocEntry,
                    'BaseLine' => (int) $poLine['line_num'],
                    'Quantity' => $allocQty,
                ];

                if ($hubCode) {
                    $line['WarehouseCode'] = $hubCode;
                }

                $lines[] = $line;
                $poLine['open_qty'] = max(0.0, $lineOpenQty - $allocQty);
                $remainingToAllocate -= $allocQty;
                $allocatedAny = true;
            }
            unset($poLine);

            if (!$allocatedAny) {
                $fullyReceivedItems++;
            }
        }

        if ($lines === []) {
            $reason = 'No matching PO lines found for GRPO';
            if ($matchedItems > 0 && $fullyReceivedItems === $matchedItems) {
                $reason = 'PO lines already fully received';
            }
            return [
                'ignored' => true,
                'reason' => $reason,
            ];
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

        $payload = $response->json() ?? [];
        $payload['ignored'] = false;

        return $payload;
    }

    private function getSalesOrder(int $docEntry): array
    {
        $response = $this->get('/Orders(' . $docEntry . ')');

        if (!$response->successful()) {
            throw new \RuntimeException('SAP order fetch failed: ' . $response->status() . ' ' . $response->body());
        }

        return $response->json() ?? [];
    }

    private function getDeliveryNote(int $docEntry): array
    {
        $response = $this->get('/DeliveryNotes(' . $docEntry . ')');

        if (!$response->successful()) {
            throw new \RuntimeException('SAP delivery fetch failed: ' . $response->status() . ' ' . $response->body());
        }

        return $response->json() ?? [];
    }

    private function ensureCustomerExists(string $cardCode, array $data, string $externalId): void
    {
        $existing = $this->getBusinessPartner($cardCode);
        if ($existing) {
            return;
        }

        $firstName = (string) (data_get($data, 'customer.first_name') ?? '');
        $lastName = (string) (data_get($data, 'customer.last_name') ?? '');
        $fullName = trim($firstName . ' ' . $lastName);
        if ($fullName === '') {
            $fullName = 'Omniful Customer ' . $externalId;
        }

        $body = [
            'CardCode' => $cardCode,
            'CardName' => $fullName,
            'CardType' => 'C',
        ];

        $email = data_get($data, 'customer.email') ?? data_get($data, 'billing_address.email');
        $phone = data_get($data, 'customer.phone')
            ?? data_get($data, 'billing_address.phone')
            ?? data_get($data, 'shipping_address.phone');

        if ($email) {
            $body['EmailAddress'] = (string) $email;
        }
        if ($phone) {
            $body['Phone1'] = (string) $phone;
        }

        $response = $this->post('/BusinessPartners', $body);
        if (!$response->successful()) {
            throw new \RuntimeException('SAP customer create failed: ' . $response->status() . ' ' . $response->body());
        }
    }

    private function buildCustomerCode(array $data, string $externalId): string
    {
        $seed = (string) (
            data_get($data, 'customer.email')
            ?? data_get($data, 'customer.phone')
            ?? data_get($data, 'billing_address.phone')
            ?? $externalId
        );
        $hash = strtoupper(substr(sha1($seed), 0, 10));
        return 'OMNC' . $hash;
    }

    /**
     * @param mixed $candidates
     * @return array<int,int>
     */
    private function normalizeInvoiceTypeCandidates(mixed $candidates): array
    {
        $values = is_array($candidates) ? $candidates : [$candidates];
        $normalized = [];

        foreach ($values as $value) {
            if (is_numeric($value)) {
                $normalized[] = (int) $value;
            }
        }

        return array_values(array_unique($normalized));
    }

    private function extractOpenOrderLineQuantity(array $line): float
    {
        $candidates = [
            $line['RemainingOpenQuantity'] ?? null,
            $line['OpenQuantity'] ?? null,
            $line['RemainingOpenInventoryQuantity'] ?? null,
        ];

        foreach ($candidates as $value) {
            if (is_numeric($value)) {
                $qty = (float) $value;
                if ($qty > 0) {
                    return $qty;
                }
            }
        }

        $orderedQty = (float) ($line['Quantity'] ?? 0);
        $deliveredQty = (float) ($line['DeliveredQuantity'] ?? 0);
        $openQty = $orderedQty - $deliveredQty;

        return $openQty > 0 ? $openQty : 0.0;
    }

    private function extractDeliveryCogsAmount(array $delivery): float
    {
        $total = 0.0;
        foreach ((array) ($delivery['DocumentLines'] ?? []) as $line) {
            $qty = (float) ($line['Quantity'] ?? 0);
            if ($qty <= 0) {
                continue;
            }

            $unitCost = null;
            $candidates = [
                $line['StockPrice'] ?? null,
                $line['GrossBuyPrice'] ?? null,
                $line['GrossBase'] ?? null,
            ];
            foreach ($candidates as $candidate) {
                if (is_numeric($candidate) && (float) $candidate > 0) {
                    $unitCost = (float) $candidate;
                    break;
                }
            }

            if ($unitCost === null || $unitCost <= 0) {
                continue;
            }

            $total += $qty * $unitCost;
        }

        return round($total, 6);
    }

}

