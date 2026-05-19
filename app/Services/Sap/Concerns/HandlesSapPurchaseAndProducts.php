<?php

namespace App\Services\Sap\Concerns;

use App\Exceptions\SapRequestException;
use App\Models\IntegrationSetting;
use App\Models\SapBankAccount;
use App\Models\SapCostCenterSetting;
use App\Support\Utf8;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

trait HandlesSapPurchaseAndProducts
{
    public function createArReserveInvoiceFromOmnifulOrder(array $data, string $externalId): array
    {
        $docDate = $this->resolveOrderDocumentDate($data, [
            'order_created_at',
            'created_at',
        ]);
        $taxDate = $this->resolveOrderTaxDate($data, $docDate, [
            'document_date',
            'invoice.document_date',
            'order_created_at',
            'created_at',
        ]);
        $currency = data_get($data, 'invoice.currency') ?? data_get($data, 'currency');
        $hubCode = $this->resolveOrderWarehouseCode(data_get($data, 'hub_code'));
        // Fast path for sales orders: use the resolved mapped customer directly
        // and let SAP reject the document clearly if master data is missing.
        $customerCode = $this->resolveOrderCustomerCode($data, $externalId);

        $lines = [];
        $lineTaxPercents = [];
        $lineIndex = 0;
        $salesItemChecked = [];
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

            $qty = (float) (data_get($item, 'quantity') ?? 0);
            if ($qty <= 0) {
                continue;
            }

            $itemCode = (string) $itemCode;
            if (!isset($salesItemChecked[$itemCode])) {
                $this->ensureItemCanBeSold($itemCode, $lineIndex);
                $salesItemChecked[$itemCode] = true;
            }

            $unitPrice = data_get($item, 'unit_price');
            if ($unitPrice === null) {
                $unitPrice = data_get($item, 'price');
            }
            if ($unitPrice === null) {
                $unitPrice = data_get($item, 'selling_price');
            }
            if ($unitPrice === null) {
                $unitPrice = data_get($item, 'display_price');
            }
            if ($unitPrice === null) {
                $lineTotal = data_get($item, 'total');
                if (is_numeric($lineTotal) && $qty > 0) {
                    $unitPrice = $this->roundSapAmount(((float) $lineTotal) / $qty);
                }
            }

            $line = [
                'ItemCode' => $itemCode,
                'Quantity' => $this->roundSapQuantity($qty),
                'UnitPrice' => $this->roundSapAmount((float) ($unitPrice ?? 0)),
            ];

            $taxCode = $this->resolveSapTaxCodeForOrderLine($data, (array) $item);
            if ($taxCode !== '') {
                $line['VatGroup'] = $taxCode;
            }

            if ($hubCode) {
                $line['WarehouseCode'] = (string) $hubCode;
            }

            $lines[] = $line;
            $lineTaxPercents[] = $this->extractOrderLineTaxPercent((array) $item);
        }

        if ($lines === []) {
            return [
                'ignored' => true,
                'reason' => 'No order lines found for AR reserve invoice',
                'request_body' => null,
            ];
        }
        // Always rebalance lines to the target merchandise subtotal. Previously
        // we computed a DiscountPercent and let SAP redistribute; that produced
        // 4-decimal totals (e.g. 107.5916 SAR) that drift from Omniful's
        // 2-decimal totals (107.59) and leave the AR Reserve Invoice with a
        // tiny non-zero Balance Due even after payment. Pre-rebalancing the
        // lines so the merchandise subtotal already equals the target lets
        // SAP compute the document at the precision we want.
        $lines = $this->rebalanceOrderLinesForInvoiceTotals($lines, $data, $lineTaxPercents);
        $documentDiscountPercent = 0.0;

        $lines = $this->normalizeSapDocumentLines(
            $this->applyDefaultCostCentersToLines($lines)
        );

        $comments = 'AR Reserve Invoice from Omniful order ' . $externalId;
        if ($currency && !$this->isValidCurrency((string) $currency)) {
            $comments .= ' | Currency ' . $currency . ' not found in SAP; using local currency';
            $currency = null;
        }

        $body = [
            'CardCode' => (string) $customerCode,
            'DocDate' => $docDate,
            'DocDueDate' => $docDate,
            'TaxDate' => $taxDate,
            'DocumentLines' => $lines,
            'Comments' => $comments,
            // SAP B1 AR Reserve Invoice via Invoices with ReserveInvoice = tYES
            'ReserveInvoice' => 'tYES',
        ];

        if ($documentDiscountPercent > 0) {
            $body['DiscountPercent'] = $documentDiscountPercent;
        }

        if ($currency) {
            $body['DocCurrency'] = $currency;

            // When the order is in a foreign currency, pin the exchange rate
            // from Omniful's payload so the AR reserve invoice and the
            // downstream incoming payment both use the same rate. Without an
            // explicit DocRate SAP picks the system rate at posting time and
            // a small drift between the two postings produces the
            // "Unbalanced Transaction" (-5012) error.
            $orderRate = data_get($data, 'invoice.exchange_rate.rate');
            if (is_numeric($orderRate) && (float) $orderRate > 0) {
                $localCurrency = trim((string) data_get($data, 'invoice.exchange_rate.store_currency', ''));
                $orderCurrency = trim((string) data_get($data, 'invoice.exchange_rate.order_currency', $currency));
                // Only set DocRate when the rate maps from order currency to a
                // *different* local currency. SAP rejects DocRate when the
                // document and company currencies match.
                if ($localCurrency !== '' && strtoupper($localCurrency) !== strtoupper($orderCurrency)) {
                    $body['DocRate'] = (float) $orderRate;
                }
            }
        }

        $body = $this->appendOmnifulDocumentUdfs($body, $data, $externalId);
        $body = $this->appendFreightToMarketingDocument($body, $data);

        $existingInvoice = $this->findExistingArReserveInvoiceForOmnifulOrder($body, $data, $externalId);
        if ($existingInvoice !== null) {
            $existingInvoice['ignored'] = false;
            $existingInvoice['reused_existing'] = true;
            $existingInvoice['request_body'] = $body;

            return $existingInvoice;
        }

        $usedReserveInvoiceFallback = false;
        $response = $this->postArOrderWithReserveFallback($body, $usedReserveInvoiceFallback);
        if (!$response->successful() && $this->isSapUomCodeRequiredError((string) $response->body())) {
            $response = $this->retryArOrderWithResolvedUom($body, $usedReserveInvoiceFallback);
        }

        if (!$response->successful()) {
            $responseBody = (string) $response->body();
            if ($this->isSapArInvoiceAlreadyExistsError($responseBody)) {
                $existingInvoice = $this->findExistingArReserveInvoiceForOmnifulOrder($body, $data, $externalId, $responseBody);
                if ($existingInvoice !== null && $this->arInvoiceMatchesOmnifulOrder($existingInvoice, $externalId, $data)) {
                    $existingInvoice['ignored'] = false;
                    $existingInvoice['reused_existing'] = true;
                    $existingInvoice['request_body'] = $body;
                    $existingInvoice['sap_duplicate_error'] = $responseBody;

                    return $existingInvoice;
                }

                if ($existingInvoice !== null) {
                    Log::error('SAP AR invoice "already exists" recovery rejected: conflicting invoice belongs to a different order — SAP numbering series may have been rolled back', [
                        'external_id' => $externalId,
                        'candidate_doc_entry' => $existingInvoice['DocEntry'] ?? null,
                        'candidate_doc_num' => $existingInvoice['DocNum'] ?? null,
                        'candidate_u_omo' => $existingInvoice['U_omo'] ?? null,
                        'candidate_u_zid_id' => $existingInvoice['U_ZidId'] ?? null,
                    ]);
                }
            }

            throw new SapRequestException(
                'SAP AR reserve invoice create failed: ' . $response->status() . ' ' . $response->body(),
                $body,
                (string) $response->body(),
                $response->status(),
            );
        }

        $payload = $response->json() ?? [];
        if ((string) ($payload['ReserveInvoice'] ?? '') !== 'tYES') {
            throw new \RuntimeException(
                'SAP did not create an A/R Reserve Invoice. Sales Order fallback is out of BRS scope. Response ReserveInvoice='
                . (string) ($payload['ReserveInvoice'] ?? 'null')
            );
        }

        $payload['ignored'] = false;
        $payload['request_body'] = $body;

