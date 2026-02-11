<?php

namespace App\Services\Sap\Concerns;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

trait HandlesSapPurchaseAndProducts
{
    public function createArReserveInvoiceFromOmnifulOrder(array $data, string $externalId): array
    {
        $docDate = $this->formatDate((string) (data_get($data, 'order_created_at') ?? data_get($data, 'created_at') ?? null));
        $currency = data_get($data, 'invoice.currency') ?? data_get($data, 'currency');
        $hubCode = data_get($data, 'hub_code');
        $preferredSeries = $this->getPreferredSeriesId('17');
        $seriesInfo = $preferredSeries !== null
            ? ['series' => $preferredSeries, 'docDate' => $docDate, 'indicator' => 'preferred']
            : $this->resolveSeriesForDocument('17', $docDate);
        $docDate = $seriesInfo['docDate'];

        $customerCode = data_get($data, 'customer.code');
        if (!$customerCode) {
            $customerCode = $this->buildCustomerCode($data, $externalId);
        }
        $customerCode = $this->ensureCustomerExists((string) $customerCode, $data, $externalId);

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
            $this->ensureItemCanBeSold((string) $itemCode, $lineIndex);

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
                $line['WarehouseCode'] = $this->ensureWarehouseExists((string) $hubCode, $lineIndex);
            }

