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

    public function createArCreditMemoFromReturnOrder(array $data, array $options = []): array
    {
        $externalId = (string) ($options['external_id'] ?? '');
        $hubCode = (string) (data_get($data, 'hub_code') ?? '');
        $baseDeliveryDocEntry = (int) ($options['base_delivery_doc_entry'] ?? 0);
        $baseOrderDocEntry = (int) ($options['base_order_doc_entry'] ?? 0);

        $items = $this->buildReturnCreditLinesFromPayload($data);
        if ($items === []) {
            return [
                'ignored' => true,
                'reason' => 'No return lines found for AR credit memo',
            ];
        }

        $docDate = $this->formatDate((string) (data_get($data, 'updated_at') ?? data_get($data, 'created_at') ?? null));
        $seriesInfo = $this->resolveSeriesForDocument('14', $docDate);
        $docDate = $seriesInfo['docDate'];

        $cardCode = '';
        $documentLines = [];
        if ($baseDeliveryDocEntry > 0) {
            $delivery = $this->getDeliveryNote($baseDeliveryDocEntry);
            $cardCode = (string) ($delivery['CardCode'] ?? '');
            $documentLines = $this->buildCreditLinesFromDelivery($items, $delivery, $hubCode, $baseDeliveryDocEntry);
        }

        if ($documentLines === [] && $baseOrderDocEntry > 0) {
            $order = $this->getSalesOrder($baseOrderDocEntry);
            if ($cardCode === '') {
                $cardCode = (string) ($order['CardCode'] ?? '');
            }
            $documentLines = $this->buildDirectCreditLines($items, $hubCode);
        }

        if ($documentLines === []) {
            $documentLines = $this->buildDirectCreditLines($items, $hubCode);
        }

        if ($cardCode === '') {
            $customerCode = data_get($data, 'customer.code');
            if ($customerCode) {
                $cardCode = (string) $customerCode;
            } else {
                $seedId = (string) (
                    data_get($data, 'order_reference_id')
                    ?? data_get($data, 'return_order_id')
                    ?? data_get($data, 'id')
                    ?? $externalId
                );
                $cardCode = $this->buildCustomerCode($data, $seedId);
            }
            $this->ensureCustomerExists($cardCode, $data, $externalId !== '' ? $externalId : $cardCode);
        }

        if ($documentLines === []) {
            return [
                'ignored' => true,
                'reason' => 'No SAP credit memo lines could be built',
            ];
        }

        $body = [
            'CardCode' => $cardCode,
            'DocDate' => $docDate,
            'DocDueDate' => $docDate,
            'DocumentLines' => $documentLines,
            'Comments' => 'AR Credit Memo from Omniful return ' . ($externalId !== '' ? $externalId : 'event'),
        ];

        if ($seriesInfo['series']) {
            $body['Series'] = $seriesInfo['series'];
        }

        if ($externalId !== '') {
            $body['NumAtCard'] = $externalId;
        }

        $response = $this->post('/CreditNotes', $body);
        if (!$response->successful()) {
            throw new \RuntimeException('SAP AR credit memo create failed: ' . $response->status() . ' ' . $response->body());
        }

        $payload = $response->json() ?? [];
        $payload['ignored'] = false;
        return $payload;
    }

    public function createCogsReversalJournalForCreditMemo(array $data): array
    {
        $creditMemoDocEntry = (int) ($data['credit_memo_doc_entry'] ?? 0);
        if ($creditMemoDocEntry <= 0) {
            throw new \RuntimeException('Missing SAP credit memo doc entry for COGS reversal');
        }

        $expenseAccount = trim((string) ($data['expense_account'] ?? config('omniful.order_accounting.cogs_expense_account', '')));
        $offsetAccount = trim((string) ($data['offset_account'] ?? config('omniful.order_accounting.inventory_offset_account', '')));
        if ($expenseAccount === '' || $offsetAccount === '') {
            return [
                'ignored' => true,
                'reason' => 'Missing COGS reversal accounts',
            ];
        }

        $creditMemo = $this->getCreditNote($creditMemoDocEntry);
        $amount = (float) ($data['amount'] ?? $this->extractCreditMemoCogsAmount($creditMemo));
        if ($amount <= 0) {
            return [
                'ignored' => true,
                'reason' => 'COGS reversal amount is not available from credit memo lines',
            ];
        }

        $referenceDate = $this->formatDate((string) (($creditMemo['DocDate'] ?? null) ?: now()->format('Y-m-d')));
        $reference = trim((string) ($data['reference'] ?? ($creditMemo['NumAtCard'] ?? '')));
        $memo = trim((string) ($data['memo'] ?? ('COGS reversal for Credit Memo ' . ($creditMemo['DocNum'] ?? $creditMemoDocEntry))));

        $body = [
            'ReferenceDate' => $referenceDate,
            'DueDate' => $referenceDate,
            'TaxDate' => $referenceDate,
            'Memo' => $memo,
            'JournalEntryLines' => [
                [
                    'AccountCode' => $offsetAccount,
                    'Debit' => $amount,
                ],
                [
                    'AccountCode' => $expenseAccount,
                    'Credit' => $amount,
                ],
            ],
        ];

        if ($reference !== '') {
            $body['Reference'] = $reference;
            $body['Reference2'] = (string) ($creditMemo['DocNum'] ?? $reference);
            $body['Reference3'] = $reference;
        }

        $response = $this->post('/JournalEntries', $body);
        if (!$response->successful()) {
            throw new \RuntimeException('SAP COGS reversal journal create failed: ' . $response->status() . ' ' . $response->body());
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

        $supplierCode = $this->ensureSupplierExists((string) $supplierCode, $data);

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




    private function ensureSupplierExists(string $cardCode, array $data): string
    {
        $existing = $this->getBusinessPartner($cardCode);
        if ($existing) {
            return $cardCode;
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
        if ($response->successful()) {
            return $cardCode;
        }

        if (array_key_exists('Phone1', $body) && $this->isSapMobileDuplicationError($response->body())) {
            $existingByPhone = $this->findSupplierByPhone((string) $body['Phone1']);
            if ($existingByPhone) {
                return $existingByPhone;
            }

            unset($body['Phone1']);
            $retry = $this->post('/BusinessPartners', $body);
            if ($retry->successful()) {
                return $cardCode;
            }

            throw new \RuntimeException('SAP vendor create failed (retry without phone): ' . $retry->status() . ' ' . $retry->body());
        }

        throw new \RuntimeException('SAP vendor create failed: ' . $response->status() . ' ' . $response->body());
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

    public function syncSupplierFromOmniful(array $data): array
    {
        $cardCode = (string) (
            data_get($data, 'code')
            ?? data_get($data, 'supplier_code')
            ?? data_get($data, 'id')
        );

        if ($cardCode === '') {
            throw new \RuntimeException('Missing supplier code for SAP supplier sync');
        }

        if (!$this->isSupplierIntegrationEnabled($cardCode)) {
            return ['status' => 'skipped_by_udf', 'supplier_code' => $cardCode];
        }

        $cardName = (string) (data_get($data, 'name') ?? $cardCode);
        $email = data_get($data, 'email');
        $phone = data_get($data, 'phone') ?? data_get($data, 'phone_number');
        $existing = $this->getBusinessPartner($cardCode);

        $payload = array_filter([
            'CardCode' => $cardCode,
            'CardName' => $cardName,
            'CardType' => 'S',
            'EmailAddress' => $email ?: null,
            'Phone1' => $phone ?: null,
        ], fn ($value) => $value !== null && $value !== '');

        if ($existing) {
            $updatePayload = $payload;
            unset($updatePayload['CardCode'], $updatePayload['CardType']);

            if ($updatePayload !== []) {
                $encoded = str_replace("'", "''", $cardCode);
                $response = $this->patch("/BusinessPartners('{$encoded}')", $updatePayload);
                if (!$response->successful()) {
                    throw new \RuntimeException('SAP supplier update failed: ' . $response->status() . ' ' . $response->body());
                }
            }

            return ['status' => 'updated', 'supplier_code' => $cardCode];
        }

        $response = $this->post('/BusinessPartners', $payload);
        if ($response->successful()) {
            return ['status' => 'created', 'supplier_code' => $cardCode];
        }

        if (array_key_exists('Phone1', $payload) && $this->isSapMobileDuplicationError($response->body())) {
            $existingByPhone = $this->findSupplierByPhone((string) $payload['Phone1']);
            if ($existingByPhone) {
                return ['status' => 'linked_existing_by_phone', 'supplier_code' => $existingByPhone];
            }

            unset($payload['Phone1']);
            $retry = $this->post('/BusinessPartners', $payload);
            if ($retry->successful()) {
                return ['status' => 'created', 'supplier_code' => $cardCode];
            }

            throw new \RuntimeException('SAP supplier create failed (retry without phone): ' . $retry->status() . ' ' . $retry->body());
        }

        throw new \RuntimeException('SAP supplier create failed: ' . $response->status() . ' ' . $response->body());
    }

    public function syncWarehouseFromOmniful(array $data): array
    {
        $warehouseCode = (string) (
            data_get($data, 'code')
            ?? data_get($data, 'hub_code')
            ?? data_get($data, 'warehouse_code')
            ?? data_get($data, 'id')
        );

        if ($warehouseCode === '') {
            throw new \RuntimeException('Missing warehouse code for SAP warehouse sync');
        }

        if (!$this->isWarehouseIntegrationEnabled($warehouseCode)) {
            return ['status' => 'skipped_by_udf', 'warehouse_code' => $warehouseCode];
        }

        $warehouseName = (string) (
            data_get($data, 'name')
            ?? data_get($data, 'hub_name')
            ?? $warehouseCode
        );

        if ($this->isValidWarehouse($warehouseCode)) {
            $encoded = str_replace("'", "''", $warehouseCode);
            $response = $this->patch("/Warehouses('{$encoded}')", [
                'WarehouseName' => $warehouseName,
            ]);

            if (!$response->successful()) {
                throw new \RuntimeException('SAP warehouse update failed: ' . $response->status() . ' ' . $response->body());
            }

            return ['status' => 'updated', 'warehouse_code' => $warehouseCode];
        }

        $response = $this->post('/Warehouses', [
            'WarehouseCode' => $warehouseCode,
            'WarehouseName' => $warehouseName,
        ]);

        if (!$response->successful()) {
            throw new \RuntimeException('SAP warehouse create failed: ' . $response->status() . ' ' . $response->body());
        }

        return ['status' => 'created', 'warehouse_code' => $warehouseCode];
    }


    public function syncProductFromOmniful(array $data, string $eventName = ''): array
    {
        $itemCode = data_get($data, 'seller_sku_code')
            ?? data_get($data, 'sku_code')
            ?? data_get($data, 'seller_sku_id');

        if (!$itemCode) {
            throw new \RuntimeException('Missing item code for SAP product sync');
        }

        if (!$this->isItemIntegrationEnabled((string) $itemCode)) {
            return ['status' => 'skipped_by_udf', 'item_code' => (string) $itemCode];
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

    public function syncBundleFromOmniful(array $data, string $eventName = ''): array
    {
        $bundleCode = data_get($data, 'bundle_code')
            ?? data_get($data, 'seller_sku_code')
            ?? data_get($data, 'sku_code')
            ?? data_get($data, 'code')
            ?? data_get($data, 'id');

        if (!$bundleCode) {
            throw new \RuntimeException('Missing bundle code for SAP bundle sync');
        }

        $components = $this->extractBundleComponents($data);
        if ($components === []) {
            return ['status' => 'ignored', 'bundle_code' => (string) $bundleCode];
        }

        $this->ensureBundleParentItemExists((string) $bundleCode, $data);

        foreach ($components as $index => $component) {
            $this->ensureItemExists(
                (string) $component['item_code'],
                ['sku_code' => $component['item_code'], 'name' => $component['item_code']],
                $index + 1
            );
        }

        $treeItems = array_map(fn ($component) => [
            'ItemCode' => (string) $component['item_code'],
            'Quantity' => (float) $component['quantity'],
            'Warehouse' => (string) ($component['warehouse'] ?? ''),
        ], $components);

        $existing = $this->getProductTree((string) $bundleCode);
        $isUpdate = str_contains(strtolower($eventName), 'update');
        $isDelete = str_contains(strtolower($eventName), 'delete');

        if ($existing) {
            if ($isDelete) {
                $this->deleteProductTree((string) $bundleCode);
                return ['status' => 'deleted', 'bundle_code' => (string) $bundleCode];
            }

            $this->updateProductTree((string) $bundleCode, $treeItems);
            return ['status' => 'updated', 'bundle_code' => (string) $bundleCode];
        }

        if ($isDelete) {
            return ['status' => 'skipped', 'bundle_code' => (string) $bundleCode];
        }

        $this->createProductTree((string) $bundleCode, $treeItems);
        return ['status' => $isUpdate ? 'created' : 'created', 'bundle_code' => (string) $bundleCode];
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

    /**
     * @return array<int,array{item_code:string,quantity:float,warehouse:?string}>
     */
    private function extractBundleComponents(array $data): array
    {
        $sources = [
            data_get($data, 'bundle_items', []),
            data_get($data, 'bundle_components', []),
            data_get($data, 'components', []),
            data_get($data, 'bom_items', []),
            data_get($data, 'kit_items', []),
        ];

        $components = [];
        foreach ($sources as $source) {
            foreach ((array) $source as $row) {
                $itemCode = data_get($row, 'item_code')
                    ?? data_get($row, 'sku_code')
                    ?? data_get($row, 'seller_sku_code')
                    ?? data_get($row, 'seller_sku.seller_sku_code');
                if (!$itemCode) {
                    continue;
                }

                $qty = (float) (data_get($row, 'quantity') ?? data_get($row, 'qty') ?? 0);
                if ($qty <= 0) {
                    continue;
                }

                $components[] = [
                    'item_code' => (string) $itemCode,
                    'quantity' => $qty,
                    'warehouse' => data_get($row, 'hub_code') ?? data_get($row, 'warehouse') ?? null,
                ];
            }

            if ($components !== []) {
                break;
            }
        }

        return $components;
    }

    private function ensureBundleParentItemExists(string $bundleCode, array $data): void
    {
        if ($this->isValidItem($bundleCode)) {
            return;
        }

        $name = (string) (data_get($data, 'name') ?? data_get($data, 'bundle_name') ?? $bundleCode);
        $body = [
            'ItemCode' => $bundleCode,
            'ItemName' => $name,
            'InventoryItem' => 'tNO',
            'PurchaseItem' => 'tNO',
            'SalesItem' => 'tYES',
        ];

        $response = $this->post('/Items', $body);
        if (!$response->successful()) {
            throw new \RuntimeException('SAP bundle item create failed: ' . $response->status() . ' ' . $response->body());
        }
    }

    private function getProductTree(string $treeCode): ?array
    {
        $encoded = str_replace("'", "''", $treeCode);
        $response = $this->get("/ProductTrees('{$encoded}')");

        if ($response->status() === 404) {
            return null;
        }

        if (!$response->successful()) {
            throw new \RuntimeException('SAP product tree lookup failed: ' . $response->status() . ' ' . $response->body());
        }

        return $response->json() ?? null;
    }

    /**
     * @param array<int,array{ItemCode:string,Quantity:float,Warehouse:string}> $items
     */
    private function createProductTree(string $treeCode, array $items): void
    {
        $treeItems = array_map(fn ($item) => array_filter([
            'ItemCode' => $item['ItemCode'],
            'Quantity' => $item['Quantity'],
            'Warehouse' => $item['Warehouse'] ?: null,
        ], fn ($value) => $value !== null), $items);

        $body = [
            'TreeCode' => $treeCode,
            'TreeType' => 'iSalesTree',
            'ProductTreeLines' => $treeItems,
        ];

        $response = $this->post('/ProductTrees', $body);
        if (!$response->successful()) {
            throw new \RuntimeException('SAP product tree create failed: ' . $response->status() . ' ' . $response->body());
        }
    }

    /**
     * @param array<int,array{ItemCode:string,Quantity:float,Warehouse:string}> $items
     */
    private function updateProductTree(string $treeCode, array $items): void
    {
        $treeItems = array_map(fn ($item) => array_filter([
            'ItemCode' => $item['ItemCode'],
            'Quantity' => $item['Quantity'],
            'Warehouse' => $item['Warehouse'] ?: null,
        ], fn ($value) => $value !== null), $items);

        $encoded = str_replace("'", "''", $treeCode);
        $body = [
            'TreeType' => 'iSalesTree',
            'ProductTreeLines' => $treeItems,
        ];

        $response = $this->patch("/ProductTrees('{$encoded}')", $body);
        if (!$response->successful()) {
            throw new \RuntimeException('SAP product tree update failed: ' . $response->status() . ' ' . $response->body());
        }
    }

    private function deleteProductTree(string $treeCode): void
    {
        $encoded = str_replace("'", "''", $treeCode);
        $response = $this->delete("/ProductTrees('{$encoded}')");
        if ($response->status() === 404) {
            return;
        }
        if (!$response->successful()) {
            throw new \RuntimeException('SAP product tree delete failed: ' . $response->status() . ' ' . $response->body());
        }
    }

    public function isItemIntegrationEnabled(string $itemCode): bool
    {
        $udfField = trim((string) config('omniful.integration_control.item_udf_field', ''));
        if ($udfField === '') {
            return true;
        }

        $allowedValues = (array) config('omniful.integration_control.item_allowed_values', ['y', 'yes', 'true', '1', 'enabled']);
        $allowed = array_values(array_filter(array_map(
            fn ($v) => strtolower(trim((string) $v)),
            $allowedValues
        ), fn ($v) => $v !== ''));

        if ($allowed === []) {
            return true;
        }

        $encodedCode = str_replace("'", "''", $itemCode);
        $encodedField = str_replace("'", "''", $udfField);
        $response = $this->get("/Items('{$encodedCode}')?\$select=ItemCode,{$encodedField}");

        if ($response->status() === 404) {
            return true;
        }

        if (!$response->successful()) {
            throw new \RuntimeException('SAP item integration-udf lookup failed: ' . $response->status() . ' ' . $response->body());
        }

        $payload = $response->json() ?? [];
        $value = strtolower(trim((string) ($payload[$udfField] ?? '')));
        if ($value === '') {
            return true;
        }

        return in_array($value, $allowed, true);
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

    private function getCreditNote(int $docEntry): array
    {
        $response = $this->get('/CreditNotes(' . $docEntry . ')');

        if (!$response->successful()) {
            throw new \RuntimeException('SAP credit memo fetch failed: ' . $response->status() . ' ' . $response->body());
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

    private function findSupplierByPhone(string $phone): ?string
    {
        $target = $this->normalizePhone($phone);
        if ($target === '') {
            return null;
        }

        foreach ($this->fetchSuppliers() as $supplier) {
            $candidate = (string) ($supplier['Phone1'] ?? '');
            if ($candidate === '') {
                continue;
            }

            if ($this->normalizePhone($candidate) === $target) {
                $cardCode = (string) ($supplier['CardCode'] ?? '');
                if ($cardCode !== '') {
                    return $cardCode;
                }
            }
        }

        return null;
    }

    private function normalizePhone(string $value): string
    {
        return preg_replace('/\D+/', '', $value) ?? '';
    }

    private function isSapMobileDuplicationError(string $body): bool
    {
        $normalized = strtolower($body);

        return str_contains($normalized, '"code" : -1116')
            || str_contains($normalized, '"code":-1116')
            || str_contains($normalized, '"code": -1116')
            || str_contains($normalized, 'mobile no. duplication')
            || str_contains($normalized, 'mobile number related customer already available');
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

    private function extractCreditMemoCogsAmount(array $creditMemo): float
    {
        $total = 0.0;
        foreach ((array) ($creditMemo['DocumentLines'] ?? []) as $line) {
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

    /**
     * @return array<int,array{item_code:string,quantity:float,unit_price:float}>
     */
    private function buildReturnCreditLinesFromPayload(array $data): array
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

            $unitPrice = data_get($item, 'unit_price');
            if ($unitPrice === null) {
                $unitPrice = data_get($item, 'price');
            }

            $lines[] = [
                'item_code' => (string) $itemCode,
                'quantity' => $qty,
                'unit_price' => (float) ($unitPrice ?? 0),
            ];
        }

        return $lines;
    }

    /**
     * @param array<int,array{item_code:string,quantity:float,unit_price:float}> $items
     * @return array<int,array<string,mixed>>
     */
    private function buildCreditLinesFromDelivery(array $items, array $delivery, string $hubCode, int $deliveryDocEntry): array
    {
        $deliveryByItem = [];
        foreach ((array) ($delivery['DocumentLines'] ?? []) as $line) {
            $itemCode = (string) ($line['ItemCode'] ?? '');
            $lineNum = $line['LineNum'] ?? null;
            if ($itemCode === '' || !is_numeric($lineNum)) {
                continue;
            }

            $lineQty = (float) ($line['Quantity'] ?? 0);
            if ($lineQty <= 0) {
                continue;
            }

            $deliveryByItem[$itemCode][] = [
                'line_num' => (int) $lineNum,
                'open_qty' => $lineQty,
                'warehouse' => (string) ($line['WarehouseCode'] ?? ''),
            ];
        }

        $creditLines = [];
        foreach ($items as $item) {
            $itemCode = $item['item_code'];
            $remaining = (float) $item['quantity'];
            if (!isset($deliveryByItem[$itemCode]) || $remaining <= 0) {
                continue;
            }

            foreach ($deliveryByItem[$itemCode] as &$deliveryLine) {
                if ($remaining <= 0) {
                    break;
                }

                if ($hubCode !== '' && $deliveryLine['warehouse'] !== '' && $deliveryLine['warehouse'] !== $hubCode) {
                    continue;
                }

                $openQty = (float) $deliveryLine['open_qty'];
                if ($openQty <= 0) {
                    continue;
                }

                $applyQty = min($remaining, $openQty);
                if ($applyQty <= 0) {
                    continue;
                }

                $line = [
                    'BaseType' => 15,
                    'BaseEntry' => $deliveryDocEntry,
                    'BaseLine' => (int) $deliveryLine['line_num'],
                    'Quantity' => $applyQty,
                ];

                if ($hubCode !== '') {
                    $line['WarehouseCode'] = $hubCode;
                }

                $creditLines[] = $line;
                $deliveryLine['open_qty'] = max(0.0, $openQty - $applyQty);
                $remaining -= $applyQty;
            }
            unset($deliveryLine);
        }

        return $creditLines;
    }

    /**
     * @param array<int,array{item_code:string,quantity:float,unit_price:float}> $items
     * @return array<int,array<string,mixed>>
     */
    private function buildDirectCreditLines(array $items, string $hubCode): array
    {
        $lines = [];
        $lineIndex = 0;
        foreach ($items as $item) {
            $lineIndex++;
            $itemCode = $item['item_code'];
            $qty = (float) $item['quantity'];
            if ($itemCode === '' || $qty <= 0) {
                continue;
            }

            $this->ensureItemExists($itemCode, [
                'sku_code' => $itemCode,
                'unit_price' => $item['unit_price'],
            ], $lineIndex);

            $line = [
                'ItemCode' => $itemCode,
                'Quantity' => $qty,
                'UnitPrice' => (float) $item['unit_price'],
            ];

            if ($hubCode !== '') {
                $this->ensureWarehouseExists($hubCode, $lineIndex);
                $line['WarehouseCode'] = $hubCode;
            }

            $lines[] = $line;
        }

        return $lines;
    }

}