        return $payload;
    }

    private function postArOrderWithReserveFallback(array $body, bool &$usedReserveInvoiceFallback)
    {
        $usedReserveInvoiceFallback = false;

        return $this->post('/Invoices', $body);
    }

    public function findExistingArReserveInvoiceForOmnifulOrderReference(array $data, string $externalId): ?array
    {
        $body = $this->appendOmnifulDocumentUdfs([], $data, $externalId);

        return $this->findExistingArReserveInvoiceForOmnifulOrder($body, $data, $externalId);
    }

    private function isSapArInvoiceAlreadyExistsError(string $body): bool
    {
        $normalized = strtolower($body);

        return str_contains($normalized, 'already exists')
            && (
                str_contains($normalized, 'ar invoice')
                || str_contains($normalized, 'invoice')
                || str_contains($normalized, 'reference number')
            );
    }

    private function findExistingArReserveInvoiceForOmnifulOrder(array $body, array $data, string $externalId, string $duplicateErrorBody = ''): ?array
    {
        $orderReference = $this->resolveOmnifulOrderReferenceForSap($data, $externalId);
        $duplicateReference = $this->extractSapDuplicateArInvoiceReference($duplicateErrorBody);
        $references = array_values(array_unique(array_filter([
            $orderReference,
            $externalId,
            $duplicateReference,
        ], fn ($value) => is_string($value) && trim($value) !== '')));

        $fields = array_values(array_unique(array_filter([
            trim((string) config('omniful.order_sync.order_number_udf_field', 'U_omo')),
            'U_ZidId',
            'U_SallaOrderId',
        ], fn ($field) => is_string($field) && trim($field) !== '')));

        foreach ($fields as $field) {
            $values = $references;
            if (isset($body[$field])) {
                array_unshift($values, trim((string) $body[$field]));
            }

            foreach (array_values(array_unique(array_filter($values, fn ($value) => trim((string) $value) !== ''))) as $value) {
                $invoice = $this->findArReserveInvoiceByFieldValue($field, (string) $value);
                if ($invoice !== null) {
                    return $invoice;
                }
            }
        }

        foreach ($references as $reference) {
            $invoice = $this->findArInvoiceByDocumentReference((string) $reference);
            if ($invoice !== null) {
                return $invoice;
            }
        }

        foreach ($references as $reference) {
            $invoice = $this->findArReserveInvoiceByComments((string) $reference);
            if ($invoice !== null) {
                return $invoice;
            }
        }

        foreach ($references as $reference) {
            $invoice = $this->findArInvoiceByPayloadScan((string) $reference);
            if ($invoice !== null) {
                return $invoice;
            }
        }

        return null;
    }

    private function extractSapDuplicateArInvoiceReference(string $body): string
    {
        if (preg_match('/reference\s+number\s+already\s+exists\s*:\s*([A-Za-z0-9_\-]+)/i', $body, $matches)) {
            return trim((string) ($matches[1] ?? ''));
        }

        if (preg_match('/\((?:\d+)\)\s*([^\s]+)\s+AR\s+invoice/i', $body, $matches)) {
            return trim((string) ($matches[1] ?? ''));
        }

        return '';
    }

    private function findArReserveInvoiceByFieldValue(string $field, string $value): ?array
    {
        $escapedField = str_replace("'", "''", $field);
        $escapedValue = str_replace("'", "''", $value);
        $filter = rawurlencode("{$escapedField} eq '{$escapedValue}'");
        // Do NOT pin a $select that lists UDFs explicitly: when a UDF such as
        // U_ZidId or U_SallaOrderId is not defined in this SAP company, the
        // Service Layer returns 400 ("Property U_xxx is not defined") for the
        // entire query and ownership recovery silently collapses to ownership=none.
        // Fetching all fields keeps the lookup resilient across SAP schemas.
        $response = $this->get("/Invoices?\$filter={$filter}&\$orderby=DocEntry desc&\$top=1");

        if (!$response->successful()) {
            // A 400 here typically means the *filter* field (the UDF being searched)
            // does not exist in this SAP company. That is expected for channel UDFs
            // like U_ZidId / U_SallaOrderId on tenants without those channels — the
            // caller iterates other fields, so we keep this at warning level only.
            Log::warning('SAP existing AR reserve invoice lookup failed', [
                'field' => $field,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            return null;
        }

        $rows = (array) ($response->json('value') ?? []);
        $invoice = $rows[0] ?? null;
        if (!is_array($invoice)) {
            return null;
        }

        return $this->normalizeFoundArInvoice($invoice);
    }

    private function findArInvoiceByDocumentReference(string $reference): ?array
    {
        $reference = trim($reference);
        if ($reference === '') {
            return null;
        }

        $escapedValue = str_replace("'", "''", $reference);
        $filters = ["NumAtCard eq '{$escapedValue}'"];

        if (ctype_digit($reference)) {
            $filters[] = 'DocNum eq ' . (int) $reference;
        }

        foreach ($filters as $filterExpression) {
            $filter = rawurlencode($filterExpression);
            // No $select: see findArReserveInvoiceByFieldValue() for rationale.
            $response = $this->get("/Invoices?\$filter={$filter}&\$orderby=DocEntry desc&\$top=1");

            if (!$response->successful()) {
                Log::warning('SAP existing AR invoice document-reference lookup failed', [
                    'reference' => $reference,
                    'filter' => $filterExpression,
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);

                continue;
            }

            $rows = (array) ($response->json('value') ?? []);
            $invoice = $rows[0] ?? null;
            if (is_array($invoice)) {
                return $this->normalizeFoundArInvoice($invoice);
            }
        }

        return null;
    }

    private function findArReserveInvoiceByComments(string $reference): ?array
    {
        $reference = trim($reference);
        if ($reference === '') {
            return null;
        }

        $escapedValue = str_replace("'", "''", $reference);
        $filter = rawurlencode("contains(Comments,'{$escapedValue}')");
        // No $select: see findArReserveInvoiceByFieldValue() for rationale.
        $response = $this->get("/Invoices?\$filter={$filter}&\$orderby=DocEntry desc&\$top=1");

        if (!$response->successful()) {
            Log::warning('SAP existing AR reserve invoice comments lookup failed', [
                'reference' => $reference,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            return null;
        }

        $rows = (array) ($response->json('value') ?? []);
        $invoice = $rows[0] ?? null;
        if (!is_array($invoice)) {
            return null;
        }

        return $this->normalizeFoundArInvoice($invoice);
    }

    private function findArInvoiceByPayloadScan(string $reference): ?array
    {
        $reference = trim($reference);
        if ($reference === '') {
            return null;
        }

        $top = 100;
        $maxRows = max($top, (int) config('services.sap.duplicate_invoice_scan_limit', 2000));
        $scanned = 0;

        while ($scanned < $maxRows) {
            $response = $this->get("/Invoices?\$orderby=DocEntry desc&\$top={$top}&\$skip={$scanned}");
            if (!$response->successful()) {
                Log::warning('SAP existing AR invoice payload scan failed', [
                    'reference' => $reference,
                    'skip' => $scanned,
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);

                return null;
            }

            $rows = (array) ($response->json('value') ?? []);
            if ($rows === []) {
                break;
            }

            foreach ($rows as $invoice) {
                if (!is_array($invoice)) {
                    continue;
                }

                $encoded = json_encode($invoice, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                if (!is_string($encoded) || !str_contains($encoded, $reference)) {
                    continue;
                }

                Log::warning('Found duplicate SAP AR invoice by payload scan', [
                    'reference' => $reference,
                    'scanned' => $scanned,
                    'doc_entry' => $invoice['DocEntry'] ?? null,
                    'doc_num' => $invoice['DocNum'] ?? null,
                    'num_at_card' => $invoice['NumAtCard'] ?? null,
                ]);

                return $this->normalizeFoundArInvoice($invoice);
            }

            $count = count($rows);
            $scanned += $count;
            if ($count < $top) {
                break;
            }
        }

        Log::warning('Duplicate SAP AR invoice lookup exhausted', [
            'reference' => $reference,
            'scanned' => $scanned,
            'limit' => $maxRows,
        ]);

        return null;
    }

    private function normalizeFoundArInvoice(array $invoice): array
    {
        if ((string) ($invoice['ReserveInvoice'] ?? '') !== 'tYES') {
            Log::warning('Reusing existing SAP AR invoice that is not marked as reserve invoice', [
                'doc_entry' => $invoice['DocEntry'] ?? null,
                'doc_num' => $invoice['DocNum'] ?? null,
                'reserve_invoice' => $invoice['ReserveInvoice'] ?? null,
            ]);
        }

        $invoice['ignored'] = false;
        return $invoice;
    }

    /**
     * Verify that an AR invoice fetched from SAP actually belongs to the Omniful
     * order we are processing. Protects against SAP numbering-series rollbacks
     * where DocNum X was already used for a *different* order and SAP raises
     * "(10002) X AR invoice already exists" for the new order we tried to create.
     */
    private function arInvoiceMatchesOmnifulOrder(array $invoice, string $externalId, array $data = []): bool
    {
        $externalId = trim($externalId);
        if ($externalId === '') {
            return false;
        }

        $candidates = [
            (string) ($invoice['U_omo'] ?? ''),
            (string) ($invoice['U_ZidId'] ?? ''),
            (string) ($invoice['U_SallaOrderId'] ?? ''),
            (string) ($invoice['NumAtCard'] ?? ''),
        ];
        foreach ($candidates as $candidate) {
            if (trim($candidate) === '') {
                continue;
            }
            if (trim($candidate) === $externalId) {
                return true;
            }
        }

        $comments = trim((string) ($invoice['Comments'] ?? ''));
        if ($comments !== '' && str_contains($comments, $externalId)) {
            return true;
        }

        // Optional: also accept Omniful internal id when the channel order
        // reference differs from the externalId we received.
        $orderReference = $this->resolveOmnifulOrderReferenceForSap($data, $externalId);
        if ($orderReference !== '' && $orderReference !== $externalId) {
            foreach ($candidates as $candidate) {
                if (trim($candidate) === $orderReference) {
                    return true;
                }
            }
            if ($comments !== '' && str_contains($comments, $orderReference)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Public recovery helper used when SAP rejects a create POST with
     * "AR invoice already exists". Pulls the conflicting invoice from SAP and
     * validates it belongs to the given Omniful order before returning it.
     * Returns null if no matching invoice can be located, or if the conflicting
     * DocNum belongs to a *different* order (true SAP series counter conflict).
     */
    public function recoverArReserveInvoiceForOmnifulOrder(string $responseBody, array $data, string $externalId): ?array
    {
        $inspection = $this->inspectArReserveInvoiceDuplicate($responseBody, $data, $externalId);
        $invoice = $inspection['invoice'] ?? null;
        if (!is_array($invoice) || empty($invoice['DocEntry'])) {
            return null;
        }

        if (($inspection['ownership'] ?? 'unknown') === 'foreign') {
            return null;
        }

        $invoice['ignored'] = false;
        $invoice['reused_existing'] = true;
        $invoice['recovered_after_duplicate_error'] = true;
        $invoice['sap_duplicate_error'] = $responseBody;
        $invoice['ownership'] = $inspection['ownership'];

        return $invoice;
    }

    /**
     * Inspect a SAP "already exists" duplicate error and classify the conflicting
     * invoice ownership. Used to decide whether to rebind locally, escalate as a
     * SAP numbering-series conflict (foreign invoice), or treat as a hard failure.
     *
     * Returned shape:
     *   ['invoice' => ?array, 'ownership' => 'match'|'orphan'|'foreign'|'none', 'reason' => string]
     *
     * - match:   UDFs/Comments tie the invoice to this Omniful order. Safe to rebind.
     * - orphan:  Invoice has no order ownership markers at all. Likely created by an
     *            earlier integration version without UDFs — caller may choose to
     *            adopt with caution.
     * - foreign: Invoice carries ownership markers pointing to a different order.
     *            Strong signal of a SAP numbering-series rollback; the SAP admin
     *            must increase the AR Invoice series "Next No." past MAX(DocNum).
     * - none:    No invoice located at all.
     */
    public function inspectArReserveInvoiceDuplicate(string $responseBody, array $data, string $externalId): array
    {
        $invoice = $this->findExistingArReserveInvoiceForOmnifulOrder([], $data, $externalId, $responseBody);
        if (!is_array($invoice) || empty($invoice['DocEntry'])) {
            Log::warning('SAP AR invoice duplicate-error recovery found no candidate', [
                'external_id' => $externalId,
                'response_body' => $responseBody,
            ]);

            return [
                'invoice' => null,
                'ownership' => 'none',
                'reason' => 'No invoice located in SAP for the duplicate error',
            ];
        }

        if ($this->arInvoiceMatchesOmnifulOrder($invoice, $externalId, $data)) {
            return [
                'invoice' => $invoice,
                'ownership' => 'match',
                'reason' => 'Invoice ownership matches the Omniful order',
            ];
        }

        if ($this->arInvoiceHasNoOwnershipMarkers($invoice)) {
            Log::warning('SAP AR invoice duplicate-error recovery found an orphan invoice (no ownership markers)', [
                'external_id' => $externalId,
                'response_body' => $responseBody,
                'candidate_doc_entry' => $invoice['DocEntry'] ?? null,
                'candidate_doc_num' => $invoice['DocNum'] ?? null,
            ]);

            return [
                'invoice' => $invoice,
                'ownership' => 'orphan',
                'reason' => 'Invoice has no UDF/Comments ownership markers; adoption requires manual review',
            ];
        }

        Log::error('SAP AR invoice duplicate-error recovery found a foreign invoice — likely SAP numbering series rollback', [
            'external_id' => $externalId,
            'response_body' => $responseBody,
            'candidate_doc_entry' => $invoice['DocEntry'] ?? null,
            'candidate_doc_num' => $invoice['DocNum'] ?? null,
            'candidate_u_omo' => $invoice['U_omo'] ?? null,
            'candidate_u_zid_id' => $invoice['U_ZidId'] ?? null,
            'candidate_u_salla_order_id' => $invoice['U_SallaOrderId'] ?? null,
            'candidate_num_at_card' => $invoice['NumAtCard'] ?? null,
            'candidate_comments' => $invoice['Comments'] ?? null,
        ]);

        return [
            'invoice' => $invoice,
            'ownership' => 'foreign',
            'reason' => 'Conflicting invoice belongs to a different order; SAP series counter may have been rolled back',
        ];
    }

    /**
     * True when the invoice has no UDF / NumAtCard / Comments markers identifying
     * any specific Omniful order. Such "orphan" invoices may safely be reviewed
     * for adoption by the current order.
     */
    private function arInvoiceHasNoOwnershipMarkers(array $invoice): bool
    {
        foreach (['U_omo', 'U_ZidId', 'U_SallaOrderId', 'NumAtCard'] as $field) {
            if (trim((string) ($invoice[$field] ?? '')) !== '') {
                return false;
            }
        }

        $comments = trim((string) ($invoice['Comments'] ?? ''));
        if ($comments === '') {
            return true;
        }

        // Comments may carry the standard "AR Reserve Invoice from Omniful order <id>"
        // template — treat that as an ownership marker only when an id follows it.
        return !preg_match('/Omniful\s+order\s+\S+/i', $comments);
    }

    public function createIncomingPaymentForInvoice(array $data): array
    {
        $invoiceDocEntry = (int) ($data['invoice_doc_entry'] ?? 0);
        if ($invoiceDocEntry <= 0) {
            throw new \RuntimeException('Missing invoice doc entry for incoming payment');
        }

        $transferAccount = trim((string) (
            $data['transfer_account']
            ?? config('omniful.order_payment.transfer_account', '')
        ));
        $paymentMethod = trim((string) ($data['payment_method'] ?? ''));
        $paymentCreditCard = $this->buildIncomingPaymentCreditCardLine(
            $paymentMethod,
            $transferAccount,
            $data,
        );

        if ($paymentCreditCard === null) {
            $transferAccount = $this->resolveSapTransferAccountValue($transferAccount);
            if ($transferAccount === '') {
                return [
                    'ignored' => true,
                    'reason' => 'Missing incoming payment transfer account',
                    'request_body' => null,
                ];
            }
        }

        $salesDoc = $this->getArReserveInvoice($invoiceDocEntry);
        $cardCode = (string) ($data['card_code'] ?? ($salesDoc['CardCode'] ?? ''));
        if ($cardCode === '') {
            throw new \RuntimeException('Missing CardCode for incoming payment');
        }

        $sumApplied = $this->roundSapAmount((float) ($data['sum_applied'] ?? ($salesDoc['DocTotal'] ?? 0)));
        if ($sumApplied <= 0) {
            return [
                'ignored' => true,
                'reason' => 'Incoming payment skipped: non-positive amount',
                'request_body' => null,
            ];
        }

        $transferDate = $this->formatDate((string) ($data['transfer_date'] ?? ($salesDoc['DocDate'] ?? now()->format('Y-m-d'))));
        $reference = trim((string) ($data['reference'] ?? ''));

        $remarks = 'Incoming payment from Omniful prepaid order';
        if ($reference !== '') {
            $remarks .= ' | order=' . $reference;
        }
        if ($paymentMethod !== '') {
            $remarks .= ' | method=' . $paymentMethod;
        }

        $body = [
            'CardCode' => $cardCode,
            'DocType' => 'rCustomer',
            'DocDate' => $transferDate,
            'PaymentInvoices' => [
                [
                    'DocEntry' => $invoiceDocEntry,
                    'SumApplied' => $sumApplied,
                ],
            ],
            'Remarks' => $remarks,
        ];

        // CRITICAL for multi-currency: when the invoice was posted in a foreign
        // currency (e.g. order priced in AED while the SAP company is SAR),
        // SAP defaults the payment's DocCurrency to the local currency unless
        // we set it explicitly. That makes SumApplied 139.46 mean 139.46 SAR
        // applied to an invoice worth 139.46 AED ~= 142.50 SAR — producing the
        // "Unbalanced Transaction" (-5012) error. Inherit the invoice's
        // DocCurrency / DocRate so the payment lands at the exact same
        // local-currency amount the invoice reserved.
        $invoiceCurrency = trim((string) ($salesDoc['DocCurrency'] ?? ''));
        $invoiceRate = is_numeric($salesDoc['DocRate'] ?? null) ? (float) $salesDoc['DocRate'] : 0.0;
        if ($invoiceCurrency !== '') {
            $body['DocCurrency'] = $invoiceCurrency;
        }
        if ($invoiceRate > 0) {
            $body['DocRate'] = $invoiceRate;
        }

        $body = $this->appendOmnifulDocumentUdfs($body, $data, $reference);

        if ($paymentCreditCard !== null) {
            $body['PaymentCreditCards'] = [$paymentCreditCard];
        } else {
            $body['TransferAccount'] = $transferAccount;
            $body['TransferDate'] = $transferDate;
            $body['TransferSum'] = $sumApplied;
        }

        $externalId = trim((string) ($data['external_id'] ?? $data['reference'] ?? ''));

        // Idempotency: before POST, look in SAP for an existing incoming payment
        // owned by this Omniful order. Rescues retries where an earlier worker
        // posted the payment successfully but failed to persist DocEntry locally.
        if ($externalId !== '') {
            $existingPayment = $this->findExistingIncomingPaymentForOmnifulOrder($data, $externalId, $invoiceDocEntry);
            if (is_array($existingPayment) && !empty($existingPayment['DocEntry'])
                && $this->incomingPaymentMatchesOmnifulOrder($existingPayment, $externalId, $data)) {
                $existingPayment['ignored'] = false;
                $existingPayment['reused_existing'] = true;
                $existingPayment['request_body'] = $body;
                return $existingPayment;
            }
        }

        $response = $this->post('/IncomingPayments', $body);
        if (!$response->successful()) {
            $responseBody = (string) $response->body();

            // "Invoice is already closed or blocked" / generic duplicate signal —
            // try to recover the matching payment from SAP and bind it locally
            // instead of failing the order outright.
            if ($externalId !== '' && $this->isSapIncomingPaymentInvoiceClosedError($responseBody)) {
                $inspection = $this->inspectIncomingPaymentDuplicate($responseBody, $invoiceDocEntry, $data, $externalId);
                $candidate = $inspection['payment'] ?? null;
                if (($inspection['ownership'] ?? '') === 'match'
                    && is_array($candidate) && !empty($candidate['DocEntry'])) {
                    $candidate['ignored'] = false;
                    $candidate['reused_existing'] = true;
                    $candidate['recovered_after_duplicate_error'] = true;
                    $candidate['sap_duplicate_error'] = $responseBody;
                    $candidate['request_body'] = $body;
                    return $candidate;
                }
            }

            throw new SapRequestException(
                'SAP incoming payment create failed: ' . $response->status() . ' ' . $response->body(),
                $body,
                $responseBody,
                $response->status(),
            );
        }

        $payload = $response->json() ?? [];
        $payload['ignored'] = false;
        $payload['request_body'] = $body;

        return $payload;
    }

    /**
     * Search SAP for an Incoming Payment tied to the given Omniful order, by
     * UDFs first (U_omo / U_ZidId / U_SallaOrderId), then by Remarks. Used to
     * rebind successful-but-unsaved payments and to recover from "Invoice is
     * already closed or blocked" errors.
     */
    public function findExistingIncomingPaymentForOmnifulOrder(array $data, string $externalId, int $invoiceDocEntry = 0): ?array
    {
        $externalId = trim($externalId);
        if ($externalId === '') {
            return null;
        }

        $escapedValue = str_replace("'", "''", $externalId);
        $fields = array_values(array_unique(array_filter([
            trim((string) config('omniful.order_sync.order_number_udf_field', 'U_omo')),
            'U_ZidId',
            'U_SallaOrderId',
        ], fn ($field) => is_string($field) && trim($field) !== '')));

        foreach ($fields as $field) {
            $escapedField = str_replace("'", "''", $field);
            $filter = rawurlencode("{$escapedField} eq '{$escapedValue}' and DocType eq 'rCustomer'");
            // No $select: SAP UDFs may not exist on this tenant; fetch all fields.
            $response = $this->get("/IncomingPayments?\$filter={$filter}&\$orderby=DocEntry desc&\$top=1");

            if (!$response->successful()) {
                Log::warning('SAP incoming payment UDF lookup failed', [
                    'field' => $field,
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);
                continue;
            }

            $rows = (array) ($response->json('value') ?? []);
            $payment = $rows[0] ?? null;
            if (is_array($payment) && !empty($payment['DocEntry'])) {
                return $payment;
            }
        }

        // Fallback: search Remarks for the standard "order=<externalId>" tag.
        $remarksFilter = rawurlencode("contains(Remarks,'order={$escapedValue}') and DocType eq 'rCustomer'");
        $response = $this->get("/IncomingPayments?\$filter={$remarksFilter}&\$orderby=DocEntry desc&\$top=1");
        if ($response->successful()) {
            $rows = (array) ($response->json('value') ?? []);
            $payment = $rows[0] ?? null;
            if (is_array($payment) && !empty($payment['DocEntry'])) {
                return $payment;
            }
        }

        return null;
    }

    /**
     * Classify a SAP "Invoice is already closed or blocked" (or similar)
     * incoming payment error: match / orphan / foreign / none.
     */
    public function inspectIncomingPaymentDuplicate(string $responseBody, int $invoiceDocEntry, array $data, string $externalId): array
    {
        $payment = $this->findExistingIncomingPaymentForOmnifulOrder($data, $externalId, $invoiceDocEntry);
        if (!is_array($payment) || empty($payment['DocEntry'])) {
            Log::warning('SAP incoming payment duplicate-error recovery found no candidate', [
                'external_id' => $externalId,
                'invoice_doc_entry' => $invoiceDocEntry,
                'response_body' => $responseBody,
            ]);

            return [
                'payment' => null,
                'ownership' => 'none',
                'reason' => 'No incoming payment located in SAP for this Omniful order',
            ];
        }

        if ($this->incomingPaymentMatchesOmnifulOrder($payment, $externalId, $data)) {
            return [
                'payment' => $payment,
                'ownership' => 'match',
                'reason' => 'Incoming payment ownership matches the Omniful order',
            ];
        }

        if ($this->incomingPaymentHasNoOwnershipMarkers($payment)) {
            Log::warning('SAP incoming payment duplicate-error recovery found an orphan payment', [
                'external_id' => $externalId,
                'candidate_doc_entry' => $payment['DocEntry'] ?? null,
                'candidate_doc_num' => $payment['DocNum'] ?? null,
            ]);

            return [
                'payment' => $payment,
                'ownership' => 'orphan',
                'reason' => 'Incoming payment has no UDF/Remarks ownership markers',
            ];
        }

        Log::error('SAP incoming payment duplicate-error recovery found a foreign payment', [
            'external_id' => $externalId,
            'candidate_doc_entry' => $payment['DocEntry'] ?? null,
            'candidate_doc_num' => $payment['DocNum'] ?? null,
            'candidate_u_omo' => $payment['U_omo'] ?? null,
            'candidate_u_zid_id' => $payment['U_ZidId'] ?? null,
            'candidate_remarks' => $payment['Remarks'] ?? null,
        ]);

        return [
            'payment' => $payment,
            'ownership' => 'foreign',
            'reason' => 'Conflicting incoming payment belongs to a different order',
        ];
    }

    private function isSapIncomingPaymentInvoiceClosedError(string $body): bool
    {
        $normalized = strtolower($body);

        return str_contains($normalized, 'already closed or blocked')
            || str_contains($normalized, 'invoice is already closed')
            || str_contains($normalized, 'invoice is already blocked')
            || str_contains($normalized, 'is closed or cancelled');
    }

    private function incomingPaymentMatchesOmnifulOrder(array $payment, string $externalId, array $data = []): bool
    {
        $externalId = trim($externalId);
        if ($externalId === '') {
            return false;
        }

        foreach (['U_omo', 'U_ZidId', 'U_SallaOrderId'] as $field) {
            if (trim((string) ($payment[$field] ?? '')) === $externalId) {
                return true;
            }
        }

        $remarks = trim((string) ($payment['Remarks'] ?? ''));
        if ($remarks !== '' && (
            str_contains($remarks, 'order=' . $externalId)
            || str_contains($remarks, $externalId)
        )) {
            return true;
        }

        return false;
    }

    private function incomingPaymentHasNoOwnershipMarkers(array $payment): bool
    {
        foreach (['U_omo', 'U_ZidId', 'U_SallaOrderId'] as $field) {
            if (trim((string) ($payment[$field] ?? '')) !== '') {
                return false;
            }
        }

        $remarks = trim((string) ($payment['Remarks'] ?? ''));
        if ($remarks === '') {
            return true;
        }

        return !preg_match('/order\s*=\s*\S+/i', $remarks);
    }

    private function buildIncomingPaymentCreditCardLine(string $paymentMethod, string $configuredAccount, array $data): ?array
    {
        $normalizedMethod = strtolower(str_replace([' ', '-', '_'], '', trim($paymentMethod)));
        if ($normalizedMethod === '') {
            return null;
        }

        $creditCardId = (int) config('omniful.order_payment.method_credit_cards.' . $normalizedMethod, 0);
        if ($creditCardId <= 0) {
            return null;
        }

        $creditAccount = $this->resolveSapTransferAccountValue(trim($configuredAccount));
        if ($creditAccount === '') {
            $creditAccount = trim((string) config('omniful.order_payment.method_transfer_accounts.' . $normalizedMethod, ''));
            $creditAccount = $this->resolveSapTransferAccountValue($creditAccount);
        }

        if ($creditAccount === '') {
            return null;
        }

        $creditSum = $this->roundSapAmount((float) ($data['sum_applied'] ?? 0));
        if ($creditSum <= 0) {
            return null;
        }

        $reference = trim((string) ($data['reference'] ?? '1234'));
        if ($reference === '') {
            $reference = '1234';
        }

        $validUntil = $this->formatDate((string) ($data['transfer_date'] ?? now()->format('Y-m-d')));
        $validUntil = \Carbon\Carbon::parse($validUntil)->addYears(5)->format('Ymd');

        return [
            'LineNum' => 0,
            'CreditCard' => $creditCardId,
            'CreditAcct' => $creditAccount,
            'CreditCardNumber' => (string) $creditCardId,
            'CardValidUntil' => $validUntil,
            'VoucherNum' => mb_substr($reference, 0, 50),
            'CreditSum' => $creditSum,
        ];
    }

    public function createCardFeeJournalEntryForOrder(array $data): array
    {
        $amount = (float) ($data['amount'] ?? 0);
        if ($amount <= 0) {
            return [
                'ignored' => true,
                'reason' => 'Card fee amount is not positive',
                'request_body' => null,
            ];
        }

        $expenseAccount = trim((string) ($data['expense_account'] ?? config('omniful.order_payment.card_fee_expense_account', '')));
        $offsetAccount = trim((string) ($data['offset_account'] ?? config('omniful.order_payment.card_fee_offset_account', '')));
        if ($expenseAccount === '' || $offsetAccount === '') {
            return [
                'ignored' => true,
                'reason' => 'Missing card fee journal accounts',
                'request_body' => null,
            ];
        }

        $referenceDate = $this->formatDate((string) ($data['posting_date'] ?? now()->format('Y-m-d')));
        $reference = trim((string) ($data['reference'] ?? ''));
        $memo = trim((string) ($data['memo'] ?? 'Card fee journal from Omniful prepaid order'));
        $journalReference = trim((string) ($data['journal_reference'] ?? 'CARD_FEE'));

        $journalLines = $this->applyDefaultCostCentersToJournalLines([
            [
                'AccountCode' => $expenseAccount,
                'Debit' => $this->roundSapAmount($amount),
                'LineMemo' => $this->truncateSapText($memo, 254),
                'Reference1' => $journalReference,
                'Reference2' => $reference,
                'AdditionalReference' => $this->truncateSapText($reference, 100),
            ],
            [
                'AccountCode' => $offsetAccount,
                'Credit' => $this->roundSapAmount($amount),
                'LineMemo' => $this->truncateSapText($memo, 254),
                'Reference1' => $journalReference,
                'Reference2' => $reference,
                'AdditionalReference' => $this->truncateSapText($reference, 100),
            ],
        ], $this->resolveOrderWarehouseCode(data_get($data, 'hub_code')));

        $body = [
            'ReferenceDate' => $referenceDate,
            'DueDate' => $referenceDate,
            'TaxDate' => $referenceDate,
            'Memo' => $this->truncateSapText($memo, 254),
            'JournalEntryLines' => $journalLines,
        ];

        if ($reference !== '') {
            $body['Reference'] = $journalReference !== '' ? $journalReference : $reference;
            $body['Reference2'] = $reference;
        }

        // Idempotency: look for an existing card-fee journal entry first so a
        // crash between POST and local save does not produce a duplicate.
        $existingJournal = $this->findExistingCardFeeJournalForOrder($reference, $journalReference);
        if (is_array($existingJournal) && !empty($existingJournal['TransId'])) {
            $existingJournal['ignored'] = false;
            $existingJournal['reused_existing'] = true;
            $existingJournal['request_body'] = $body;
            return $existingJournal;
        }

        $response = $this->post('/JournalEntries', $body);
        if (!$response->successful()) {
            $responseBody = (string) $response->body();
            if ($this->isSapJournalAlreadyIntegratedError($responseBody)) {
                // Resolve the actual TransId/Number from SAP rather than storing
                // the literal "already_integrated" string in the local DB.
                $recovered = $this->findExistingCardFeeJournalForOrder($reference, $journalReference);
                if (is_array($recovered) && !empty($recovered['TransId'])) {
                    $recovered['ignored'] = false;
                    $recovered['already_integrated'] = true;
                    $recovered['reused_existing'] = true;
                    $recovered['recovered_after_duplicate_error'] = true;
                    $recovered['request_body'] = $body;
                    $recovered['sap_duplicate_error'] = $responseBody;
                    return $recovered;
                }

                // Fallback when SAP refused the POST but we cannot locate the
                // matching journal — keep the order recoverable by marking it
                // ignored with a clear, actionable reason instead of storing
                // a meaningless literal as the journal reference.
                Log::warning('SAP card-fee journal "already integrated" but no matching JE could be located', [
                    'reference' => $reference,
                    'journal_reference' => $journalReference,
                    'response_body' => $responseBody,
                ]);

                return [
                    'ignored' => true,
                    'already_integrated' => true,
                    'reason' => 'SAP refused the card-fee journal as already integrated, but the matching JE could not be located by Reference/Reference2. Review in SAP and link manually if needed.',
                    'request_body' => $body,
                    'error_response_body' => $responseBody,
                    'status_code' => $response->status(),
                ];
            }

            throw new SapRequestException(
                'SAP card-fee journal create failed: ' . $response->status() . ' ' . $responseBody,
                $body,
                $responseBody,
                $response->status(),
            );
        }

        $payload = $response->json() ?? [];
        $payload['ignored'] = false;
        $payload['request_body'] = $body;

        return $payload;
    }

    /**
     * Locate an existing card-fee journal entry in SAP by Reference (CARD_FEE
     * tag) and Reference2 (Omniful order id). Used both for pre-POST idempotency
     * and to recover the real TransId/Number when SAP refuses a duplicate POST
     * with "JE already integrated" — replacing the previous behavior of
     * persisting the literal string "already_integrated" as a journal id.
     */
    public function findExistingCardFeeJournalForOrder(string $reference, string $journalReference = 'CARD_FEE'): ?array
    {
        $reference = trim($reference);
        if ($reference === '') {
            return null;
        }

        $escapedReference = str_replace("'", "''", $reference);
        $escapedJournalReference = str_replace("'", "''", $journalReference !== '' ? $journalReference : 'CARD_FEE');

        $filters = [
            "Reference2 eq '{$escapedReference}' and Reference eq '{$escapedJournalReference}'",
            "Reference2 eq '{$escapedReference}'",
        ];

        foreach ($filters as $filterExpression) {
            $filter = rawurlencode($filterExpression);
            // No $select: tenants may extend JournalEntries with UDFs that fail
            // to project together; fetch the full record instead.
            $response = $this->get("/JournalEntries?\$filter={$filter}&\$orderby=TransId desc&\$top=1");
            if (!$response->successful()) {
                Log::warning('SAP card-fee journal lookup failed', [
                    'reference' => $reference,
                    'filter' => $filterExpression,
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);
                continue;
            }

            $rows = (array) ($response->json('value') ?? []);
            $journal = $rows[0] ?? null;
            if (is_array($journal) && !empty($journal['TransId'])) {
                if (!isset($journal['Number']) && isset($journal['JdtNum'])) {
                    $journal['Number'] = $journal['JdtNum'];
                }
                return $journal;
            }
        }

        return null;
    }

    private function isSapJournalAlreadyIntegratedError(string $body): bool
    {
        $normalized = preg_replace('/\s+/', ' ', strtolower($body)) ?? strtolower($body);

        return str_contains($normalized, 'je already integrated')
            || (str_contains($normalized, 'je') && str_contains($normalized, 'already integrated'));
    }

    public function createDeliveryFromReserveOrder(array $data): array
    {
        $orderDocEntry = (int) ($data['order_doc_entry'] ?? 0);
        if ($orderDocEntry <= 0) {
            throw new \RuntimeException('Missing SAP order doc entry for delivery');
        }

        $salesDoc = $this->getArReserveInvoice($orderDocEntry);
        $hubCode = (string) $this->resolveOrderWarehouseCode($data['hub_code'] ?? null);
        $externalId = (string) ($data['external_id'] ?? '');
        $docDate = $this->resolveOrderDocumentDate($data, [
            'delivery_date',
            'shipment.delivery_date',
            'updated_at',
            'order_created_at',
            'created_at',
        ]);
        $taxDate = $this->resolveOrderTaxDate($data, $docDate, [
            'delivery_date',
            'shipment.delivery_date',
            'document_date',
            'updated_at',
            'order_created_at',
            'created_at',
        ]);

        $orderItems = (array) ($data['order_items'] ?? []);
        $lines = $this->buildDeliveryLinesFromRequestedItems($salesDoc, $orderItems, $orderDocEntry, $hubCode);

        if ($lines === []) {
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
                    'BaseType' => 13,
                    'BaseEntry' => $orderDocEntry,
                    'BaseLine' => (int) $lineNum,
                'Quantity' => $this->roundSapQuantity($openQty),
            ];

                if ($hubCode !== '') {
                    $deliveryLine['WarehouseCode'] = $this->ensureWarehouseExists($hubCode, ((int) $lineNum) + 1);
                }

                $lines[] = $deliveryLine;
            }
        }

        if ($lines === []) {
            return [
                'ignored' => true,
                'reason' => 'No open quantity found for delivery',
                'request_body' => null,
            ];
        }
        $lines = $this->normalizeSapDocumentLines(
            $this->applyDefaultCostCentersToLines($lines)
        );

        $body = [
            'CardCode' => (string) ($salesDoc['CardCode'] ?? ''),
            'DocDate' => $docDate,
            'DocDueDate' => $docDate,
            'TaxDate' => $taxDate,
            'Comments' => 'Delivery from Omniful order ' . $externalId,
            'DocumentLines' => $lines,
        ];

        $body = $this->appendOmnifulDocumentUdfs($body, $data, $externalId);
        $body = $this->appendFreightToMarketingDocument($body, $data);

        $response = $this->post('/DeliveryNotes', $body);
        if (!$response->successful()) {
            throw new SapRequestException(
                'SAP delivery create failed: ' . $response->status() . ' ' . $response->body(),
                $body,
                (string) $response->body(),
                $response->status(),
            );
        }

        $payload = $response->json() ?? [];
        $payload['ignored'] = false;
        $payload['request_body'] = $body;

        return $payload;
    }

    private function buildDeliveryLinesFromRequestedItems(array $salesDoc, array $orderItems, int $orderDocEntry, string $hubCode): array
    {
        $requestedByItem = $this->extractRequestedDeliveryQuantities($orderItems);
        if ($requestedByItem === []) {
            return [];
        }

        $salesLinesByItem = [];
        foreach ((array) ($salesDoc['DocumentLines'] ?? []) as $line) {
            $lineNum = $line['LineNum'] ?? null;
            $itemCode = trim((string) ($line['ItemCode'] ?? ''));
            if (!is_numeric($lineNum) || $itemCode === '') {
                continue;
            }

            $openQty = $this->extractOpenOrderLineQuantity($line);
            if ($openQty <= 0) {
                continue;
            }

            $salesLinesByItem[$itemCode][] = [
                'line_num' => (int) $lineNum,
                'open_qty' => $openQty,
            ];
        }

        $deliveryLines = [];
        foreach ($requestedByItem as $itemCode => $requestedQty) {
            if ($requestedQty <= 0 || !isset($salesLinesByItem[$itemCode])) {
                continue;
            }

            $remaining = $requestedQty;
            foreach ($salesLinesByItem[$itemCode] as &$salesLine) {
                if ($remaining <= 0) {
                    break;
                }

                $lineOpenQty = (float) ($salesLine['open_qty'] ?? 0);
                if ($lineOpenQty <= 0) {
                    continue;
                }

                $allocQty = min($remaining, $lineOpenQty);
                if ($allocQty <= 0) {
                    continue;
                }

                $deliveryLine = [
                    'BaseType' => 13,
                    'BaseEntry' => $orderDocEntry,
                    'BaseLine' => (int) $salesLine['line_num'],
                    'Quantity' => $this->roundSapQuantity($allocQty),
                ];

                if ($hubCode !== '') {
                    $deliveryLine['WarehouseCode'] = $this->ensureWarehouseExists($hubCode, ((int) $salesLine['line_num']) + 1);
                }

                $deliveryLines[] = $deliveryLine;
                $salesLine['open_qty'] = max(0.0, $lineOpenQty - $allocQty);
                $remaining -= $allocQty;
            }
            unset($salesLine);
        }

        return $deliveryLines;
    }

    /**
     * @param array<int,array<string,mixed>> $orderItems
     * @return array<string,float>
     */
    private function extractRequestedDeliveryQuantities(array $orderItems): array
    {
        $quantities = [];

        foreach ($orderItems as $item) {
            $itemCode = $this->extractOrderWebhookItemCode((array) $item);
            if ($itemCode === '') {
                continue;
            }

            $qty = $this->extractRequestedDeliveryQuantity((array) $item);
            if ($qty <= 0) {
                continue;
            }

            if (!isset($quantities[$itemCode])) {
                $quantities[$itemCode] = 0.0;
            }

            $quantities[$itemCode] += $qty;
        }

        return $quantities;
    }

    private function extractOrderWebhookItemCode(array $item): string
    {
        $candidates = [
            data_get($item, 'sku_code'),
            data_get($item, 'seller_sku_code'),
            data_get($item, 'sku.seller_sku_code'),
            data_get($item, 'seller_sku.seller_sku_code'),
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

    private function extractRequestedDeliveryQuantity(array $item): float
    {
        $explicitShipmentFields = [
            'delivered_quantity',
            'shipped_quantity',
            'shipment_quantity',
            'fulfilled_quantity',
            'packed_quantity',
            'picked_quantity',
        ];

        $hasExplicitShipmentField = false;
        foreach ($explicitShipmentFields as $field) {
            $value = data_get($item, $field);
            if ($value !== null) {
                $hasExplicitShipmentField = true;
            }

            if (is_numeric($value) && (float) $value > 0) {
                return (float) $value;
            }
        }

        if ($hasExplicitShipmentField) {
            return 0.0;
        }

        $fallbackQty = data_get($item, 'quantity');
        if (is_numeric($fallbackQty) && (float) $fallbackQty > 0) {
            return (float) $fallbackQty;
        }

        return 0.0;
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
        $reference = trim((string) ($data['reference'] ?? ($delivery['NumAtCard'] ?? '')));
        $amount = (float) ($data['amount'] ?? 0);
        if ($amount <= 0 && $reference !== '') {
            $amount = $this->fetchCogsAmountByOrderReference($reference);
        }
        if ($amount <= 0) {
            $amount = $this->extractDeliveryCogsAmount($delivery);
        }
        if ($amount <= 0) {
            return [
                'ignored' => true,
                'reason' => 'COGS amount is not available from delivery lines',
                'request_body' => null,
            ];
        }

        $referenceDate = $this->formatDate((string) (($delivery['DocDate'] ?? null) ?: now()->format('Y-m-d')));
        $memo = trim((string) ($data['memo'] ?? ('COGS journal for Delivery ' . ($delivery['DocNum'] ?? $deliveryDocEntry))));

        $journalLines = $this->applyDefaultCostCentersToJournalLines([
            [
                'AccountCode' => $expenseAccount,
                'Debit' => $this->roundSapAmount($amount),
                'LineMemo' => $this->truncateSapText($memo, 254),
                'Reference1' => $reference,
                'Reference2' => (string) ($delivery['DocNum'] ?? ''),
                'AdditionalReference' => $this->truncateSapText($reference, 100),
            ],
            [
                'AccountCode' => $offsetAccount,
                'Credit' => $this->roundSapAmount($amount),
                'LineMemo' => $this->truncateSapText($memo, 254),
                'Reference1' => $reference,
                'Reference2' => (string) ($delivery['DocNum'] ?? ''),
                'AdditionalReference' => $this->truncateSapText($reference, 100),
            ],
        ], $this->resolveWarehouseCodeFromDocumentLines($delivery));

        $body = [
            'ReferenceDate' => $referenceDate,
            'DueDate' => $referenceDate,
            'TaxDate' => $referenceDate,
            'Memo' => $this->truncateSapText($memo, 254),
            'JournalEntryLines' => $journalLines,
        ];

        if ($reference !== '') {
            $body['Reference'] = $reference;
            $body['Reference2'] = (string) ($delivery['DocNum'] ?? $reference);
            $body['Reference3'] = $reference;
        }

        $response = $this->post('/JournalEntries', $body);
        if (!$response->successful()) {
            throw new SapRequestException(
                'SAP COGS journal create failed: ' . $response->status() . ' ' . $response->body(),
                $body,
                (string) $response->body(),
                $response->status(),
            );
        }

        $payload = $response->json() ?? [];
        $payload['ignored'] = false;
        $payload['request_body'] = $body;

        return $payload;
    }

    public function createArCreditMemoFromReturnOrder(array $data, array $options = []): array
    {
        $externalId = (string) ($options['external_id'] ?? '');
        $hubCode = (string) $this->resolveOrderWarehouseCode(data_get($data, 'hub_code'));
        $baseDeliveryDocEntry = (int) ($options['base_delivery_doc_entry'] ?? 0);
        $baseOrderDocEntry = (int) ($options['base_order_doc_entry'] ?? 0);
        $items = (array) ($options['parsed_items'] ?? []);
        if ($items === []) {
            $items = $this->buildReturnCreditLinesFromPayload($data);
        }
        if ($items === []) {
            return [
                'ignored' => true,
                'reason' => 'No return lines found for AR credit memo',
                'request_body' => null,
            ];
        }

        $docDate = $this->resolveOrderDocumentDate($data, [
            'document_date',
            'updated_at',
            'created_at',
        ]);
        $taxDate = $this->resolveOrderTaxDate($data, $docDate, [
            'document_date',
            'updated_at',
            'created_at',
        ]);

        $cardCode = '';
        $documentLines = [];
        if ($baseDeliveryDocEntry > 0) {
            $delivery = $this->getDeliveryNote($baseDeliveryDocEntry);
            $cardCode = (string) ($delivery['CardCode'] ?? '');
            $documentLines = $this->buildCreditLinesFromDelivery($items, $delivery, $hubCode, $baseDeliveryDocEntry, $data);
        }

        if ($documentLines === [] && $baseOrderDocEntry > 0) {
            $order = $this->getArReserveInvoice($baseOrderDocEntry);
            if ($cardCode === '') {
                $cardCode = (string) ($order['CardCode'] ?? '');
            }
            $documentLines = $this->buildDirectCreditLines($items, $hubCode, $data);
        }

        if ($documentLines === []) {
            $documentLines = $this->buildDirectCreditLines($items, $hubCode, $data);
        }

        if ($cardCode === '') {
            $seedId = (string) (
                data_get($data, 'order_reference_id')
                ?? data_get($data, 'return_order_id')
                ?? data_get($data, 'id')
                ?? $externalId
            );
            $cardCode = $this->resolveOrderCustomerCode($data, $seedId);
            $cardCode = $this->ensureCustomerExists($cardCode, $data, $externalId !== '' ? $externalId : $cardCode);
        }

        if ($documentLines === []) {
            return [
                'ignored' => true,
                'reason' => 'No SAP credit memo lines could be built',
                'request_body' => null,
            ];
        }
        $documentLines = $this->normalizeSapDocumentLines(
            $this->applyDefaultCostCentersToLines($documentLines)
        );

        $body = [
            'CardCode' => $cardCode,
            'DocDate' => $docDate,
            'DocDueDate' => $docDate,
            'TaxDate' => $taxDate,
            'DocumentLines' => $documentLines,
            'Comments' => 'AR Credit Memo from Omniful return ' . ($externalId !== '' ? $externalId : 'event'),
        ];

        $body = $this->appendOmnifulDocumentUdfs(
            $body,
            $data,
            $this->resolveOmnifulOrderReferenceForSap($data, $externalId)
        );

        $response = $this->post('/CreditNotes', $body);
        if (!$response->successful()) {
            throw new SapRequestException(
                'SAP AR credit memo create failed: ' . $response->status() . ' ' . $response->body(),
                $body,
                (string) $response->body(),
                $response->status(),
            );
        }

        $payload = $response->json() ?? [];
        $payload['ignored'] = false;
        $payload['request_body'] = $body;
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
                'request_body' => null,
            ];
        }

        $referenceDate = $this->formatDate((string) (($creditMemo['DocDate'] ?? null) ?: now()->format('Y-m-d')));
        $reference = trim((string) ($data['reference'] ?? ($creditMemo['NumAtCard'] ?? '')));
        $memo = trim((string) ($data['memo'] ?? ('COGS reversal for Credit Memo ' . ($creditMemo['DocNum'] ?? $creditMemoDocEntry))));

        $journalLines = $this->applyDefaultCostCentersToJournalLines([
            [
                'AccountCode' => $offsetAccount,
                'Debit' => $this->roundSapAmount($amount),
            ],
            [
                'AccountCode' => $expenseAccount,
                'Credit' => $this->roundSapAmount($amount),
            ],
        ], $this->resolveWarehouseCodeFromDocumentLines($creditMemo));

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
            throw new SapRequestException(
                'SAP COGS reversal journal create failed: ' . $response->status() . ' ' . $response->body(),
                $body,
                (string) $response->body(),
                $response->status(),
            );
        }

        $payload = $response->json() ?? [];
        $payload['ignored'] = false;
        $payload['request_body'] = $body;
        return $payload;
    }

    public function createPurchaseOrderFromOmniful(array $data): array
    {
        $docDate = $this->resolveOrderDocumentDate($data, [
            'document_date',
            'created_at',
        ]);
        $dueDate = $docDate;
        $taxDate = $this->resolveOrderTaxDate($data, $docDate, [
            'document_date',
            'created_at',
        ]);
        $currency = data_get($data, 'currency');
        $hubCode = data_get($data, 'hub_code');
        $displayId = data_get($data, 'display_id');

        $supplierCode = $this->resolvePurchaseOrderSupplierCode($data);
        $supplierCode = $this->ensureSupplierExists($supplierCode, $data);

        $lines = [];
        $lineIndex = 0;
        foreach ((array) data_get($data, 'purchase_order_items', []) as $item) {
            $lineIndex++;
            $itemCode = $this->resolvePurchaseOrderLineItemCode((array) $item);

            if (!$itemCode) {
                throw new \RuntimeException('Missing item code for SAP PO line');
            }

            $this->ensureItemExists($itemCode, $item, $lineIndex);
            $quantity = $this->resolvePurchaseOrderLineQuantity((array) $item);
            if ($quantity <= 0) {
                continue;
            }

            $line = [
                'ItemCode' => $itemCode,
                'Quantity' => $this->roundSapQuantity($quantity),
                'UnitPrice' => $this->roundSapAmount($this->resolvePurchaseOrderLineUnitPrice((array) $item)),
            ];

            $taxCode = $this->resolveSapTaxCodeForOrderLine($data, (array) $item);
            if ($taxCode !== '') {
                $line['VatGroup'] = $taxCode;
            }

            if ($hubCode) {
                $line['WarehouseCode'] = $this->ensureWarehouseExists($hubCode, $lineIndex);
            }

            $lines[] = $line;
        }

        if ($lines === []) {
            throw new \RuntimeException('No purchase_order_items found for SAP PO');
        }
        $lines = $this->normalizeSapDocumentLines(
            $this->applyDefaultCostCentersToLines($lines)
        );

        $comments = $displayId ? ('Omniful PO ' . $displayId) : 'Omniful PO';
        if ($currency && !$this->isValidCurrency($currency)) {
            $comments .= ' | Currency ' . $currency . ' not found in SAP; using local currency';
            $currency = null;
        }

        $body = [
            'CardCode' => $supplierCode,
            'DocDate' => $docDate,
            'DocDueDate' => $dueDate,
            'TaxDate' => $taxDate,
            'DocumentLines' => $lines,
            'Comments' => $comments,
        ];

        if ($currency) {
            $body['DocCurrency'] = $currency;
        }

        $response = $this->post('/PurchaseOrders', $body);
        if (!$response->successful() && $this->isSapInactiveVendorError((string) $response->body())) {
            $this->ensureSupplierIsActive($supplierCode, $data);
            $response = $this->post('/PurchaseOrders', $body);
        }
        if (!$response->successful() && $this->isSapUomCodeRequiredError($response->body())) {
            $response = $this->retryPurchaseOrderWithResolvedUom($body);
        }

        if (!$response->successful()) {
            throw new \RuntimeException('SAP PO create failed: ' . $response->status() . ' ' . $response->body());
        }

        return $response->json() ?? [];
    }

    public function createAccountsPayableDocument(string $documentType, array $data): array
    {
        $config = $this->resolveAccountsPayableDocumentConfig($documentType);
        $cardCode = trim((string) (
            data_get($data, 'card_code')
            ?? data_get($data, 'supplier.code')
            ?? data_get($data, 'supplier.supplier_code')
            ?? data_get($data, 'supplier.vendor_code')
            ?? ''
        ));

        if ($cardCode === '') {
            $cardCode = $this->resolvePurchaseOrderSupplierCode($data);
        }
        $cardCode = $this->ensureSupplierExists($cardCode, $data);

        $lines = $this->buildAccountsPayableDocumentLines($data);
        if ($lines === []) {
            return [
                'ignored' => true,
                'reason' => 'No A/P document lines found',
            ];
        }
        $lines = $this->applyDefaultCostCentersToLines($lines);

        $docDate = $this->formatDate((string) ($data['doc_date'] ?? now()->format('Y-m-d')));
        $body = [
            'CardCode' => $cardCode,
            'DocDate' => $docDate,
            'DocDueDate' => $docDate,
            'Comments' => trim((string) ($data['remarks'] ?? $config['label'] . ' from integration')),
            'DocumentLines' => $lines,
        ];

        $currency = trim((string) ($data['currency'] ?? ''));
        if ($currency !== '') {
            if (!$this->isValidCurrency($currency)) {
                throw new \RuntimeException('Invalid SAP currency for A/P document: ' . $currency);
            }
            $body['DocCurrency'] = $currency;
        }

        $response = $this->post($config['path'], $body);
        if (!$response->successful()) {
            throw new \RuntimeException(
                'SAP ' . $config['label'] . ' create failed: ' . $response->status() . ' ' . $response->body()
                . ' | Payload: ' . json_encode($body, JSON_UNESCAPED_UNICODE)
            );
        }

        $payload = $response->json() ?? [];
        $payload['ignored'] = false;
        $payload['document_type'] = $config['type'];

        return $payload;
    }

    public function createVendorPayment(array $data): array
    {
        $invoiceDocEntry = (int) ($data['invoice_doc_entry'] ?? 0);
        if ($invoiceDocEntry <= 0) {
            throw new \RuntimeException('Missing invoice doc entry for vendor payment');
        }

        $cardCode = trim((string) ($data['card_code'] ?? ''));
        if ($cardCode === '') {
            throw new \RuntimeException('Missing CardCode for vendor payment');
        }

        $transferAccount = trim((string) ($data['transfer_account'] ?? ''));
        if ($transferAccount === '') {
            return [
                'ignored' => true,
                'reason' => 'Missing vendor payment transfer account',
            ];
        }

        $sumApplied = $this->roundSapAmount((float) ($data['sum_applied'] ?? 0));
        if ($sumApplied <= 0) {
            return [
                'ignored' => true,
                'reason' => 'Vendor payment skipped: non-positive amount',
            ];
        }

        $invoiceType = (int) ($data['invoice_type'] ?? 18);
        $transferDate = $this->formatDate((string) ($data['transfer_date'] ?? now()->format('Y-m-d')));
        $remarks = trim((string) ($data['remarks'] ?? 'Vendor payment from integration'));

        $body = [
            'CardCode' => $cardCode,
            'DocType' => 'rSupplier',
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
            'Remarks' => $remarks,
        ];

        $response = $this->post('/VendorPayments', $body);
        if (!$response->successful()) {
            throw new \RuntimeException(
                'SAP vendor payment create failed: ' . $response->status() . ' ' . $response->body()
                . ' | Payload: ' . json_encode($body, JSON_UNESCAPED_UNICODE)
            );
        }

        $payload = $response->json() ?? [];
        $payload['ignored'] = false;

        return $payload;
    }

    public function createAccountsReceivableDocument(string $documentType, array $data): array
    {
        $config = $this->resolveAccountsReceivableDocumentConfig($documentType);
        $cardCode = trim((string) (
            data_get($data, 'card_code')
            ?? data_get($data, 'customer.code')
            ?? ''
        ));

        if ($cardCode === '') {
            $externalId = trim((string) (
                data_get($data, 'external_id')
                ?? data_get($data, 'display_id')
                ?? data_get($data, 'id')
                ?? ''
            ));
            $cardCode = $this->buildCustomerCode($data, $externalId !== '' ? $externalId : 'manual-ar-document');
        }

        $cardCode = $this->ensureCustomerExists($cardCode, $data, (string) ($data['external_id'] ?? $cardCode));

        $lines = $this->buildAccountsReceivableDocumentLines($data);
        if ($lines === []) {
            return [
                'ignored' => true,
                'reason' => 'No A/R document lines found',
            ];
        }
        $lines = $this->applyDefaultCostCentersToLines($lines);

        $docDate = $this->formatDate((string) ($data['doc_date'] ?? now()->format('Y-m-d')));
        $body = [
            'CardCode' => $cardCode,
            'DocDate' => $docDate,
            'DocDueDate' => $docDate,
            'Comments' => trim((string) ($data['remarks'] ?? $config['label'] . ' from integration')),
            'DocumentLines' => $lines,
        ];

        $currency = trim((string) ($data['currency'] ?? ''));
        if ($currency !== '') {
            if (!$this->isValidCurrency($currency)) {
                throw new \RuntimeException('Invalid SAP currency for A/R document: ' . $currency);
            }
            $body['DocCurrency'] = $currency;
        }

        $response = $this->post($config['path'], $body);
        if (!$response->successful()) {
            throw new \RuntimeException(
                'SAP ' . $config['label'] . ' create failed: ' . $response->status() . ' ' . $response->body()
                . ' | Payload: ' . json_encode($body, JSON_UNESCAPED_UNICODE)
            );
        }

        $payload = $response->json() ?? [];
        $payload['ignored'] = false;
        $payload['document_type'] = $config['type'];

        return $payload;
    }

    public function createDeposit(array $data): array
    {
        $absId = (int) ($data['abs_id'] ?? 0);
        $depositAccount = trim((string) ($data['deposit_account'] ?? ''));
        $voucherAccount = trim((string) ($data['voucher_account'] ?? ''));
        $depositType = trim((string) ($data['deposit_type'] ?? 'dtCredit'));

        if ($absId <= 0) {
            throw new \RuntimeException('Deposit requires a valid credit line AbsId');
        }

        if ($depositAccount === '' || $voucherAccount === '') {
            throw new \RuntimeException('Deposit requires deposit_account and voucher_account');
        }

        $body = [
            'CreditLines' => [
                ['AbsId' => $absId],
            ],
            'DepositAccount' => $depositAccount,
            'DepositType' => $depositType,
            'VoucherAccount' => $voucherAccount,
        ];

        $response = $this->post('/Deposits', $body);
        if (!$response->successful()) {
            throw new \RuntimeException(
                'SAP deposit create failed: ' . $response->status() . ' ' . $response->body()
                . ' | Payload: ' . json_encode($body, JSON_UNESCAPED_UNICODE)
            );
        }

        $payload = $response->json() ?? [];
        $payload['ignored'] = false;

        return $payload;
    }

    public function createCheckForPayment(array $data): array
    {
        $bankCode = trim((string) ($data['bank_code'] ?? ''));
        $customerAccountCode = trim((string) ($data['customer_account_code'] ?? ''));
        $countryCode = trim((string) ($data['country_code'] ?? ''));
        $rowTotal = (float) ($data['amount'] ?? 0);

        if ($bankCode === '' || $customerAccountCode === '' || $countryCode === '' || $rowTotal <= 0) {
            throw new \RuntimeException('Check for payment requires bank_code, customer_account_code, country_code, and positive amount');
        }

        $body = [
            'BankCode' => $bankCode,
            'CustomerAccountCode' => $customerAccountCode,
            'CountryCode' => $countryCode,
            'CardOrAccount' => trim((string) ($data['card_or_account'] ?? 'cfp_Account')),
            'ChecksforPaymentLines' => [
                [
                    'RowTotal' => $rowTotal,
                ],
            ],
        ];

        $optionalFields = [
            'AccountNumber' => trim((string) ($data['account_number'] ?? '')),
            'Branch' => trim((string) ($data['branch'] ?? '')),
            'Details' => trim((string) ($data['details'] ?? '')),
            'VendorCode' => trim((string) ($data['vendor_code'] ?? '')),
        ];

        foreach ($optionalFields as $field => $value) {
            if ($value !== '') {
                $body[$field] = $value;
            }
        }

        $response = $this->post('/ChecksforPayment', $body);
        if (!$response->successful()) {
            throw new \RuntimeException(
                'SAP check for payment create failed: ' . $response->status() . ' ' . $response->body()
                . ' | Payload: ' . json_encode($body, JSON_UNESCAPED_UNICODE)
            );
        }

        $payload = $response->json() ?? [];
        $payload['ignored'] = false;

        return $payload;
    }

    private function resolvePurchaseOrderSupplierCode(array $data): string
    {
        $candidates = [
            data_get($data, 'supplier.code'),
            data_get($data, 'supplier.supplier_code'),
            data_get($data, 'supplier.vendor_code'),
            data_get($data, 'supplier.card_code'),
        ];

        foreach ($candidates as $candidate) {
            $value = trim((string) $candidate);
            if ($value !== '') {
                return $value;
            }
        }

        $seed = (string) (
            data_get($data, 'supplier.id')
            ?? data_get($data, 'supplier.email')
            ?? $this->extractSupplierPhone($data)
            ?? data_get($data, 'supplier.name')
            ?? data_get($data, 'display_id')
            ?? data_get($data, 'id')
            ?? ''
        );

        if ($seed === '') {
            throw new \RuntimeException('Missing supplier identifier for SAP PO (supplier.code / supplier.id)');
        }

        return 'OMNS' . strtoupper(substr(sha1($seed), 0, 10));
    }

    private function resolvePurchaseOrderLineItemCode(array $item): string
    {
        $candidates = [
            data_get($item, 'sku.seller_sku_code'),
            data_get($item, 'sku.seller_sku_id'),
            data_get($item, 'seller_sku_code'),
            data_get($item, 'sku_code'),
            data_get($item, 'sku.code'),
            data_get($item, 'item_code'),
        ];

        foreach ($candidates as $candidate) {
            $value = trim((string) $candidate);
            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }

    private function resolvePurchaseOrderLineQuantity(array $item): float
    {
        $candidates = [
            data_get($item, 'quantity'),
            data_get($item, 'ordered_quantity'),
            data_get($item, 'requested_quantity'),
            data_get($item, 'approved_quantity'),
            data_get($item, 'received_quantity'),
        ];

        foreach ($candidates as $candidate) {
            if (is_numeric($candidate) && (float) $candidate > 0) {
                return (float) $candidate;
            }
        }

        return 0.0;
    }

    private function resolvePurchaseOrderLineUnitPrice(array $item): float
    {
        $candidates = [
            data_get($item, 'unit_price'),
            data_get($item, 'buying_price'),
            data_get($item, 'purchase_price'),
            data_get($item, 'cost'),
            data_get($item, 'price'),
            data_get($item, 'sku.cost'),
        ];

        foreach ($candidates as $candidate) {
            if (is_numeric($candidate) && (float) $candidate >= 0) {
                return (float) $candidate;
            }
        }

        $total = data_get($item, 'total');
        $quantity = $this->resolvePurchaseOrderLineQuantity($item);
        if (is_numeric($total) && (float) $total > 0 && $quantity > 0) {
            return $this->roundSapAmount((float) $total / $quantity);
        }

        return 0.0;
    }

    /**
     * @return array{path:string,label:string,type:string}
     */
    private function resolveAccountsPayableDocumentConfig(string $documentType): array
    {
        $normalized = strtolower(trim($documentType));

        return match ($normalized) {
            'invoice', 'purchase_invoice', 'purchase-invoice' => [
                'path' => '/PurchaseInvoices',
                'label' => 'purchase invoice',
                'type' => 'purchase_invoice',
            ],
            'credit_note', 'credit-note', 'purchase_credit_note', 'purchase-credit-note' => [
                'path' => '/PurchaseCreditNotes',
                'label' => 'purchase credit note',
                'type' => 'purchase_credit_note',
            ],
            'down_payment', 'down-payment', 'purchase_down_payment', 'purchase-down-payment' => [
                'path' => '/PurchaseDownPayments',
                'label' => 'purchase down payment',
                'type' => 'purchase_down_payment',
            ],
            default => throw new \RuntimeException('Unsupported A/P document type: ' . $documentType),
        };
    }

    /**
     * @return array{path:string,label:string,type:string}
     */
    private function resolveAccountsReceivableDocumentConfig(string $documentType): array
    {
        $normalized = strtolower(trim($documentType));

        return match ($normalized) {
            'invoice', 'ar_invoice', 'ar-invoice' => [
                'path' => '/Invoices',
                'label' => 'A/R invoice',
                'type' => 'invoice',
            ],
            'return', 'returns', 'ar_return', 'ar-return' => [
                'path' => '/Returns',
                'label' => 'A/R return',
                'type' => 'return',
            ],
            default => throw new \RuntimeException('Unsupported A/R document type: ' . $documentType),
        };
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function buildAccountsPayableDocumentLines(array $data): array
    {
        $items = $this->extractAccountsPayableDocumentItems($data);
        $hubCode = trim((string) (
            data_get($data, 'hub_code')
            ?? data_get($data, 'warehouse_code')
            ?? ''
        ));

        $lines = [];
        $lineIndex = 0;
        foreach ($items as $item) {
            $lineIndex++;
            $itemCode = $this->resolvePurchaseOrderLineItemCode((array) $item);
            $quantity = $this->resolvePurchaseOrderLineQuantity((array) $item);
            if ($itemCode === '' || $quantity <= 0) {
                continue;
            }

            $this->ensureItemExists($itemCode, (array) $item, $lineIndex);

            $line = [
                'ItemCode' => $itemCode,
                'Quantity' => $this->roundSapQuantity($quantity),
                'UnitPrice' => $this->roundSapAmount($this->resolvePurchaseOrderLineUnitPrice((array) $item)),
            ];

            $taxCode = $this->resolveSapTaxCodeForOrderLine($data, (array) $item);
            if ($taxCode !== '') {
                $line['VatGroup'] = $taxCode;
            }

            $lineWarehouse = trim((string) (
                data_get($item, 'warehouse_code')
                ?? data_get($item, 'warehouse')
                ?? data_get($item, 'hub_code')
                ?? $hubCode
            ));

            if ($lineWarehouse !== '') {
                $line['WarehouseCode'] = $this->ensureWarehouseExists($lineWarehouse, $lineIndex);
                $this->ensureItemWarehouseExists($itemCode, $line['WarehouseCode']);
            }

            $lines[] = $line;
        }

        return $lines;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function buildAccountsReceivableDocumentLines(array $data): array
    {
        $items = $this->extractAccountsPayableDocumentItems($data);
        $hubCode = trim((string) (
            data_get($data, 'hub_code')
            ?? data_get($data, 'warehouse_code')
            ?? ''
        ));

        $lines = [];
        $lineIndex = 0;
        foreach ($items as $item) {
            $lineIndex++;
            $itemCode = $this->resolvePurchaseOrderLineItemCode((array) $item);
            $quantity = $this->resolvePurchaseOrderLineQuantity((array) $item);
            if ($itemCode === '' || $quantity <= 0) {
                continue;
            }

            $this->ensureItemExists($itemCode, (array) $item, $lineIndex);
            $this->ensureItemCanBeSold($itemCode, $lineIndex);

            $line = [
                'ItemCode' => $itemCode,
                'Quantity' => $this->roundSapQuantity($quantity),
                'UnitPrice' => $this->roundSapAmount($this->resolvePurchaseOrderLineUnitPrice((array) $item)),
            ];

            $lineWarehouse = trim((string) (
                data_get($item, 'warehouse_code')
                ?? data_get($item, 'warehouse')
                ?? data_get($item, 'hub_code')
                ?? $hubCode
            ));

            if ($lineWarehouse !== '') {
                $line['WarehouseCode'] = $this->ensureWarehouseExists($lineWarehouse, $lineIndex);
                $this->ensureItemWarehouseExists($itemCode, $line['WarehouseCode']);
            }

            $lines[] = $line;
        }

        return $lines;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function extractAccountsPayableDocumentItems(array $data): array
    {
        $sources = [
            data_get($data, 'items', []),
            data_get($data, 'order_items', []),
            data_get($data, 'document_lines', []),
        ];

        foreach ($sources as $source) {
            if (!is_array($source) || $source === []) {
                continue;
            }

            $rows = [];
            foreach ($source as $row) {
                if (is_array($row)) {
                    $rows[] = $row;
                }
            }

            if ($rows !== []) {
                return $rows;
            }
        }

        $itemCode = trim((string) (
            data_get($data, 'item_code')
            ?? data_get($data, 'sku_code')
            ?? ''
        ));
        $quantity = data_get($data, 'quantity');

        if ($itemCode !== '' && is_numeric($quantity) && (float) $quantity > 0) {
            return [[
                'item_code' => $itemCode,
                'quantity' => (float) $quantity,
                'unit_price' => (float) (data_get($data, 'unit_price') ?? data_get($data, 'price') ?? 0),
                'warehouse_code' => data_get($data, 'warehouse_code') ?? data_get($data, 'hub_code'),
            ]];
        }

        return [];
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

    public function syncSalesOrderFromOmnifulEvent(int $docEntry, string $eventName, string $status): void
    {
        if ($docEntry <= 0) {
            throw new \RuntimeException('Missing SAP sales order doc entry for sync');
        }

        $salesOrder = $this->getArReserveInvoice($docEntry);
        $payload = $this->buildSalesOrderSyncPayload($salesOrder, $eventName, $status);
        if ($payload === []) {
            return;
        }

        $response = $this->patch('/Invoices(' . $docEntry . ')', $payload);
        if (!$response->successful() && count($payload) > 1) {
            $fallback = $payload;
            foreach (array_keys($payload) as $field) {
                if ($field === 'Comments') {
                    continue;
                }

                if ($this->isInvalidSapPropertyError((string) $response->body(), $field)) {
                    unset($fallback[$field]);
                }
            }

            if ($fallback !== $payload && $fallback !== []) {
                $response = $this->patch('/Invoices(' . $docEntry . ')', $fallback);
            }
        }

        if (!$response->successful()) {
            throw new \RuntimeException('SAP reserve invoice update failed: ' . $response->status() . ' ' . $response->body());
        }
    }




    private function ensureSupplierExists(string $cardCode, array $data): string
    {
        $existing = $this->getBusinessPartner($cardCode);
        if ($existing) {
            $this->ensureSupplierIsActive($cardCode, $data, $existing);
            return $cardCode;
        }

        $supplier = (array) data_get($data, 'supplier', []);
        $cardName = data_get($supplier, 'name') ?: $cardCode;
        $phone = $this->extractSupplierPhone($data);
        $email = data_get($supplier, 'email');
        $phoneValue = $this->normalizePhoneForSap($phone);

        $resolvedByIdentity = $this->resolveExistingSupplierCodeForDuplication($phone, (string) $cardName, $email);
        if ($resolvedByIdentity !== null) {
            return $resolvedByIdentity;
        }

        $body = [
            'CardCode' => $cardCode,
            'CardName' => $cardName,
            'CardType' => 'S',
        ];

        if ($email) {
            $body['EmailAddress'] = $email;
        }
        if ($phoneValue !== '') {
            $body['Phone1'] = $phoneValue;
            $body['Cellular'] = $phoneValue;
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

            throw new \RuntimeException(
                'SAP vendor create blocked by duplicate mobile; could not auto-resolve existing BP'
                . ' (supplier_code=' . $cardCode
                . ', phone=' . ($phone !== '' ? $phone : '-')
                . ', email=' . (trim((string) ($email ?? '')) !== '' ? (string) $email : '-')
                . '). Original error: ' . $response->status() . ' ' . $response->body()
            );
        }

        throw new \RuntimeException('SAP vendor create failed: ' . $response->status() . ' ' . $response->body());
    }

    private function ensureSupplierIsActive(string $cardCode, array $data, ?array $existing = null): void
    {
        $existing ??= $this->getBusinessPartner($cardCode);
        if (!$existing) {
            return;
        }

        if (!$this->isBusinessPartnerInactive($existing)) {
            return;
        }

        $supplier = (array) data_get($data, 'supplier', []);
        $cardName = (string) (data_get($supplier, 'name') ?: ($existing['CardName'] ?? $cardCode));
        $email = data_get($supplier, 'email') ?: ($existing['EmailAddress'] ?? null);
        $phone = $this->extractSupplierPhone($data);
        $phoneValue = $this->normalizePhoneForSap($phone);

        $patch = array_filter([
            'CardName' => $cardName,
            'EmailAddress' => $email ?: null,
            'Phone1' => $phoneValue !== '' ? $phoneValue : null,
            'Cellular' => $phoneValue !== '' ? $phoneValue : null,
            'Valid' => 'tYES',
            'Frozen' => 'tNO',
        ], fn ($value) => $value !== null && $value !== '');

        $encoded = str_replace("'", "''", $cardCode);
        $response = $this->patch("/BusinessPartners('{$encoded}')", $patch);

        if (!$response->successful()) {
            $fallback = $patch;
            unset($fallback['Valid'], $fallback['Frozen']);
            $fallback['frozen'] = 'tNO';
            $fallback['validFor'] = 'tYES';

            $response = $this->patch("/BusinessPartners('{$encoded}')", $fallback);
        }

        if (!$response->successful()) {
            throw new \RuntimeException('SAP vendor activation failed: ' . $response->status() . ' ' . $response->body());
        }
    }

    private function isBusinessPartnerInactive(array $bp): bool
    {
        $valid = strtolower(trim((string) ($bp['Valid'] ?? $bp['validFor'] ?? '')));
        if ($valid !== '' && in_array($valid, ['tno', 'no', 'n', 'false', '0', 'inactive'], true)) {
            return true;
        }

        $active = strtolower(trim((string) ($bp['Active'] ?? '')));
        if ($active !== '' && in_array($active, ['tno', 'no', 'n', 'false', '0', 'inactive'], true)) {
            return true;
        }

        $frozen = strtolower(trim((string) ($bp['Frozen'] ?? $bp['frozenFor'] ?? '')));
        if ($frozen !== '' && in_array($frozen, ['tyes', 'yes', 'y', 'true', '1', 'frozen'], true)) {
            return true;
        }

        return false;
    }

    private function isSapInactiveVendorError(string $body): bool
    {
        $normalized = strtolower($body);

        return str_contains($normalized, 'vendor')
            && str_contains($normalized, 'inactive');
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
        $phoneValue = $this->normalizePhoneForSap((string) ($phone ?? ''));
        $existing = $this->getBusinessPartner($cardCode);

        $payload = array_filter([
            'CardCode' => $cardCode,
            'CardName' => $cardName,
            'CardType' => 'S',
            'EmailAddress' => $email ?: null,
            'Phone1' => $phoneValue !== '' ? $phoneValue : null,
            'Cellular' => $phoneValue !== '' ? $phoneValue : null,
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

        $docDate = now()->format('Y-m-d');

        $body = [
            'CardCode' => $po['CardCode'] ?? null,
            'DocDate' => $docDate,
            'TaxDate' => $docDate,
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
        $defaultWarehouse = data_get($data, 'hub_code') ?? data_get($data, 'warehouse') ?? null;

        $sources = [
            data_get($data, 'child_skus', []),
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
                    'warehouse' => data_get($row, 'hub_code') ?? data_get($row, 'warehouse') ?? $defaultWarehouse,
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
            if ($this->isInvalidSapPropertyError((string) $response->body(), $udfField)) {
                Log::warning('SAP item integration UDF field not found; bypassing item UDF control', [
                    'item_code' => $itemCode,
                    'udf_field' => $udfField,
                    'status' => $response->status(),
                ]);

                return true;
            }

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

    private function getArReserveInvoice(int $docEntry): array
    {
        $response = $this->get('/Invoices(' . $docEntry . ')');

        if (!$response->successful()) {
            throw new \RuntimeException('SAP reserve invoice fetch failed: ' . $response->status() . ' ' . $response->body());
        }

        return $response->json() ?? [];
    }

    private function resolveSapTransferAccountValue(string $configured): string
    {
        $configured = trim($configured);
        if ($configured === '') {
            return '';
        }

        $bankAccount = SapBankAccount::query()
            ->where('account_code', $configured)
            ->first();

        if (!$bankAccount) {
            return $configured;
        }

        $payload = (array) ($bankAccount->payload ?? []);
        $glAccount = trim((string) ($payload['GLAccount'] ?? ''));

        return $glAccount !== '' ? $glAccount : $configured;
    }

    private function buildSalesOrderSyncPayload(array $salesOrder, string $eventName, string $status): array
    {
        $payload = [];

        if ((bool) config('omniful.order_sync.append_comment', true)) {
            $message = trim(sprintf(
                '[%s] %s %s',
                now()->format('Y-m-d H:i:s'),
                trim($eventName) !== '' ? $eventName : 'order.event',
                trim($status) !== '' ? $status : '-'
            ));

            $existing = trim((string) ($salesOrder['Comments'] ?? ''));
            $payload['Comments'] = $existing === '' ? $message : ($existing . "\n" . $message);
        }

        $statusField = trim((string) config('omniful.order_sync.status_udf_field', ''));
        if ($statusField !== '' && trim($status) !== '') {
            $payload[$statusField] = trim($status);
        }

        $eventField = trim((string) config('omniful.order_sync.event_udf_field', ''));
        if ($eventField !== '' && trim($eventName) !== '') {
            $payload[$eventField] = trim($eventName);
        }

        $updatedAtField = trim((string) config('omniful.order_sync.updated_at_udf_field', ''));
        if ($updatedAtField !== '') {
            $payload[$updatedAtField] = now()->format('Y-m-d H:i:s');
        }

        return $payload;
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
        $configuredCustomerCode = $this->resolveConfiguredSourceCustomerCode($data)
            ?? $this->resolveConfiguredFallbackCustomerCode('Omniful Customer ' . $externalId, null);
        if ($configuredCustomerCode !== null) {
            return $configuredCustomerCode;
        }

        $seed = (string) (
            data_get($data, 'customer.email')
            ?? data_get($data, 'customer.phone')
            ?? data_get($data, 'billing_address.phone')
            ?? $externalId
        );
        $hash = strtoupper(substr(sha1($seed), 0, 10));
        return 'OMNC' . $hash;
    }

    private function resolveOrderCustomerCode(array $data, string $externalId): string
    {
        $mappedCustomerCode = $this->resolveMappedOrderCustomerCode($data);
        if ($mappedCustomerCode !== null) {
            return $mappedCustomerCode;
        }

        $configuredCustomerCode = $this->resolveConfiguredSourceCustomerCode($data);
        if ($configuredCustomerCode !== null) {
            return $configuredCustomerCode;
        }

        $explicitCustomerCode = trim((string) data_get($data, 'customer.code', ''));
        if ($explicitCustomerCode !== '') {
            return $explicitCustomerCode;
        }

        return $this->buildCustomerCode($data, $externalId);
    }

    private function resolveMappedOrderCustomerCode(array $data): ?string
    {
        $localCustomerCode = trim((string) (
            $this->getIntegrationSettingValue('order_local_customer_code')
            ?? config('omniful.order_customer_mapping.local_customer_code', 'C00046')
        ));
        $foreignCustomerCode = trim((string) (
            $this->getIntegrationSettingValue('order_foreign_customer_code')
            ?? config('omniful.order_customer_mapping.foreign_customer_code', 'C00047')
        ));

        if ($localCustomerCode === '' || $foreignCustomerCode === '') {
            return null;
        }

        return $this->isLocalOrderCustomer($data) ? $localCustomerCode : $foreignCustomerCode;
    }

    private function isLocalOrderCustomer(array $data): bool
    {
        $countryCandidates = [
            data_get($data, 'customer.country'),
            data_get($data, 'customer.country_code'),
            data_get($data, 'billing_address.country'),
            data_get($data, 'billing_address.country_code'),
            data_get($data, 'shipping_address.country'),
            data_get($data, 'shipping_address.country_code'),
        ];

        $localCountryTokens = array_map(
            fn ($value) => strtoupper(trim((string) $value)),
            (array) config('omniful.order_customer_mapping.local_country_tokens', ['SA', 'SAU', 'KSA', 'SAUDI ARABIA'])
        );

        foreach ($countryCandidates as $candidate) {
            $normalized = strtoupper(trim((string) ($candidate ?? '')));
            if ($normalized !== '' && in_array($normalized, $localCountryTokens, true)) {
                return true;
            }
        }

        $phoneCandidates = [
            data_get($data, 'customer.mobile'),
            data_get($data, 'customer.phone'),
            data_get($data, 'billing_address.phone'),
            data_get($data, 'shipping_address.phone'),
        ];

        foreach ($phoneCandidates as $candidate) {
            $digits = preg_replace('/\D+/', '', (string) ($candidate ?? '')) ?? '';
            if ($digits === '') {
                continue;
            }

            if (str_starts_with($digits, '966') || str_starts_with($digits, '05') || (str_starts_with($digits, '5') && strlen($digits) === 9)) {
                return true;
            }
        }

        return false;
    }

    private function resolveConfiguredSourceCustomerCode(array $data): ?string
    {
        $source = $this->extractOrderSourceKey($data);
        if ($source === '') {
            return null;
        }

        $map = $this->getOrderFallbackCustomerCodeBySourceMap();
        if (!is_array($map)) {
            return null;
        }

        $customerCode = trim((string) ($map[$source] ?? ''));
        return $customerCode !== '' ? $customerCode : null;
    }

    private function extractOrderSourceKey(array $data): string
    {
        $candidates = [
            data_get($data, 'source'),
            data_get($data, 'source_name'),
            data_get($data, 'source_code'),
            data_get($data, 'order_source'),
            data_get($data, 'order_source_name'),
            data_get($data, 'channel'),
            data_get($data, 'channel_name'),
            data_get($data, 'channel_code'),
            data_get($data, 'sales_channel'),
            data_get($data, 'sales_channel.name'),
            data_get($data, 'sales_channel.code'),
            data_get($data, 'platform'),
            data_get($data, 'platform_name'),
            data_get($data, 'store.name'),
            data_get($data, 'store.code'),
        ];

        foreach ($candidates as $candidate) {
            if (is_array($candidate)) {
                $candidate = $candidate['code'] ?? $candidate['name'] ?? null;
            }

            $normalized = $this->normalizeSourceKey((string) ($candidate ?? ''));
            if ($normalized !== '') {
                return $normalized;
            }
        }

        return '';
    }

    private function normalizeSourceKey(string $value): string
    {
        $normalized = strtolower(trim($value));
        if ($normalized === '') {
            return '';
        }

        $normalized = preg_replace('/[^a-z0-9]+/', '_', $normalized) ?? '';
        return trim($normalized, '_');
    }

    private function resolveOmnifulOrderReferenceForSap(array $data, string $fallback = ''): string
    {
        $candidates = [
            data_get($data, 'external_id'),
            data_get($data, 'order_reference_id'),
            data_get($data, 'display_id'),
            data_get($data, 'order_id'),
            data_get($data, 'data.external_id'),
            data_get($data, 'data.order_reference_id'),
            $fallback,
        ];

        foreach ($candidates as $candidate) {
            $value = trim((string) ($candidate ?? ''));
            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }

    private function resolveOmnifulChannelForSap(array $data): string
    {
        $candidates = [
            data_get($data, 'sales_channel.name'),
            data_get($data, 'sales_channel'),
            data_get($data, 'channel_name'),
            data_get($data, 'channel'),
            data_get($data, 'source_name'),
            data_get($data, 'source'),
            data_get($data, 'platform_name'),
            data_get($data, 'platform'),
            data_get($data, 'store_name'),
            data_get($data, 'store.name'),
        ];

        foreach ($candidates as $candidate) {
            if (is_array($candidate)) {
                $candidate = $candidate['name'] ?? $candidate['code'] ?? null;
            }

            $value = trim((string) ($candidate ?? ''));
            if ($value !== '') {
                return mb_substr($value, 0, 100);
            }
        }

        return '';
    }

    private function appendOmnifulDocumentUdfs(array $body, array $data, string $fallbackOrderReference = ''): array
    {
        $orderField = trim((string) config('omniful.order_sync.order_number_udf_field', 'U_omo'));
        $channelField = trim((string) config('omniful.order_sync.channel_udf_field', 'U_omChannel'));
        $zidField = 'U_ZidId';
        $sallaField = 'U_SallaOrderId';

        $orderReference = $this->resolveOmnifulOrderReferenceForSap($data, $fallbackOrderReference);
        if ($orderField !== '' && $orderReference !== '') {
            $body[$orderField] = $orderReference;
        }

        $channel = $this->resolveOmnifulChannelForSap($data);
        if ($channelField !== '' && $channel !== '') {
            $body[$channelField] = $channel;
        }

        $channelKey = $this->resolveOmnifulChannelKey($data);
        if ($orderReference !== '') {
            if ($channelKey === 'zid') {
                $body[$zidField] = $orderReference;
            }

            if ($channelKey === 'salla') {
                $body[$sallaField] = $orderReference;
            }
        }

        return $body;
    }

    private function resolveOmnifulChannelKey(array $data): string
    {
        $candidates = [
            data_get($data, 'sales_channel.tag'),
            data_get($data, 'sales_channel.name'),
            data_get($data, 'source'),
            data_get($data, 'source_name'),
            data_get($data, 'channel'),
            data_get($data, 'channel_name'),
            data_get($data, 'platform'),
            data_get($data, 'platform_name'),
        ];

        foreach ($candidates as $candidate) {
            $value = $this->normalizeSourceKey((string) ($candidate ?? ''));
            if ($value === '') {
                continue;
            }

            if (str_contains($value, 'zid')) {
                return 'zid';
            }

            if (str_contains($value, 'salla')) {
                return 'salla';
            }
        }

        return '';
    }

    private function appendFreightToMarketingDocument(array $body, array $data): array
    {
        $freightAmount = $this->extractOrderFreightNetAmount($data);
        $expenseCode = (int) (
            $this->getIntegrationSettingValue('order_freight_expense_code')
            ?? config('omniful.order_freight.expense_code', 0)
        );
        $freightTaxCode = $this->resolveSapTaxCodeForOrderLine($data, [
            'tax_percent' => $this->extractOrderFreightTaxPercent($data),
        ]);

        if ($freightAmount <= 0 || $expenseCode <= 0) {
            return $body;
        }

        $expenseLine = [
            'ExpenseCode' => $expenseCode,
            'LineTotal' => $this->roundSapAmount($freightAmount),
        ];

        if ($freightTaxCode !== '') {
            $expenseLine['VatGroup'] = $freightTaxCode;
        }

        $body['DocumentAdditionalExpenses'] = [$expenseLine];

        return $body;
    }

    private function extractOrderFreightNetAmount(array $data): float
    {
        $grossAmount = $this->extractOrderFreightGrossAmount($data);
        if ($grossAmount <= 0) {
            return 0.0;
        }

        // SAP recomputes tax on the freight expense line from VatGroup, so we
        // must hand SAP the *net* freight (pre-tax). Otherwise SAP adds VAT on
        // top of an already gross figure and the AR reserve invoice's grand
        // total exceeds Omniful's order total — preventing the Incoming
        // Payment from settling the invoice cleanly.
        //
        // Strategy (point 6 of the BRS):
        // - If Omniful already reports a shipping tax amount, subtract it.
        // - Else if shipping is flagged tax_inclusive, divide by (1 + rate).
        // - Else (freight is already net) return the amount as-is.
        $shippingTax = data_get($data, 'invoice.shipping_tax');
        if (is_numeric($shippingTax) && (float) $shippingTax > 0) {
            return $this->roundSapAmount(max($grossAmount - (float) $shippingTax, 0.0));
        }

        $taxInclusive = filter_var(
            data_get($data, 'invoice.shipping_tax_inclusive', false),
            FILTER_VALIDATE_BOOL,
        );
        if ($taxInclusive) {
            $taxPercent = $this->extractOrderFreightTaxPercent($data);
            if ($taxPercent > 0) {
                return $this->roundSapAmount($grossAmount / (1 + ($taxPercent / 100)));
            }
        }

        return $this->roundSapAmount($grossAmount);
    }

    private function extractOrderFreightGrossAmount(array $data): float
    {
        $candidates = [
            data_get($data, 'invoice.shipping_price'),
            data_get($data, 'invoice.shipping_fee'),
            data_get($data, 'invoice.delivery_fee'),
            data_get($data, 'invoice.freight'),
            data_get($data, 'shipping_price'),
            data_get($data, 'shipping_fee'),
            data_get($data, 'delivery_fee'),
            data_get($data, 'shipment_fee'),
            data_get($data, 'freight_amount'),
            data_get($data, 'additional_charges.shipping_price'),
            data_get($data, 'additional_charges.delivery_fee'),
        ];

        foreach ($candidates as $candidate) {
            if (is_numeric($candidate) && (float) $candidate > 0) {
                $discount = $this->extractOrderFreightDiscountAmount($data);

                return $this->roundSapAmount(max(((float) $candidate) - $discount, 0));
            }
        }

        return 0.0;
    }

    private function extractOrderFreightDiscountAmount(array $data): float
    {
        $charges = (array) data_get($data, 'invoice.additional_charges', data_get($data, 'additional_charges', []));
        $discount = 0.0;

        foreach ($charges as $charge) {
            $type = strtolower(trim((string) data_get($charge, 'type', '')));
            if (!in_array($type, ['shipment_fee', 'shipping_fee', 'delivery_fee', 'freight'], true)) {
                continue;
            }

            $discountAmount = data_get($charge, 'discount_amount');
            if (is_numeric($discountAmount) && (float) $discountAmount > 0) {
                $discount += (float) $discountAmount;
            }
        }

        return $this->roundSapAmount($discount);
    }

    private function extractOrderFreightTaxAmount(array $data): float
    {
        $candidates = [
            data_get($data, 'invoice.shipping_tax'),
            data_get($data, 'invoice.delivery_tax'),
            data_get($data, 'shipping_tax'),
            data_get($data, 'delivery_tax'),
        ];

        foreach ($candidates as $candidate) {
            if (is_numeric($candidate) && (float) $candidate > 0) {
                return $this->roundSapAmount((float) $candidate);
            }
        }

        $charges = (array) data_get($data, 'invoice.additional_charges', data_get($data, 'additional_charges', []));
        $taxAmount = 0.0;

        foreach ($charges as $charge) {
            $type = strtolower(trim((string) data_get($charge, 'type', '')));
            if (!in_array($type, ['shipment_fee', 'shipping_fee', 'delivery_fee', 'freight'], true)) {
                continue;
            }

            $candidate = data_get($charge, 'tax_amount');
            if (is_numeric($candidate) && (float) $candidate > 0) {
                $taxAmount += (float) $candidate;
            }
        }

        return $this->roundSapAmount($taxAmount);
    }

    private function extractOrderFreightTaxPercent(array $data): float
    {
        // 1) Explicit shipping tax percent: highest priority, treat 0 as
        //    intentional (e.g. merchant configured zero-rated shipping).
        $explicitPercentPaths = [
            'invoice.shipping_tax_percent',
            'invoice.delivery_tax_percent',
            'shipping_tax_percent',
            'delivery_tax_percent',
        ];

        foreach ($explicitPercentPaths as $path) {
            $candidate = data_get($data, $path);
            if (is_numeric($candidate)) {
                return (float) $candidate;
            }
        }

        // 2) tax_percentage on a shipping/freight additional_charges line.
        $charges = (array) data_get($data, 'invoice.additional_charges', data_get($data, 'additional_charges', []));
        foreach ($charges as $charge) {
            $type = strtolower(trim((string) data_get($charge, 'type', '')));
            if (!in_array($type, ['shipment_fee', 'shipping_fee', 'delivery_fee', 'freight'], true)) {
                continue;
            }

            $taxPercentage = data_get($charge, 'tax_percentage');
            if (is_numeric($taxPercentage)) {
                return (float) $taxPercentage;
            }
        }

        // 3) Inferred from a positive tax amount Omniful provided.
        $explicitTaxAmountPaths = [
            'invoice.shipping_tax',
            'invoice.delivery_tax',
            'shipping_tax',
            'delivery_tax',
        ];

        foreach ($explicitTaxAmountPaths as $path) {
            $candidate = data_get($data, $path);
            if (is_numeric($candidate) && (float) $candidate > 0) {
                return $this->inferFreightTaxPercentFromAmount($data, (float) $candidate);
            }
        }

        foreach ($charges as $charge) {
            $type = strtolower(trim((string) data_get($charge, 'type', '')));
            if (!in_array($type, ['shipment_fee', 'shipping_fee', 'delivery_fee', 'freight'], true)) {
                continue;
            }

            $taxAmount = data_get($charge, 'tax_amount');
            if (is_numeric($taxAmount) && (float) $taxAmount > 0) {
                return $this->inferFreightTaxPercentFromAmount($data, (float) $taxAmount);
            }
        }

        // 4) Fallback: follow the order items' VAT rate. Omniful frequently
        //    omits shipping_tax or sends it as 0 even on domestic 15% orders;
        //    SAP previously stamped EOV (0%) on the freight expense line while
        //    the merchandise lines carried SOV (15%), leaving the invoice in
        //    an inconsistent mixed-VAT state. Mirroring the line tax keeps
        //    freight aligned with the rest of the invoice and matches the
        //    BRS expectation that shipping follows the merchandise VAT rate.
        $lineItems = (array) data_get($data, 'items', data_get($data, 'order_items', []));
        foreach ($lineItems as $item) {
            $taxPercent = $this->extractOrderLineTaxPercent((array) $item);
            if ($taxPercent > 0) {
                return $taxPercent;
            }
        }

        return 0.0;
    }

    private function inferFreightTaxPercentFromAmount(array $data, float $taxAmount): float
    {
        $freightGross = $this->extractOrderFreightGrossAmount($data);
        if ($freightGross <= 0 || $taxAmount <= 0 || $taxAmount >= $freightGross) {
            return 0.0;
        }

        $freightNet = max($freightGross - $taxAmount, 0.0);
        if ($freightNet <= 0) {
            return 0.0;
        }

        return ($taxAmount / $freightNet) * 100;
    }

    private function resolveSapTaxCodeForOrderLine(array $data, array $item): string
    {
        $taxPercent = $this->extractOrderLineTaxPercent($item);

        if ($this->isLocalOrderCustomer($data)) {
            return $this->resolveConfiguredSapTaxCode(
                $taxPercent > 0 ? 'order_tax_code_ksa_taxable' : 'order_tax_code_ksa_zero',
                $taxPercent > 0 ? 'omniful.order_tax.ksa_taxable_code' : 'omniful.order_tax.ksa_zero_tax_code',
                $taxPercent > 0 ? 'SOV' : 'EOV',
            );
        }

        return $this->resolveConfiguredSapTaxCode(
            'order_tax_code_foreign',
            'omniful.order_tax.foreign_code',
            'EOV',
        );
    }

    private function resolveConfiguredSapTaxCode(string $settingKey, string $configKey, string $fallback): string
    {
        foreach ([
            $this->getIntegrationSettingValue($settingKey),
            config($configKey, $fallback),
            $fallback,
        ] as $candidate) {
            $taxCode = $this->sanitizeSapTaxCode($candidate);
            if ($taxCode !== '') {
                return $taxCode;
            }
        }

        return '';
    }

    private function sanitizeSapTaxCode(mixed $value): string
    {
        $code = trim(Utf8::sanitizeString((string) $value));

        if ($code === '') {
            return '';
        }

        if (!preg_match('/^[A-Za-z0-9_.-]+$/', $code)) {
            Log::warning('Ignoring invalid SAP tax code value.', [
                'tax_code' => $code,
            ]);

            return '';
        }

        return $code;
    }

    private function extractOrderLineTaxPercent(array $item): float
    {
        $candidates = [
            data_get($item, 'tax_percent'),
            data_get($item, 'vat_percent'),
            data_get($item, 'tax_percentage'),
        ];

        foreach ($candidates as $candidate) {
            if (is_numeric($candidate)) {
                return (float) $candidate;
            }
        }

        return 0.0;
    }

    private function roundSapAmount(float $value): float
    {
        return round($value, 2);
    }

    private function roundSapQuantity(float $value): float
    {
        return round($value, 2);
    }

    /**
     * @param array<int,array<string,mixed>> $lines
     * @return array<int,array<string,mixed>>
     */
    private function normalizeSapDocumentLines(array $lines): array
    {
        foreach ($lines as &$line) {
            if (isset($line['Quantity']) && is_numeric($line['Quantity'])) {
                $line['Quantity'] = $this->roundSapQuantity((float) $line['Quantity']);
            }

            if (isset($line['UnitPrice']) && is_numeric($line['UnitPrice'])) {
                $line['UnitPrice'] = $this->roundSapAmount((float) $line['UnitPrice']);
            }

            if (isset($line['Price']) && is_numeric($line['Price'])) {
                $line['Price'] = $this->roundSapAmount((float) $line['Price']);
            }

            if (isset($line['LineTotal']) && is_numeric($line['LineTotal'])) {
                $line['LineTotal'] = $this->roundSapAmount((float) $line['LineTotal']);
            }
        }
        unset($line);

        return $lines;
    }

    /**
     * @param array<int,array<string,mixed>> $lines
     * @return array<int,array<string,mixed>>
     */
    private function rebalanceOrderLinesForInvoiceTotals(array $lines, array $data, array $lineTaxPercents = []): array
    {
        $targetSubtotal = $this->resolveTargetOrderMerchandiseSubtotal($data, $lines, $lineTaxPercents);
        if ($targetSubtotal === null) {
            return $lines;
        }

        $currentTotals = [];
        $currentSubtotal = 0.0;
        foreach ($lines as $index => $line) {
            $qty = (float) ($line['Quantity'] ?? 0);
            $lineTotal = $this->extractSapLineMerchandiseTotal($line);
            if ($qty <= 0 || $lineTotal <= 0) {
                continue;
            }

            $currentTotals[$index] = $lineTotal;
            $currentSubtotal += $lineTotal;
        }

        if ($currentSubtotal <= 0 || abs($currentSubtotal - $targetSubtotal) < 0.01) {
            return $lines;
        }

        $allocated = 0.0;
        $indexes = array_keys($currentTotals);
        $lastIndex = array_key_last($indexes);

        foreach ($indexes as $position => $index) {
            $baseLineTotal = $currentTotals[$index];
            $qty = (float) ($lines[$index]['Quantity'] ?? 0);
            if ($qty <= 0) {
                continue;
            }

            if ($position === $lastIndex) {
                $lineTotal = $this->roundSapAmount($targetSubtotal - $allocated);
            } else {
                $lineTotal = $this->roundSapAmount(($targetSubtotal * $baseLineTotal) / $currentSubtotal);
                $allocated += $lineTotal;
            }

            $lines[$index]['LineTotal'] = $lineTotal;
            unset($lines[$index]['UnitPrice']);
        }

        return $lines;
    }

    private function resolveOrderDocumentDiscountPercent(array $data, array $lines): float
    {
        $discount = data_get($data, 'invoice.discount');
        if (!is_numeric($discount) || (float) $discount <= 0) {
            return 0.0;
        }

        $discountInclusive = filter_var(data_get($data, 'invoice.sub_total_discount_inclusive', false), FILTER_VALIDATE_BOOL);
        if ($discountInclusive) {
            return 0.0;
        }

        $freightDiscount = $this->extractOrderFreightDiscountAmount($data);
        $merchandiseDiscount = $this->roundSapAmount(max((float) $discount - $freightDiscount, 0.0));
        if ($merchandiseDiscount <= 0) {
            return 0.0;
        }

        $lineSubtotal = $this->sumSapLineMerchandiseTotals($lines);
        $subtotal = data_get($data, 'invoice.subtotal');
        $discountBase = $lineSubtotal > 0
            ? $lineSubtotal
            : (is_numeric($subtotal) && (float) $subtotal > 0 ? (float) $subtotal : 0.0);

        if ($discountBase <= 0) {
            return 0.0;
        }

        return round(min(($merchandiseDiscount / $discountBase) * 100, 100), 2);
    }

    private function sumSapLineMerchandiseTotals(array $lines): float
    {
        $total = 0.0;
        foreach ($lines as $line) {
            $total += $this->extractSapLineMerchandiseTotal((array) $line);
        }

        return $this->roundSapAmount($total);
    }

    private function resolveTargetOrderMerchandiseSubtotal(array $data, array $lines, array $lineTaxPercents = []): ?float
    {
        $targetGross = $this->resolveTargetOrderMerchandiseGrossTotal($data);
        if ($targetGross !== null) {
            $currentNet = 0.0;
            $currentGross = 0.0;

            foreach ($lines as $index => $line) {
                $lineTotal = $this->extractSapLineMerchandiseTotal($line);
                if ($lineTotal <= 0) {
                    continue;
                }

                $taxPercent = (float) ($lineTaxPercents[$index] ?? 0);
                $currentNet += $lineTotal;
                $currentGross += $lineTotal * (1 + ($taxPercent / 100));
            }

            if ($currentNet > 0 && $currentGross > 0) {
                return $this->roundSapAmount($currentNet * ($targetGross / $currentGross));
            }
        }

        $subtotal = data_get($data, 'invoice.subtotal');
        if (!is_numeric($subtotal)) {
            return null;
        }

        $target = (float) $subtotal;
        $discount = data_get($data, 'invoice.discount');
        $discountInclusive = filter_var(data_get($data, 'invoice.sub_total_discount_inclusive', false), FILTER_VALIDATE_BOOL);
        $freightDiscount = $this->extractOrderFreightDiscountAmount($data);
        $merchandiseDiscount = is_numeric($discount) ? max((float) $discount - $freightDiscount, 0) : 0.0;

        if (!$discountInclusive && $merchandiseDiscount > 0) {
            $target -= $merchandiseDiscount;
        }

        return $target > 0 ? $this->roundSapAmount($target) : 0.0;
    }

    private function resolveTargetOrderMerchandiseGrossTotal(array $data): ?float
    {
        $invoiceTotal = data_get($data, 'invoice.total', data_get($data, 'total'));
        if (!is_numeric($invoiceTotal)) {
            return null;
        }

        $freightGross = $this->extractOrderFreightGrossAmount($data);
        $target = (float) $invoiceTotal - $freightGross;

        return $target >= 0 ? $this->roundSapAmount($target) : 0.0;
    }

    private function extractSapLineMerchandiseTotal(array $line): float
    {
        if (isset($line['LineTotal']) && is_numeric($line['LineTotal'])) {
            return (float) $line['LineTotal'];
        }

        $qty = (float) ($line['Quantity'] ?? 0);
        $unitPrice = (float) ($line['UnitPrice'] ?? 0);

        if ($qty <= 0 || $unitPrice <= 0) {
            return 0.0;
        }

        return $qty * $unitPrice;
    }

    private function resolveOrderWarehouseCode(mixed $hubCode): ?string
    {
        $resolved = trim((string) ($hubCode ?? ''));
        if ($resolved !== '') {
            return $resolved;
        }

        $fallback = trim((string) config('omniful.order_fallback.warehouse_code', ''));
        return $fallback !== '' ? $fallback : null;
    }

    private function resolveOrderDocumentDate(array $data, array $paths): string
    {
        foreach ($paths as $path) {
            $value = data_get($data, $path);
            if ($value !== null && trim((string) $value) !== '') {
                return $this->formatDate((string) $value);
            }
        }

        return $this->formatDate(now()->format('Y-m-d'));
    }

    private function resolveOrderTaxDate(array $data, string $docDate, array $paths): string
    {
        foreach ($paths as $path) {
            $value = data_get($data, $path);
            if ($value !== null && trim((string) $value) !== '') {
                return $this->formatDate((string) $value);
            }
        }

        return $docDate;
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

        if (strlen($target) >= 10) {
            $last10 = substr($target, -10);
            $candidates[] = $last10;
            $candidates[] = '0' . ltrim($last10, '0');
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
        $emailValue = trim((string) ($email ?? ''));
        if ($emailValue !== '') {
            $existingByEmail = $this->findBusinessPartnerByEmail($emailValue, null);
            if ($existingByEmail) {
                $existingCode = (string) ($existingByEmail['CardCode'] ?? '');
                $existingType = $this->normalizeSapCardType((string) ($existingByEmail['CardType'] ?? ''));
                if ($existingCode !== '') {
                    if ($existingType === 'S') {
                        return $existingCode;
                    }
                    if ($this->ensureBusinessPartnerIsSupplier($existingCode, $cardName, $email, $phone)) {
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
                    if ($existingType === 'S') {
                        return $existingCode;
                    }
                    if ($this->ensureBusinessPartnerIsSupplier($existingCode, $cardName, $email, $phone)) {
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

        $existingByLooseScan = $this->findBusinessPartnerByLooseScan($phone, (string) ($email ?? ''), $cardName);
        if ($existingByLooseScan) {
            $existingCode = (string) ($existingByLooseScan['CardCode'] ?? '');
            $existingType = $this->normalizeSapCardType((string) ($existingByLooseScan['CardType'] ?? ''));
            if ($existingCode !== '') {
                if ($existingType === 'S') {
                    return $existingCode;
                }
                if ($this->ensureBusinessPartnerIsSupplier($existingCode, $cardName, $email, $phone)) {
                    return $existingCode;
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

        $existingByLooseScan = $this->findBusinessPartnerByLooseScan($phone, (string) ($email ?? ''), $cardName);
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
    private function findBusinessPartnerByLooseScan(string $phone, string $email, string $cardName): ?array
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

                        // Relaxed local matching by trailing digits.
                        if (strlen($p) >= 9 && strlen($targetPhone) >= 9) {
                            if (substr($p, -9) === substr($targetPhone, -9)) {
                                return $row;
                            }
                        }

                        if (strlen($p) >= 10 && strlen($targetPhone) >= 10) {
                            if (substr($p, -10) === substr($targetPhone, -10)) {
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

    private function ensureBusinessPartnerIsSupplier(string $cardCode, string $cardName, mixed $email, mixed $phone = null): bool
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
        $phoneValue = $this->normalizePhoneForSap(trim((string) ($phone ?? '')));
        if ($phoneValue !== '') {
            $patch['Phone1'] = $phoneValue;
            $patch['Cellular'] = $phoneValue;
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

    private function getOrderFallbackCustomerCodeBySourceMap(): array
    {
        $fallback = config('omniful.order_fallback.customer_code_by_source', []);
        return is_array($fallback) ? $fallback : [];
    }

    private function parseSourceCustomerCodeMap(string $raw): array
    {
        $normalizedRaw = str_replace(["\r\n", "\r"], "\n", trim($raw));
        if ($normalizedRaw === '') {
            return [];
        }

        $decoded = json_decode($normalizedRaw, true);
        if (is_array($decoded)) {
            $map = [];
            foreach ($decoded as $source => $customerCode) {
                $sourceKey = $this->normalizeSourceKey((string) $source);
                $customerCode = trim((string) $customerCode);
                if ($sourceKey !== '' && $customerCode !== '') {
                    $map[$sourceKey] = $customerCode;
                }
            }

            return $map;
        }

        $pairs = preg_split('/[\n,]+/', $normalizedRaw) ?: [];
        $map = [];
        foreach ($pairs as $pair) {
            $pair = trim($pair);
            if ($pair === '' || !str_contains($pair, ':')) {
                continue;
            }

            [$source, $customerCode] = array_map('trim', explode(':', $pair, 2));
            $sourceKey = $this->normalizeSourceKey($source);
            if ($sourceKey !== '' && $customerCode !== '') {
                $map[$sourceKey] = $customerCode;
            }
        }

        return $map;
    }

    private function getIntegrationSettingValue(string $key): mixed
    {
        static $settings = null;

        if ($settings === null) {
            $settings = IntegrationSetting::query()->first();
        }

        return $settings?->{$key};
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

        return $this->roundSapAmount($total);
    }

    private function fetchCogsAmountByOrderReference(string $reference): float
    {
        $reference = trim($reference);
        if ($reference === '') {
            return 0.0;
        }

        $escapedReference = str_replace("'", "''", $reference);
        $path = "/SQLQueries('CogsSP')/List?SallaOrderId='" . rawurlencode($escapedReference) . "'";
        $response = $this->get($path);
        if (!$response->successful()) {
            Log::warning('SAP COGS SQL query failed', [
                'reference' => $reference,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            return 0.0;
        }

        $payload = $response->json() ?? [];
        $value = data_get($payload, 'value.0.TotalStockValue');
        if (!is_numeric($value) || (float) $value <= 0) {
            return 0.0;
        }

        return $this->roundSapAmount((float) $value);
    }

    private function truncateSapText(string $value, int $limit): string
    {
        $value = trim($value);
        if ($limit <= 0 || $value === '') {
            return '';
        }

        return mb_substr($value, 0, $limit);
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

        return $this->roundSapAmount($total);
    }

    /**
     * @return array<int,array{item_code:string,quantity:float,unit_price:float}>
     */
    private function buildReturnCreditLinesFromPayload(array $data): array
    {
        $items = data_get($data, 'order_items', data_get($data, 'return_items', []));
        $lines = [];
        $grouped = [];

        foreach ((array) $items as $item) {
            $itemCode = data_get($item, 'seller_sku.seller_sku_code')
                ?? data_get($item, 'seller_sku.seller_sku_id')
                ?? data_get($item, 'seller_sku_code')
                ?? data_get($item, 'sku_code')
                ?? data_get($item, 'code');
            if (!$itemCode) {
                continue;
            }

            $qty = data_get($item, 'return_quantity');
            if ($qty === null) {
                $qty = data_get($item, 'returned_quantity');
            }
            if ($qty === null) {
                $qty = data_get($item, 'refunded_quantity');
            }
            if ($qty === null) {
                $qty = data_get($item, 'cancel_quantity');
            }
            if ($qty === null) {
                $qty = data_get($item, 'cancelled_quantity');
            }
            if ($qty === null) {
                $qty = data_get($item, 'canceled_quantity');
            }
            if ($qty === null) {
                $qty = data_get($item, 'delivered_quantity');
            }
            if ($qty === null) {
                $qty = data_get($item, 'quantity');
            }

            $qty = (float) ($qty ?? 0);
            if ($qty <= 0) {
                continue;
            }

            $unitPrice = data_get($item, 'unit_price');
            if ($unitPrice === null) {
                $unitPrice = data_get($item, 'price');
            }
            if ($unitPrice === null) {
                $unitPrice = data_get($item, 'selling_price');
            }
            if ($unitPrice === null) {
                $unitPrice = data_get($item, 'display_price');
            }
            if ($unitPrice === null) {
                $unitPrice = data_get($item, 'seller_sku.retail_price');
            }

            $itemCode = (string) $itemCode;
            if (!isset($grouped[$itemCode])) {
                $grouped[$itemCode] = [
                    'item_code' => $itemCode,
                    'quantity' => 0.0,
                    'unit_price' => 0.0,
                ];
            }

            $grouped[$itemCode]['quantity'] += $qty;

            $resolvedPrice = (float) ($unitPrice ?? 0);
            if ($resolvedPrice > 0 && (float) $grouped[$itemCode]['unit_price'] <= 0) {
                $grouped[$itemCode]['unit_price'] = $resolvedPrice;
            }
        }

        foreach ($grouped as $line) {
            $lines[] = $line;
        }

        return $lines;
    }

    /**
     * @param array<int,array{item_code:string,quantity:float,unit_price:float}> $items
     * @return array<int,array<string,mixed>>
     */
    private function buildCreditLinesFromDelivery(array $items, array $delivery, string $hubCode, int $deliveryDocEntry, array $data = []): array
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
            $itemCode = $this->extractCreditMemoItemCode((array) $item);
            $remaining = (float) ($item['quantity'] ?? $item['return_quantity'] ?? $item['returned_quantity'] ?? 0);
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
                    'Quantity' => $this->roundSapQuantity($applyQty),
                ];

                if ($hubCode !== '') {
                    $line['WarehouseCode'] = $hubCode;
                }

                $taxCode = $this->resolveSapTaxCodeForOrderLine($data, [
                    'sku_code' => $itemCode,
                    'tax_percent' => data_get($item, 'tax_percent'),
                ]);
                if ($taxCode !== '') {
                    $line['VatGroup'] = $taxCode;
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
    private function buildDirectCreditLines(array $items, string $hubCode, array $data = []): array
    {
        $lines = [];
        $lineIndex = 0;
        foreach ($items as $item) {
            $lineIndex++;
            $item = (array) $item;
            $itemCode = $this->extractCreditMemoItemCode($item);
            $qty = (float) ($item['quantity'] ?? $item['return_quantity'] ?? $item['returned_quantity'] ?? 0);
            if ($itemCode === '' || $qty <= 0) {
                continue;
            }

            $unitPrice = (float) ($item['unit_price'] ?? $item['price'] ?? $item['selling_price'] ?? $item['display_price'] ?? 0);
            $this->ensureItemExists($itemCode, [
                'sku_code' => $itemCode,
                'unit_price' => $unitPrice,
            ], $lineIndex);

            $line = [
                'ItemCode' => $itemCode,
                'Quantity' => $this->roundSapQuantity($qty),
                'UnitPrice' => $this->roundSapAmount($unitPrice),
            ];

            $taxCode = $this->resolveSapTaxCodeForOrderLine($data, [
                'sku_code' => $itemCode,
                'tax_percent' => data_get($item, 'tax_percent'),
            ]);
            if ($taxCode !== '') {
                $line['VatGroup'] = $taxCode;
            }

            if ($hubCode !== '') {
                $line['WarehouseCode'] = $this->ensureWarehouseExists($hubCode, $lineIndex);
            }

            $lines[] = $line;
        }

        return $lines;
    }

    private function extractCreditMemoItemCode(array $item): string
    {
        $candidates = [
            $item['item_code'] ?? null,
            $item['sku_code'] ?? null,
            $item['seller_sku_code'] ?? null,
            data_get($item, 'seller_sku.seller_sku_code'),
            data_get($item, 'seller_sku.seller_sku_id'),
            data_get($item, 'sku.seller_sku_code'),
            data_get($item, 'code'),
        ];

        foreach ($candidates as $candidate) {
            if (is_string($candidate) && trim($candidate) !== '') {
                return trim($candidate);
            }

            if (is_numeric($candidate)) {
                return (string) $candidate;
            }
        }

        return '';
    }

    private function retryArOrderWithDynamicSeries(array $body, string $initialDocDate, bool &$usedReserveInvoiceFallback)
    {
        $seriesList = $this->getDocumentSeries('13');
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
                    $this->rememberPreferredSeriesId('13', $seriesId);
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
                $this->forgetPreferredSeriesId('13');
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

        $preferredSeries = $this->getPreferredSeriesId('13');
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

    private function applyDefaultCostCentersToLines(array $lines, ?string $fallbackWarehouseCode = null): array
    {
        foreach ($lines as $idx => $line) {
            if (!is_array($line)) {
                continue;
            }
            $warehouseCode = trim((string) ($line['WarehouseCode'] ?? $fallbackWarehouseCode ?? ''));
            $fields = $this->getDefaultCostCenterFields($warehouseCode !== '' ? $warehouseCode : null);
            if ($fields === []) {
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

    private function applyDefaultCostCentersToJournalLines(array $lines, ?string $warehouseCode = null): array
    {
        return $this->applyDefaultCostCentersToLines($lines, $warehouseCode);
    }

    private function getDefaultCostCenterFields(?string $warehouseCode = null): array
    {
        static $cached = [];
        $cacheKey = $warehouseCode ?: '__global__';
        if (array_key_exists($cacheKey, $cached)) {
            return $cached[$cacheKey];
        }

        $setting = null;
        if ($warehouseCode !== null && trim($warehouseCode) !== '') {
            $setting = SapCostCenterSetting::query()
                ->where('warehouse_code', trim($warehouseCode))
                ->first();
        }

        $globalSetting = SapCostCenterSetting::query()
            ->whereNull('warehouse_code')
            ->first();

        $raw = [
            'CostingCode' => $this->resolveCostCenterFieldValue($setting, $globalSetting, 'costing_code', 'omniful.sap_cost_centers.costing_code'),
            'CostingCode2' => $this->resolveCostCenterFieldValue($setting, $globalSetting, 'costing_code2', 'omniful.sap_cost_centers.costing_code2'),
            'CostingCode3' => $this->resolveCostCenterFieldValue($setting, $globalSetting, 'costing_code3', 'omniful.sap_cost_centers.costing_code3'),
            'CostingCode4' => $this->resolveCostCenterFieldValue($setting, $globalSetting, 'costing_code4', 'omniful.sap_cost_centers.costing_code4'),
            'CostingCode5' => $this->resolveCostCenterFieldValue($setting, $globalSetting, 'costing_code5', 'omniful.sap_cost_centers.costing_code5'),
            'ProjectCode' => $this->resolveCostCenterFieldValue($setting, $globalSetting, 'project_code', 'omniful.sap_cost_centers.project_code'),
        ];

        $fields = [];
        foreach ($raw as $key => $value) {
            $v = trim($value);
            if ($v !== '') {
                $fields[$key] = $v;
            }
        }

        $cached[$cacheKey] = $fields;
        return $fields;
    }

    private function resolveCostCenterFieldValue(?SapCostCenterSetting $warehouseSetting, ?SapCostCenterSetting $globalSetting, string $field, string $configKey): string
    {
        foreach ([
            $warehouseSetting?->{$field},
            $globalSetting?->{$field},
            config($configKey, ''),
        ] as $value) {
            $value = trim((string) ($value ?? ''));
            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }

    private function resolveWarehouseCodeFromDocumentLines(array $document): ?string
    {
        foreach ((array) ($document['DocumentLines'] ?? []) as $line) {
            $warehouseCode = trim((string) ($line['WarehouseCode'] ?? ''));
            if ($warehouseCode !== '') {
                return $warehouseCode;
            }
        }

        return null;
    }

}