            $lines[] = $line;
        }

        if ($lines === []) {
            return [
                'ignored' => true,
                'reason' => 'No order lines found for AR reserve invoice',
            ];
        }
        $lines = $this->applyDefaultCostCentersToLines($lines);

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

        $usedReserveInvoiceFallback = false;
        $response = $this->postArOrderWithReserveFallback($body, $usedReserveInvoiceFallback);
        if (!$response->successful() && $this->isSapSeriesPeriodMismatchError((string) $response->body())) {
            $response = $this->retryArOrderWithDynamicSeries($body, $docDate, $usedReserveInvoiceFallback);
        }
        if (!$response->successful() && $this->isSapUomCodeRequiredError((string) $response->body())) {
            $response = $this->retryArOrderWithResolvedUom($body, $usedReserveInvoiceFallback);
        }

        if (!$response->successful()) {
            throw new \RuntimeException('SAP AR reserve invoice create failed: ' . $response->status() . ' ' . $response->body());
        }

        $payload = $response->json() ?? [];
        $payload['ignored'] = false;
        if ($usedReserveInvoiceFallback) {
            $payload['reserve_invoice_fallback'] = true;
        }

        return $payload;
    }

    private function postArOrderWithReserveFallback(array $body, bool &$usedReserveInvoiceFallback)
    {
        $response = $this->post('/Orders', $body);
        if (
            !$response->successful()
            && str_contains((string) $response->body(), 'This field is not supported in this document [OINV.isIns]')
            && array_key_exists('ReserveInvoice', $body)
        ) {
            // Some SAP B1 setups reject ReserveInvoice on /Orders. Retry without it to keep order flow alive.
            $fallbackBody = $body;
            unset($fallbackBody['ReserveInvoice']);
            $response = $this->post('/Orders', $fallbackBody);
            $usedReserveInvoiceFallback = $response->successful() || $usedReserveInvoiceFallback;
        }

        return $response;
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

        $journalLines = $this->applyDefaultCostCentersToJournalLines([
            [
                'AccountCode' => $expenseAccount,
                'Debit' => $amount,
            ],
            [
                'AccountCode' => $offsetAccount,
                'Credit' => $amount,
            ],
        ]);

        $body = [
            'ReferenceDate' => $referenceDate,
            'DueDate' => $referenceDate,
            'TaxDate' => $referenceDate,
            'Memo' => $memo,
            'JournalEntryLines' => $journalLines,
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
                $deliveryLine['WarehouseCode'] = $this->ensureWarehouseExists($hubCode, ((int) $lineNum) + 1);
            }

            $lines[] = $deliveryLine;
        }

        if ($lines === []) {
            return [
                'ignored' => true,
                'reason' => 'No open quantity found for delivery',
            ];
        }
        $lines = $this->applyDefaultCostCentersToLines($lines);

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

        $journalLines = $this->applyDefaultCostCentersToJournalLines([
            [
                'AccountCode' => $expenseAccount,
                'Debit' => $amount,
            ],
            [
                'AccountCode' => $offsetAccount,
                'Credit' => $amount,
            ],
        ]);

        $body = [
            'ReferenceDate' => $referenceDate,
            'DueDate' => $referenceDate,
            'TaxDate' => $referenceDate,
            'Memo' => $memo,
            'JournalEntryLines' => $journalLines,
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
            $cardCode = $this->ensureCustomerExists($cardCode, $data, $externalId !== '' ? $externalId : $cardCode);
        }

        if ($documentLines === []) {
            return [
                'ignored' => true,
                'reason' => 'No SAP credit memo lines could be built',
            ];
        }
        $documentLines = $this->applyDefaultCostCentersToLines($documentLines);

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

        $journalLines = $this->applyDefaultCostCentersToJournalLines([
            [
                'AccountCode' => $offsetAccount,
                'Debit' => $amount,
            ],
            [
                'AccountCode' => $expenseAccount,
                'Credit' => $amount,
            ],
        ]);

        $body = [
            'ReferenceDate' => $referenceDate,
            'DueDate' => $referenceDate,
            'TaxDate' => $referenceDate,
            'Memo' => $memo,
            'JournalEntryLines' => $journalLines,
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
        $preferredSeries = $this->getPreferredSeriesId('22');
        $seriesInfo = $preferredSeries !== null
            ? ['series' => $preferredSeries, 'docDate' => $docDate, 'indicator' => 'preferred']
            : $this->resolveSeriesForDocument('22', $docDate);
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
                $line['WarehouseCode'] = $this->ensureWarehouseExists($hubCode, $lineIndex);
            }

            $lines[] = $line;
        }

        if ($lines === []) {
            throw new \RuntimeException('No purchase_order_items found for SAP PO');
        }
        $lines = $this->applyDefaultCostCentersToLines($lines);

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
        if (!$response->successful() && $this->isSapSeriesPeriodMismatchError($response->body())) {
            $response = $this->retryPurchaseOrderWithDynamicSeries($body, $docDate);
        }
        if (!$response->successful() && $this->isSapUomCodeRequiredError($response->body())) {
            $response = $this->retryPurchaseOrderWithResolvedUom($body);
        }

        if (!$response->successful()) {
            throw new \RuntimeException('SAP PO create failed: ' . $response->status() . ' ' . $response->body());
        }

        if (isset($body['Series']) && is_numeric($body['Series'])) {
            $this->rememberPreferredSeriesId('22', (int) $body['Series']);
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
        $phone = $this->extractSupplierPhone($data);
        $email = data_get($supplier, 'email');

        if ($phone !== '') {
            $existingByPhone = $this->findBusinessPartnerByPhone($phone, null);
            if ($existingByPhone) {
                $existingCode = (string) ($existingByPhone['CardCode'] ?? '');
                $existingType = $this->normalizeSapCardType((string) ($existingByPhone['CardType'] ?? ''));
                if ($existingCode !== '') {
                    if ($existingType === 'S') {
                        return $existingCode;
                    }

                    if ($this->ensureBusinessPartnerIsSupplier($existingCode, $cardName, $email)) {
                        return $existingCode;
                    }
                }
            }
        }

        $body = [
            'CardCode' => $cardCode,
            'CardName' => $cardName,
            'CardType' => 'S',
        ];

        if ($email) {
            $body['EmailAddress'] = $email;
        }

        $response = $this->post('/BusinessPartners', $body);
        if ($response->successful()) {
            return $cardCode;
        }

        if ($this->isSapMobileDuplicationError($response->body())) {
            $existingCode = $this->resolveExistingSupplierCodeForDuplication($phone, (string) $cardName, $email);
            if ($existingCode !== null) {
                return $existingCode;
            }
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
            'ItemType' => (string) config('omniful.sap_item_defaults.item_type', 'itItems'),
            'InventoryItem' => 'tYES',
            'PurchaseItem' => 'tYES',
            'SalesItem' => 'tNO',
        ];
        $this->applyItemTypeUdfDefaults($body);

        $response = $this->post('/Items', $body);
        if (!$response->successful() && $this->isSapItemTypeRequiredError($response->body())) {
            $retryBody = $body;
            $retryBody['ItemType'] = (int) config('omniful.sap_item_defaults.item_type_numeric_fallback', 0);
            $this->applyItemTypeUdfDefaults($retryBody);
            $retry = $this->post('/Items', $retryBody);
            if ($retry->successful()) {
                return true;
            }

            throw new \RuntimeException('SAP item create failed for line ' . $lineIndex . ' (' . $itemCode . ') [retry]: ' . $retry->status() . ' ' . $retry->body());
        }

        if (!$response->successful()) {
            throw new \RuntimeException('SAP item create failed for line ' . $lineIndex . ' (' . $itemCode . '): ' . $response->status() . ' ' . $response->body());
        }

        return true;
    }

    private function ensureItemCanBeSold(string $itemCode, int $lineIndex): void
    {
        $encoded = str_replace("'", "''", $itemCode);
        $response = $this->get("/Items('{$encoded}')?\$select=ItemCode,SalesItem");

        if (!$response->successful()) {
            throw new \RuntimeException('SAP item sales-check failed for line ' . $lineIndex . ' (' . $itemCode . '): ' . $response->status() . ' ' . $response->body());
        }

        $salesItem = strtolower((string) ($response->json()['SalesItem'] ?? ''));
        if ($salesItem === 'tyes') {
            return;
        }

        $patch = $this->patch("/Items('{$encoded}')", [
            'SalesItem' => 'tYES',
        ]);

        if (!$patch->successful()) {
            throw new \RuntimeException('SAP item sales-enable failed for line ' . $lineIndex . ' (' . $itemCode . '): ' . $patch->status() . ' ' . $patch->body());
        }
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

        if ($this->isSapMobileDuplicationError($response->body())) {
            $existingCode = $this->resolveExistingSupplierCodeForDuplication((string) ($phone ?? ''), $cardName, $email);
            if ($existingCode !== null) {
                return ['status' => 'linked_existing_by_phone', 'supplier_code' => $existingCode];
            }
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
            'ItemType' => (string) config('omniful.sap_item_defaults.item_type', 'itItems'),
            'InventoryItem' => 'tYES',
            'PurchaseItem' => 'tYES',
            'SalesItem' => 'tNO',
        ];
        $this->applyItemTypeUdfDefaults($body);

        $response = $this->post('/Items', $body);
        if (!$response->successful() && $this->isSapItemTypeRequiredError($response->body())) {
            $retryBody = $body;
            $retryBody['ItemType'] = (int) config('omniful.sap_item_defaults.item_type_numeric_fallback', 0);
            $this->applyItemTypeUdfDefaults($retryBody);
            $retry = $this->post('/Items', $retryBody);
            if ($retry->successful()) {
                return;
            }
            throw new \RuntimeException('SAP item create failed [retry]: ' . $retry->status() . ' ' . $retry->body());
        }

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
        $lines = $this->applyDefaultCostCentersToLines($lines);

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
            'ItemType' => (string) config('omniful.sap_item_defaults.item_type', 'itItems'),
            'InventoryItem' => 'tNO',
            'PurchaseItem' => 'tNO',
            'SalesItem' => 'tYES',
        ];
        $this->applyItemTypeUdfDefaults($body);

        $response = $this->post('/Items', $body);
        if (!$response->successful() && $this->isSapItemTypeRequiredError($response->body())) {
            $retryBody = $body;
            $retryBody['ItemType'] = (int) config('omniful.sap_item_defaults.item_type_numeric_fallback', 0);
            $this->applyItemTypeUdfDefaults($retryBody);
            $retry = $this->post('/Items', $retryBody);
            if ($retry->successful()) {
                return;
            }
            throw new \RuntimeException('SAP bundle item create failed [retry]: ' . $retry->status() . ' ' . $retry->body());
        }
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

    private function ensureCustomerExists(string $cardCode, array $data, string $externalId): string
    {
        $existing = $this->getBusinessPartner($cardCode);
        if ($existing) {
            return $cardCode;
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
        $phone = $this->extractCustomerPhone($data);

        $resolvedByIdentity = $this->resolveExistingCustomerCodeForDuplication((string) ($phone ?? ''), $fullName, $email);
        if ($resolvedByIdentity !== null) {
            return $resolvedByIdentity;
        }

        if ($email) {
            $body['EmailAddress'] = (string) $email;
        }
        $phoneValue = trim((string) ($phone ?? ''));
        if ($phoneValue === '') {
            $phoneValue = $this->buildFallbackCustomerPhone($cardCode, $externalId);
        }
        $phoneValue = $this->normalizePhoneForSap($phoneValue);
        $body['Phone1'] = $phoneValue;
        $body['Cellular'] = $phoneValue;

        $response = $this->post('/BusinessPartners', $body);
        if ($response->successful()) {
            return $cardCode;
        }

        if ($this->isSapMobileDuplicationError($response->body())) {
            $resolvedCode = $this->resolveExistingCustomerCodeForDuplication((string) ($phone ?? ''), $fullName, $email);
            if ($resolvedCode !== null) {
                return $resolvedCode;
            }

            // If source payload has no phone, retry create with alternate generated phone values.
            if (trim((string) ($phone ?? '')) === '') {
                for ($attempt = 1; $attempt <= 3; $attempt++) {
                    $retryBody = $body;
                    $retryPhone = $this->normalizePhoneForSap($this->buildFallbackCustomerPhone($cardCode, $externalId, $attempt));
                    $retryBody['Phone1'] = $retryPhone;
                    $retryBody['Cellular'] = $retryPhone;
                    $retry = $this->post('/BusinessPartners', $retryBody);
                    if ($retry->successful()) {
                        return $cardCode;
                    }

                    if (!$this->isSapMobileDuplicationError($retry->body())) {
                        throw new \RuntimeException('SAP customer create failed: ' . $retry->status() . ' ' . $retry->body());
                    }
                }
            }
        }

        $fallbackCustomerCode = $this->resolveConfiguredFallbackCustomerCode($fullName, $email);
        if ($fallbackCustomerCode !== null) {
            return $fallbackCustomerCode;
        }

        throw new \RuntimeException('SAP customer create failed: ' . $response->status() . ' ' . $response->body());
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

    private function buildFallbackCustomerPhone(string $cardCode, string $externalId, int $attempt = 0): string
    {
        $seed = $cardCode !== '' ? $cardCode : $externalId;
        if ($seed === '') {
            $seed = (string) now()->timestamp;
        }
        if ($attempt > 0) {
            $seed .= '-' . $attempt . '-' . now()->format('Hisv');
        }

        // Build deterministic 10-digit phone-like value to avoid SAP duplicate-null mobile issue.
        $digits = preg_replace('/\D+/', '', (string) sprintf('%u', crc32($seed))) ?? '';
        $digits = str_pad(substr($digits, 0, 9), 9, '0', STR_PAD_LEFT);
        return '9' . $digits;
    }

    private function extractCustomerPhone(array $data): string
    {
        $candidates = [
            data_get($data, 'customer.phone'),
            data_get($data, 'customer.mobile'),
            data_get($data, 'billing_address.phone'),
            data_get($data, 'shipping_address.phone'),
            data_get($data, 'phone'),
            data_get($data, 'mobile'),
        ];

        foreach ($candidates as $value) {
            $v = trim((string) ($value ?? ''));
            if ($v !== '') {
                return $v;
            }
        }

        return '';
    }

    private function normalizePhoneForSap(string $phone): string
    {
        $raw = trim($phone);
        if ($raw === '') {
            return '';
        }

        $digits = $this->normalizePhone($raw);
        if ($digits === '') {
            return $raw;
        }

        // KSA local mobile format expected by many SAP B1 setups: 05XXXXXXXX.
        if (str_starts_with($digits, '966') && strlen($digits) >= 12) {
            $local = substr($digits, 3);
            if (str_starts_with($local, '5') && strlen($local) >= 9) {
                return '0' . substr($local, 0, 9);
            }
            return substr($local, 0, 10);
        }

        if (str_starts_with($digits, '5') && strlen($digits) === 9) {
            return '0' . $digits;
        }

        if (str_starts_with($digits, '05') && strlen($digits) >= 10) {
            return substr($digits, 0, 10);
        }

        return $digits;
    }

    /**
     * @return array<string,mixed>|null
     */
    private function findBusinessPartnerByPhone(string $phone, ?string $cardType = 'S'): ?array
    {
        $target = $this->normalizePhone($phone);
        if ($target === '') {
            return null;
        }

        $raw = trim($phone);
        $candidates = [
            $raw,
            $target,
            '+' . ltrim($target, '+'),
            '0' . ltrim($target, '0'),
        ];

        // Common KSA fallback variants: 966xxxxxxxxx <-> 0xxxxxxxxx
        if (str_starts_with($target, '966') && strlen($target) > 9) {
            $local = substr($target, 3);
            $candidates[] = $local;
            $candidates[] = '0' . ltrim($local, '0');
            $candidates[] = '+966' . ltrim($local, '0');
        }

        if (strlen($target) >= 9) {
            $last9 = substr($target, -9);
            $candidates[] = $last9;
            $candidates[] = '0' . ltrim($last9, '0');
        }

        $candidates = array_values(array_unique(array_filter($candidates, fn ($v) => is_string($v) && trim($v) !== '')));

        foreach ($candidates as $candidate) {
            $escaped = str_replace("'", "''", $candidate);
            $typeFilter = $cardType ? "CardType eq '" . str_replace("'", "''", $cardType) . "' and " : '';
            $filter = rawurlencode($typeFilter . "(Phone1 eq '{$escaped}' or Cellular eq '{$escaped}' or Phone2 eq '{$escaped}')");
            $path = "/BusinessPartners?\$select=CardCode,CardType,Phone1,Cellular,Phone2&\$filter={$filter}";
            $response = $this->get($path);

            if (!$response->successful()) {
                continue;
            }

            $rows = (array) ($response->json('value') ?? []);
            foreach ($rows as $bp) {
                $cardCode = (string) ($bp['CardCode'] ?? '');
                if ($cardCode !== '') {
                    return $bp;
                }
            }
        }

        return null;
    }

    /**
     * @return array<string,mixed>|null
     */
    private function findBusinessPartnerByName(string $cardName, ?string $cardType = 'S'): ?array
    {
        $name = trim($cardName);
        if ($name === '') {
            return null;
        }

        $escaped = str_replace("'", "''", $name);
        $typeFilter = $cardType ? "CardType eq '" . str_replace("'", "''", $cardType) . "' and " : '';
        $filter = rawurlencode($typeFilter . "CardName eq '{$escaped}'");
        $path = "/BusinessPartners?\$select=CardCode,CardType,CardName,Phone1,Cellular,Phone2&\$filter={$filter}&\$top=1";
        $response = $this->get($path);

        if (!$response->successful()) {
            return null;
        }

        $rows = (array) ($response->json('value') ?? []);
        return $rows[0] ?? null;
    }

    private function resolveExistingSupplierCodeForDuplication(string $phone, string $cardName, mixed $email): ?string
    {
        if ($phone !== '') {
            $existingByPhone = $this->findBusinessPartnerByPhone($phone, null);
            if ($existingByPhone) {
                $existingCode = (string) ($existingByPhone['CardCode'] ?? '');
                $existingType = $this->normalizeSapCardType((string) ($existingByPhone['CardType'] ?? ''));
                if ($existingCode !== '') {
                    if ($existingType === 'S') {
                        return $existingCode;
                    }
                    if ($this->ensureBusinessPartnerIsSupplier($existingCode, $cardName, $email)) {
                        return $existingCode;
                    }
                }
            }
        }

        if ($cardName !== '') {
            $existingByName = $this->findBusinessPartnerByName($cardName, null);
            if ($existingByName) {
                $existingCode = (string) ($existingByName['CardCode'] ?? '');
                $existingType = $this->normalizeSapCardType((string) ($existingByName['CardType'] ?? ''));
                if ($existingCode !== '') {
                    if ($existingType === 'S') {
                        return $existingCode;
                    }
                    if ($this->ensureBusinessPartnerIsSupplier($existingCode, $cardName, $email)) {
                        return $existingCode;
                    }
                }
            }
        }

        return null;
    }

    private function resolveExistingCustomerCodeForDuplication(string $phone, string $cardName, mixed $email): ?string
    {
        $emailValue = trim((string) ($email ?? ''));
        if ($emailValue !== '') {
            $existingByEmail = $this->findBusinessPartnerByEmail($emailValue, null);
            if ($existingByEmail) {
                $existingCode = (string) ($existingByEmail['CardCode'] ?? '');
                $existingType = $this->normalizeSapCardType((string) ($existingByEmail['CardType'] ?? ''));
                if ($existingCode !== '') {
                    if ($existingType === 'C') {
                        return $existingCode;
                    }
                    if ($this->ensureBusinessPartnerIsCustomer($existingCode, $cardName, $email)) {
                        return $existingCode;
                    }
                }
            }
        }

        if ($phone !== '') {
            $existingByPhone = $this->findBusinessPartnerByPhone($phone, null);
            if ($existingByPhone) {
                $existingCode = (string) ($existingByPhone['CardCode'] ?? '');
                $existingType = $this->normalizeSapCardType((string) ($existingByPhone['CardType'] ?? ''));
                if ($existingCode !== '') {
                    if ($existingType === 'C') {
                        return $existingCode;
                    }
                    if ($this->ensureBusinessPartnerIsCustomer($existingCode, $cardName, $email)) {
                        return $existingCode;
                    }
                }
            }
        }

        if ($cardName !== '') {
            $existingByName = $this->findBusinessPartnerByName($cardName, null);
            if ($existingByName) {
                $existingCode = (string) ($existingByName['CardCode'] ?? '');
                $existingType = $this->normalizeSapCardType((string) ($existingByName['CardType'] ?? ''));
                if ($existingCode !== '') {
                    if ($existingType === 'C') {
                        return $existingCode;
                    }
                    if ($this->ensureBusinessPartnerIsCustomer($existingCode, $cardName, $email)) {
                        return $existingCode;
                    }
                }
            }
        }

        $existingByLooseScan = $this->findCustomerByLooseScan($phone, (string) ($email ?? ''), $cardName);
        if ($existingByLooseScan) {
            $existingCode = (string) ($existingByLooseScan['CardCode'] ?? '');
            $existingType = $this->normalizeSapCardType((string) ($existingByLooseScan['CardType'] ?? ''));
            if ($existingCode !== '') {
                if ($existingType === 'C') {
                    return $existingCode;
                }
                if ($this->ensureBusinessPartnerIsCustomer($existingCode, $cardName, $email)) {
                    return $existingCode;
                }
            }
        }

        return null;
    }

    /**
     * Best-effort scan when exact OData eq filters miss due formatting/case differences.
     * @return array<string,mixed>|null
     */
    private function findCustomerByLooseScan(string $phone, string $email, string $cardName): ?array
    {
        $targetPhone = $this->normalizePhone($phone);
        $targetEmail = strtolower(trim($email));
        $targetName = strtolower(trim($cardName));

        $skip = 0;
        $top = 200;
        $maxRows = 2000;
        $seen = 0;

        while ($seen < $maxRows) {
            $path = "/BusinessPartners?\$select=CardCode,CardType,CardName,EmailAddress,Phone1,Cellular,Phone2&\$top={$top}&\$skip={$skip}";
            $response = $this->get($path);
            if (!$response->successful()) {
                return null;
            }

            $rows = (array) ($response->json('value') ?? []);
            if ($rows === []) {
                return null;
            }

            foreach ($rows as $row) {
                $seen++;
                $rowType = $this->normalizeSapCardType((string) ($row['CardType'] ?? ''));
                if (!in_array($rowType, ['C', 'S', 'L'], true)) {
                    continue;
                }
                $rowEmail = strtolower(trim((string) ($row['EmailAddress'] ?? '')));
                $rowName = strtolower(trim((string) ($row['CardName'] ?? '')));

                if ($targetEmail !== '' && $rowEmail !== '' && $rowEmail === $targetEmail) {
                    return $row;
                }

                if ($targetPhone !== '') {
                    $phones = [
                        $this->normalizePhone((string) ($row['Phone1'] ?? '')),
                        $this->normalizePhone((string) ($row['Cellular'] ?? '')),
                        $this->normalizePhone((string) ($row['Phone2'] ?? '')),
                    ];
                    foreach ($phones as $p) {
                        if ($p === '') {
                            continue;
                        }

                        if ($p === $targetPhone) {
                            return $row;
                        }

                        // Relaxed KSA/local matching by last 9 digits.
                        if (strlen($p) >= 9 && strlen($targetPhone) >= 9) {
                            if (substr($p, -9) === substr($targetPhone, -9)) {
                                return $row;
                            }
                        }
                    }
                }

                if ($targetName !== '' && $rowName !== '' && $rowName === $targetName) {
                    return $row;
                }
            }

            if (count($rows) < $top) {
                return null;
            }
            $skip += $top;
        }

        return null;
    }

    /**
     * @return array<string,mixed>|null
     */
    private function findBusinessPartnerByEmail(string $email, ?string $cardType = 'C'): ?array
    {
        $email = trim($email);
        if ($email === '') {
            return null;
        }

        $escaped = str_replace("'", "''", $email);
        $typeFilter = $cardType ? "CardType eq '" . str_replace("'", "''", $cardType) . "' and " : '';
        $filter = rawurlencode($typeFilter . "EmailAddress eq '{$escaped}'");
        $path = "/BusinessPartners?\$select=CardCode,CardType,CardName,EmailAddress,Phone1,Cellular,Phone2&\$filter={$filter}&\$top=1";
        $response = $this->get($path);

        if (!$response->successful()) {
            return null;
        }

        $rows = (array) ($response->json('value') ?? []);
        return $rows[0] ?? null;
    }

    private function extractSupplierPhone(array $data): string
    {
        $candidates = [
            data_get($data, 'supplier.phone'),
            data_get($data, 'supplier.mobile'),
            data_get($data, 'supplier.phone_number'),
            data_get($data, 'supplier.contact.phone'),
            data_get($data, 'supplier.contact.mobile'),
            data_get($data, 'phone'),
            data_get($data, 'phone_number'),
            data_get($data, 'mobile'),
        ];

        foreach ($candidates as $value) {
            $v = trim((string) ($value ?? ''));
            if ($v !== '') {
                return $v;
            }
        }

        return '';
    }

    private function ensureBusinessPartnerIsSupplier(string $cardCode, string $cardName, mixed $email): bool
    {
        $bp = $this->getBusinessPartner($cardCode);
        if (!$bp) {
            return false;
        }

        $currentType = $this->normalizeSapCardType((string) ($bp['CardType'] ?? ''));
        if ($currentType === 'S') {
            return true;
        }

        $patch = [
            'CardType' => 'S',
            'CardName' => $cardName ?: (string) ($bp['CardName'] ?? $cardCode),
        ];

        if ($email) {
            $patch['EmailAddress'] = (string) $email;
        }

        $encoded = str_replace("'", "''", $cardCode);
        $response = $this->patch("/BusinessPartners('{$encoded}')", $patch);
        if ($response->successful()) {
            return true;
        }

        Log::warning('Failed to convert BusinessPartner to supplier', [
            'card_code' => $cardCode,
            'status' => $response->status(),
            'body' => $response->body(),
        ]);

        return false;
    }

    private function ensureBusinessPartnerIsCustomer(string $cardCode, string $cardName, mixed $email): bool
    {
        $bp = $this->getBusinessPartner($cardCode);
        if (!$bp) {
            return false;
        }

        $currentType = $this->normalizeSapCardType((string) ($bp['CardType'] ?? ''));
        if ($currentType === 'C') {
            return true;
        }

        $patch = [
            'CardType' => 'C',
            'CardName' => $cardName ?: (string) ($bp['CardName'] ?? $cardCode),
        ];

        if ($email) {
            $patch['EmailAddress'] = (string) $email;
        }

        $encoded = str_replace("'", "''", $cardCode);
        $response = $this->patch("/BusinessPartners('{$encoded}')", $patch);
        if ($response->successful()) {
            return true;
        }

        Log::warning('Failed to convert BusinessPartner to customer', [
            'card_code' => $cardCode,
            'status' => $response->status(),
            'body' => $response->body(),
        ]);

        return false;
    }

    private function normalizePhone(string $value): string
    {
        return preg_replace('/\D+/', '', $value) ?? '';
    }

    private function normalizeSapCardType(string $value): string
    {
        $v = strtoupper(trim($value));
        return match ($v) {
            'C', 'CCUSTOMER', 'CUSTOMER', 'C_CUSTOMER' => 'C',
            'S', 'CSUPPLIER', 'SUPPLIER', 'S_SUPPLIER' => 'S',
            'L', 'CLEAD', 'LEAD', 'L_LEAD' => 'L',
            default => $v,
        };
    }

    private function resolveConfiguredFallbackCustomerCode(string $cardName, mixed $email): ?string
    {
        $fallback = trim((string) config('omniful.order_fallback.customer_code', ''));
        if ($fallback === '') {
            return null;
        }

        $bp = $this->getBusinessPartner($fallback);
        if (!$bp) {
            return null;
        }

        $type = $this->normalizeSapCardType((string) ($bp['CardType'] ?? ''));
        if ($type === 'C') {
            Log::warning('Using configured fallback SAP customer code', [
                'fallback_customer_code' => $fallback,
            ]);
            return $fallback;
        }

        if ($this->ensureBusinessPartnerIsCustomer($fallback, $cardName, $email)) {
            Log::warning('Converted configured fallback BP to customer for order flow', [
                'fallback_customer_code' => $fallback,
            ]);
            return $fallback;
        }

        return null;
    }

    /**
     * @param array<string,mixed> $body
     */
    private function applyItemTypeUdfDefaults(array &$body): void
    {
        $udfField = trim((string) config('omniful.sap_item_defaults.item_type_udf_field', ''));
        $udfValue = config('omniful.sap_item_defaults.item_type_udf_value', '');
        if ($udfField === '' || $udfValue === null || $udfValue === '') {
            // continue to built-in defaults below
        } else {
            if (!str_starts_with($udfField, 'U_')) {
                $udfField = 'U_' . $udfField;
            }

            $body[$udfField] = $udfValue;
        }

        $itemTypeDefault = trim((string) config('omniful.sap_item_defaults.item_type_default_value', 'P'));
        $itemTypeDefault = $this->normalizeSapItemTypeCode($itemTypeDefault);
        if ($itemTypeDefault !== '' && !array_key_exists('U_ItemType', $body)) {
            $body['U_ItemType'] = $itemTypeDefault;
        }

        $productTypeDefault = trim((string) config('omniful.sap_item_defaults.product_type_default_value', 'product'));
        if ($productTypeDefault !== '' && !array_key_exists('U_ProductType', $body)) {
            $body['U_ProductType'] = $productTypeDefault;
        }
    }

    private function normalizeSapItemTypeCode(string $value): string
    {
        $v = strtoupper(trim($value));
        return match ($v) {
            'PRODUCT' => 'P',
            'INVENTORY', 'INVENTORY ITEM', 'INVENTORYITEM' => 'I',
            'STOCK PRODUCT', 'STOCKPRODUCT' => 'IP',
            'OTHER' => 'M',
            default => $v,
        };
    }

    private function isSapItemTypeRequiredError(string $body): bool
    {
        $normalized = strtolower($body);
        return str_contains($normalized, 'please specify item type');
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
                $line['WarehouseCode'] = $this->ensureWarehouseExists($hubCode, $lineIndex);
            }

            $lines[] = $line;
        }

        return $lines;
    }

    private function retryArOrderWithDynamicSeries(array $body, string $initialDocDate, bool &$usedReserveInvoiceFallback)
    {
        $seriesList = $this->getDocumentSeries('17');
        $today = now()->format('Y-m-d');
        $currentYear = substr($today, 0, 4);
        $candidateSeries = [];
        $fallbackSeries = [];

        foreach ((array) $seriesList as $series) {
            if (($series['Locked'] ?? 'tNO') === 'tYES') {
                continue;
            }
            if (!$this->isSeriesUsable((array) $series)) {
                continue;
            }

            $seriesId = isset($series['Series']) && is_numeric($series['Series']) ? (int) $series['Series'] : null;
            if ($seriesId === null) {
                continue;
            }

            $indicator = (string) ($series['PeriodIndicator'] ?? '');
            if ($indicator === $currentYear || $indicator === 'Default') {
                $candidateSeries[] = $seriesId;
            } else {
                $fallbackSeries[] = $seriesId;
            }
        }

        $orderedSeries = array_values(array_unique(array_merge($candidateSeries, $fallbackSeries)));
        $candidateDates = array_values(array_unique([$today, $initialDocDate]));
        $attempts = [];
        $response = null;

        foreach ($candidateDates as $date) {
            foreach ($orderedSeries as $seriesId) {
                $attemptBody = $body;
                $attemptBody['DocDate'] = $date;
                $attemptBody['DocDueDate'] = $date;
                $attemptBody['Series'] = $seriesId;
                $attemptBody['Comments'] = ($body['Comments'] ?? 'Omniful AR reserve order') . ' | retry dynamic series=' . $seriesId . ' date=' . $date;

                $response = $this->postArOrderWithReserveFallback($attemptBody, $usedReserveInvoiceFallback);
                if ($response->successful()) {
                    $this->rememberPreferredSeriesId('17', $seriesId);
                    return $response;
                }

                $attempts[] = 'series=' . $seriesId . ',date=' . $date . ',status=' . $response->status();
                if (!$this->isSapSeriesPeriodMismatchError((string) $response->body())) {
                    return $response;
                }
            }
        }

        foreach ($candidateDates as $date) {
            $attemptBody = $body;
            $attemptBody['DocDate'] = $date;
            $attemptBody['DocDueDate'] = $date;
            unset($attemptBody['Series']);
            $attemptBody['Comments'] = ($body['Comments'] ?? 'Omniful AR reserve order') . ' | retry dynamic without series date=' . $date;

            $response = $this->postArOrderWithReserveFallback($attemptBody, $usedReserveInvoiceFallback);
            if ($response->successful()) {
                $this->forgetPreferredSeriesId('17');
                return $response;
            }

            $attempts[] = 'series=none,date=' . $date . ',status=' . $response->status();
            if (!$this->isSapSeriesPeriodMismatchError((string) $response->body())) {
                return $response;
            }
        }

        Log::warning('SAP AR reserve order series dynamic retry exhausted', [
            'attempts' => $attempts,
            'initial_doc_date' => $initialDocDate,
        ]);

        return $response;
    }

    private function retryArOrderWithResolvedUom(array $body, bool &$usedReserveInvoiceFallback)
    {
        $lines = (array) ($body['DocumentLines'] ?? []);
        $uomByItem = [];
        $updated = false;

        foreach ($lines as $idx => $line) {
            $itemCode = trim((string) ($line['ItemCode'] ?? ''));
            if ($itemCode === '') {
                continue;
            }

            if (!array_key_exists($itemCode, $uomByItem)) {
                $uomByItem[$itemCode] = $this->getPreferredSalesUomForItem($itemCode);
                if ($uomByItem[$itemCode] === []) {
                    $uomByItem[$itemCode] = $this->getPreferredPurchaseUomForItem($itemCode);
                }
            }

            $uom = $uomByItem[$itemCode];
            if (isset($uom['UoMEntry']) && (!isset($line['UoMEntry']) || (int) $line['UoMEntry'] !== (int) $uom['UoMEntry'])) {
                $lines[$idx]['UoMEntry'] = $uom['UoMEntry'];
                $updated = true;
            }
            if (isset($uom['UoMCode']) && (!isset($line['UoMCode']) || (string) $line['UoMCode'] !== (string) $uom['UoMCode'])) {
                $lines[$idx]['UoMCode'] = (string) $uom['UoMCode'];
                $updated = true;
            }
        }

        if (!$updated) {
            return $this->postArOrderWithReserveFallback($body, $usedReserveInvoiceFallback);
        }

        $retryBody = $body;
        $retryBody['DocumentLines'] = $lines;
        $retryBody['Comments'] = ($body['Comments'] ?? 'Omniful AR reserve order') . ' | retry with resolved UoM';

        $preferredSeries = $this->getPreferredSeriesId('17');
        if ($preferredSeries !== null) {
            $retryBody['Series'] = $preferredSeries;
        }

        $response = $this->postArOrderWithReserveFallback($retryBody, $usedReserveInvoiceFallback);
        if (!$response->successful() && $this->isSapSeriesPeriodMismatchError((string) $response->body())) {
            $response = $this->retryArOrderWithDynamicSeries(
                $retryBody,
                (string) ($retryBody['DocDate'] ?? now()->format('Y-m-d')),
                $usedReserveInvoiceFallback
            );
        }

        return $response;
    }

    private function retryPurchaseOrderWithDynamicSeries(array $body, string $initialDocDate)
    {
        $seriesList = $this->getDocumentSeries('22');
        $today = now()->format('Y-m-d');
        $currentYear = substr($today, 0, 4);
        $candidateSeries = [];
        $fallbackSeries = [];

        foreach ((array) $seriesList as $series) {
            if (($series['Locked'] ?? 'tNO') === 'tYES') {
                continue;
            }
            if (!$this->isSeriesUsable((array) $series)) {
                continue;
            }

            $seriesId = isset($series['Series']) && is_numeric($series['Series']) ? (int) $series['Series'] : null;
            if ($seriesId === null) {
                continue;
            }

            $indicator = (string) ($series['PeriodIndicator'] ?? '');
            if ($indicator === $currentYear || $indicator === 'Default') {
                $candidateSeries[] = $seriesId;
            } else {
                $fallbackSeries[] = $seriesId;
            }
        }

        $orderedSeries = array_values(array_unique(array_merge($candidateSeries, $fallbackSeries)));
        $candidateDates = array_values(array_unique([$today, $initialDocDate]));
        $attempts = [];

        foreach ($candidateDates as $date) {
            foreach ($orderedSeries as $seriesId) {
                $attemptBody = $body;
                $attemptBody['DocDate'] = $date;
                $attemptBody['DocDueDate'] = $date;
                $attemptBody['Series'] = $seriesId;
                $attemptBody['Comments'] = ($body['Comments'] ?? 'Omniful PO') . ' | retry dynamic series=' . $seriesId . ' date=' . $date;

                $response = $this->post('/PurchaseOrders', $attemptBody);
                if ($response->successful()) {
                    $this->rememberPreferredSeriesId('22', $seriesId);
                    return $response;
                }

                $attempts[] = 'series=' . $seriesId . ',date=' . $date . ',status=' . $response->status();

                if (!$this->isSapSeriesPeriodMismatchError($response->body())) {
                    $this->rememberPreferredSeriesId('22', $seriesId);
                    return $response;
                }
            }
        }

        foreach ($candidateDates as $date) {
            $attemptBody = $body;
            $attemptBody['DocDate'] = $date;
            $attemptBody['DocDueDate'] = $date;
            unset($attemptBody['Series']);
            $attemptBody['Comments'] = ($body['Comments'] ?? 'Omniful PO') . ' | retry dynamic without series date=' . $date;

            $response = $this->post('/PurchaseOrders', $attemptBody);
            if ($response->successful()) {
                $this->forgetPreferredSeriesId('22');
                return $response;
            }

            $attempts[] = 'series=none,date=' . $date . ',status=' . $response->status();
            if (!$this->isSapSeriesPeriodMismatchError($response->body())) {
                return $response;
            }
        }

        Log::warning('SAP PO series dynamic retry exhausted', [
            'attempts' => $attempts,
            'initial_doc_date' => $initialDocDate,
        ]);

        return $response;
    }

    private function retryPurchaseOrderWithResolvedUom(array $body)
    {
        $lines = (array) ($body['DocumentLines'] ?? []);
        $uomByItem = [];
        $updated = false;

        foreach ($lines as $idx => $line) {
            $itemCode = trim((string) ($line['ItemCode'] ?? ''));
            if ($itemCode === '') {
                continue;
            }

            if (!array_key_exists($itemCode, $uomByItem)) {
                $uomByItem[$itemCode] = $this->getPreferredPurchaseUomForItem($itemCode);
            }

            $uom = $uomByItem[$itemCode];
            if (isset($uom['UoMEntry']) && (!isset($line['UoMEntry']) || (int) $line['UoMEntry'] !== (int) $uom['UoMEntry'])) {
                $lines[$idx]['UoMEntry'] = $uom['UoMEntry'];
                $updated = true;
            }
            if (isset($uom['UoMCode']) && (!isset($line['UoMCode']) || (string) $line['UoMCode'] !== (string) $uom['UoMCode'])) {
                $lines[$idx]['UoMCode'] = $uom['UoMCode'];
                $updated = true;
            }
        }

        if (!$updated) {
            return $this->post('/PurchaseOrders', $body);
        }

        $retryBody = $body;
        $retryBody['DocumentLines'] = $lines;
        $retryBody['Comments'] = ($body['Comments'] ?? 'Omniful PO') . ' | retry with resolved UoM';

        $preferredSeries = $this->getPreferredSeriesId('22');
        if ($preferredSeries !== null) {
            $retryBody['Series'] = $preferredSeries;
        }

        $response = $this->post('/PurchaseOrders', $retryBody);
        if (!$response->successful() && $this->isSapSeriesPeriodMismatchError($response->body())) {
            $response = $this->retryPurchaseOrderWithDynamicSeries(
                $retryBody,
                (string) ($retryBody['DocDate'] ?? now()->format('Y-m-d'))
            );
        }

        return $response;
    }

    private function getPreferredPurchaseUomForItem(string $itemCode): array
    {
        $encoded = str_replace("'", "''", $itemCode);
        $response = $this->get("/Items('{$encoded}')?\$select=ItemCode,UoMGroupEntry,DefaultPurchasingUoMEntry,PurchaseUnit,InventoryUOM,SalesUnit");
        if (!$response->successful()) {
            return [];
        }

        $payload = $response->json() ?? [];
        $out = [];

        $entry = $payload['DefaultPurchasingUoMEntry'] ?? null;
        if (is_numeric($entry) && (int) $entry > 0) {
            $out['UoMEntry'] = (int) $entry;
        }

        $code = trim((string) ($payload['PurchaseUnit'] ?? $payload['InventoryUOM'] ?? $payload['SalesUnit'] ?? ''));
        if ($code !== '') {
            $out['UoMCode'] = $code;
        }

        if (!isset($out['UoMEntry'])) {
            $groupEntry = $payload['UoMGroupEntry'] ?? null;
            if (is_numeric($groupEntry) && (int) $groupEntry > 0) {
                $groupUomEntry = $this->getFirstUomEntryFromGroup((int) $groupEntry);
                if ($groupUomEntry !== null) {
                    $out['UoMEntry'] = $groupUomEntry;
                }
            }
        }

        if (!isset($out['UoMEntry']) && isset($out['UoMCode'])) {
            $entryByCode = $this->getUomEntryByCode((string) $out['UoMCode']);
            if ($entryByCode !== null) {
                $out['UoMEntry'] = $entryByCode;
            }
        }

        if (!isset($out['UoMCode']) && isset($out['UoMEntry'])) {
            $codeByEntry = $this->getUomCodeByEntry((int) $out['UoMEntry']);
            if ($codeByEntry !== null) {
                $out['UoMCode'] = $codeByEntry;
            }
        }

        return $out;
    }

    private function getPreferredSalesUomForItem(string $itemCode): array
    {
        $encoded = str_replace("'", "''", $itemCode);
        $response = $this->get("/Items('{$encoded}')?\$select=ItemCode,UoMGroupEntry,DefaultSalesUoMEntry,SalesUnit,InventoryUOM,PurchaseUnit");
        if (!$response->successful()) {
            return [];
        }

        $payload = $response->json() ?? [];
        $out = [];

        $entry = $payload['DefaultSalesUoMEntry'] ?? null;
        if (is_numeric($entry) && (int) $entry > 0) {
            $out['UoMEntry'] = (int) $entry;
        }

        $code = trim((string) ($payload['SalesUnit'] ?? $payload['InventoryUOM'] ?? $payload['PurchaseUnit'] ?? ''));
        if ($code !== '') {
            $out['UoMCode'] = $code;
        }

        if (!isset($out['UoMEntry'])) {
            $groupEntry = $payload['UoMGroupEntry'] ?? null;
            if (is_numeric($groupEntry) && (int) $groupEntry > 0) {
                $groupUomEntry = $this->getFirstUomEntryFromGroup((int) $groupEntry);
                if ($groupUomEntry !== null) {
                    $out['UoMEntry'] = $groupUomEntry;
                }
            }
        }

        if (!isset($out['UoMEntry']) && isset($out['UoMCode'])) {
            $entryByCode = $this->getUomEntryByCode((string) $out['UoMCode']);
            if ($entryByCode !== null) {
                $out['UoMEntry'] = $entryByCode;
            }
        }

        if (!isset($out['UoMCode']) && isset($out['UoMEntry'])) {
            $codeByEntry = $this->getUomCodeByEntry((int) $out['UoMEntry']);
            if ($codeByEntry !== null) {
                $out['UoMCode'] = $codeByEntry;
            }
        }

        return $out;
    }

    private function getFirstUomEntryFromGroup(int $uomGroupEntry): ?int
    {
        $response = $this->get('/UoMGroups(' . $uomGroupEntry . ')?$select=AbsEntry,UoMGroupDefinitionCollection&$expand=UoMGroupDefinitionCollection');
        if (!$response->successful()) {
            return null;
        }

        $payload = $response->json() ?? [];
        $rows = (array) ($payload['UoMGroupDefinitionCollection'] ?? []);
        foreach ($rows as $row) {
            $entry = $row['UoMEntry'] ?? null;
            if (is_numeric($entry) && (int) $entry > 0) {
                return (int) $entry;
            }
        }

        return null;
    }

    private function getUomEntryByCode(string $uomCode): ?int
    {
        $uomCode = trim($uomCode);
        if ($uomCode === '') {
            return null;
        }

        $escaped = str_replace("'", "''", $uomCode);
        $response = $this->get("/UnitOfMeasurements?\$select=AbsEntry,Code&\$filter=Code eq '{$escaped}'&\$top=1");
        if (!$response->successful()) {
            return null;
        }

        $entry = data_get($response->json(), 'value.0.AbsEntry');
        return is_numeric($entry) ? (int) $entry : null;
    }

    private function getUomCodeByEntry(int $uomEntry): ?string
    {
        if ($uomEntry <= 0) {
            return null;
        }

        $response = $this->get('/UnitOfMeasurements(' . $uomEntry . ')?$select=Code');
        if (!$response->successful()) {
            return null;
        }

        $code = trim((string) ($response->json()['Code'] ?? ''));
        return $code !== '' ? $code : null;
    }

    private function getPreferredSeriesId(string $documentCode): ?int
    {
        $key = $this->preferredSeriesCacheKey($documentCode);
        $value = Cache::get($key);
        return is_numeric($value) ? (int) $value : null;
    }

    private function rememberPreferredSeriesId(string $documentCode, int $seriesId): void
    {
        if ($seriesId <= 0) {
            return;
        }

        Cache::put($this->preferredSeriesCacheKey($documentCode), $seriesId, now()->addDays(30));
    }

    private function forgetPreferredSeriesId(string $documentCode): void
    {
        Cache::forget($this->preferredSeriesCacheKey($documentCode));
    }

    private function preferredSeriesCacheKey(string $documentCode): string
    {
        $company = trim((string) (property_exists($this, 'companyDb') ? $this->companyDb : 'default'));
        return 'sap.preferred_series.' . strtolower($company !== '' ? $company : 'default') . '.' . $documentCode;
    }

    private function isSapSeriesPeriodMismatchError(string $responseBody): bool
    {
        return str_contains(strtolower($responseBody), 'series period does not match current period');
    }

    private function isSapUomCodeRequiredError(string $responseBody): bool
    {
        $body = strtolower($responseBody);
        return str_contains($body, 'specify a uom code')
            || str_contains($body, '1470000315');
    }

    private function applyDefaultCostCentersToLines(array $lines): array
    {
        $fields = $this->getDefaultCostCenterFields();
        if ($fields === []) {
            return $lines;
        }

        foreach ($lines as $idx => $line) {
            if (!is_array($line)) {
                continue;
            }
            foreach ($fields as $key => $value) {
                if (!array_key_exists($key, $line) || trim((string) $line[$key]) === '') {
                    $line[$key] = $value;
                }
            }
            $lines[$idx] = $line;
        }

        return $lines;
    }

    private function applyDefaultCostCentersToJournalLines(array $lines): array
    {
        return $this->applyDefaultCostCentersToLines($lines);
    }

    private function getDefaultCostCenterFields(): array
    {
        $raw = [
            'CostingCode' => (string) config('omniful.sap_cost_centers.costing_code', ''),
            'CostingCode2' => (string) config('omniful.sap_cost_centers.costing_code2', ''),
            'CostingCode3' => (string) config('omniful.sap_cost_centers.costing_code3', ''),
            'CostingCode4' => (string) config('omniful.sap_cost_centers.costing_code4', ''),
            'CostingCode5' => (string) config('omniful.sap_cost_centers.costing_code5', ''),
            'ProjectCode' => (string) config('omniful.sap_cost_centers.project_code', ''),
        ];

        $fields = [];
        foreach ($raw as $key => $value) {
            $v = trim($value);
            if ($v !== '') {
                $fields[$key] = $v;
            }
        }

        return $fields;
    }

}

