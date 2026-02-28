<?php

namespace App\Services\Sap\Concerns;

use Illuminate\Support\Facades\Log;

trait HandlesSapMasterDataFetch
{
    /**
     * @return array<int,array>
     */
    public function fetchCollectionByPath(string $path): array
    {
        return $this->fetchAll($path);
    }

    /**
     * @return array<string,mixed>
     */
    public function fetchRawResource(string $path): array
    {
        if (!str_starts_with($path, '/')) {
            $path = '/' . ltrim($path, '/');
        }

        $response = $this->get($path);
        if (!$response->successful()) {
            throw new \RuntimeException('SAP fetch failed: ' . $response->status() . ' ' . $response->body() . ' | Path: ' . $path);
        }

        $payload = $response->json();
        return is_array($payload) ? $payload : [];
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
    public function fetchChartOfAccounts(): array
    {
        return $this->fetchAllWithFallback([
            '/ChartOfAccounts?$select=Code,Name,ActiveAccount,FatherAccountKey,GroupMask&$top=200',
            '/ChartOfAccounts?$top=200',
            '/ChartOfAccounts',
        ]);
    }

    /**
     * @return array<int,array>
     */
    public function fetchAccountCategories(): array
    {
        return $this->normalizeRowsFromRawPayload(
            $this->fetchRawResource('/AccountCategoryService_GetCategoryList')
        );
    }

    /**
     * @return array<int,array>
     */
    public function fetchFinancialPeriods(): array
    {
        return $this->normalizeRowsFromRawPayload(
            $this->fetchRawResource('/CompanyService_GetPeriods')
        );
    }

    /**
     * @return array<int,array>
     */
    public function fetchBanks(): array
    {
        return $this->fetchAllWithFallback([
            '/Banks?$select=BankCode,BankName&$top=200',
            '/Banks?$top=200',
            '/Banks',
        ]);
    }

    /**
     * @return array<int,array>
     */
    public function fetchHouseBankAccounts(): array
    {
        return $this->fetchAllWithFallback([
            '/HouseBankAccounts?$select=BankCode,AccountCode,AccountNo,Branch&$top=200',
            '/HouseBankAccounts?$top=200',
            '/HouseBankAccounts',
        ]);
    }

    /**
     * @return array<int,array>
     */
    public function fetchPaymentTermsTypes(): array
    {
        return $this->fetchAllWithFallback([
            '/PaymentTermsTypes?$select=GroupNumber,PaymentTermsGroupName,NumberOfAdditionalDays&$top=200',
            '/PaymentTermsTypes?$top=200',
            '/PaymentTermsTypes',
        ]);
    }

    /**
     * @return array<int,array>
     */
    public function fetchItemGroups(): array
    {
        return $this->fetchAllWithFallback([
            '/ItemGroups?$select=Number,GroupName&$top=200',
            '/ItemGroups?$top=200',
            '/ItemGroups',
        ]);
    }

    /**
     * @return array<int,array>
     */
    public function fetchQuotations(): array
    {
        return $this->fetchAllWithFallback([
            '/Quotations?$select=DocEntry,DocNum,CardCode,DocDate,DocTotal,DocStatus&$top=200',
            '/Quotations?$top=200',
            '/Quotations',
        ]);
    }

    /**
     * @return array<int,array>
     */
    public function fetchReturnsDocuments(): array
    {
        return $this->fetchAllWithFallback([
            '/Returns?$select=DocEntry,DocNum,CardCode,DocDate,DocTotal,DocStatus&$top=200',
            '/Returns?$top=200',
            '/Returns',
        ]);
    }

    /**
     * @return array<int,array>
     */
    public function fetchInventoryTransferRequests(): array
    {
        return $this->fetchAllWithFallback([
            '/InventoryTransferRequests?$select=DocEntry,DocNum,DocDate,FromWarehouse,ToWarehouse&$top=200',
            '/InventoryTransferRequests?$top=200',
            '/InventoryTransferRequests',
        ]);
    }

    /**
     * @return array<int,array>
     */
    public function fetchInventoryCountings(): array
    {
        return $this->fetchAllWithFallback([
            '/InventoryCountings?$select=DocumentEntry,DocumentNumber,CountDate,Remarks&$top=200',
            '/InventoryCountings?$top=200',
            '/InventoryCountings',
        ]);
    }

    /**
     * @return array<int,array>
     */
    public function fetchInventoryPostings(): array
    {
        return $this->fetchAllWithFallback([
            '/InventoryPostings?$select=DocumentEntry,DocumentNumber,PostingDate,Remarks&$top=200',
            '/InventoryPostings?$top=200',
            '/InventoryPostings',
        ]);
    }

    /**
     * @return array<int,array>
     */
    public function fetchProductionOrdersCatalog(): array
    {
        return $this->fetchAllWithFallback([
            '/ProductionOrders?$select=AbsoluteEntry,DocumentNumber,ItemNo,DueDate,ProductionOrderStatus&$top=200',
            '/ProductionOrders?$top=200',
            '/ProductionOrders',
        ]);
    }

    /**
     * @return array<int,array>
     */
    public function fetchArInvoices(): array
    {
        return $this->fetchAllWithFallback([
            '/Invoices?$select=DocEntry,DocNum,CardCode,DocDate,DocTotal,DocStatus&$top=200',
            '/Invoices?$top=200',
            '/Invoices',
        ]);
    }

    /**
     * @return array<int,array>
     */
    public function fetchArCreditNotes(): array
    {
        return $this->fetchAllWithFallback([
            '/CreditNotes?$select=DocEntry,DocNum,CardCode,DocDate,DocTotal,DocStatus&$top=200',
            '/CreditNotes?$top=200',
            '/CreditNotes',
        ]);
    }

    /**
     * @return array<int,array>
     */
    public function fetchArDownPayments(): array
    {
        return $this->fetchAllWithFallback([
            '/DownPayments?$select=DocEntry,DocNum,CardCode,DocDate,DocTotal,DocStatus&$top=200',
            '/DownPayments?$top=200',
            '/DownPayments',
        ]);
    }

    /**
     * @return array<int,array>
     */
    public function fetchIncomingPaymentsDocuments(): array
    {
        return $this->fetchAllWithFallback([
            '/IncomingPayments?$select=DocEntry,DocNum,CardCode,DocDate,TransferSum&$top=200',
            '/IncomingPayments?$top=200',
            '/IncomingPayments',
        ]);
    }

    /**
     * @return array<int,array>
     */
    public function fetchApInvoices(): array
    {
        return $this->fetchAllWithFallback([
            '/PurchaseInvoices?$select=DocEntry,DocNum,CardCode,DocDate,DocTotal,DocStatus&$top=200',
            '/PurchaseInvoices?$top=200',
            '/PurchaseInvoices',
        ]);
    }

    /**
     * @return array<int,array>
     */
    public function fetchApCreditNotes(): array
    {
        return $this->fetchAllWithFallback([
            '/PurchaseCreditNotes?$select=DocEntry,DocNum,CardCode,DocDate,DocTotal,DocStatus&$top=200',
            '/PurchaseCreditNotes?$top=200',
            '/PurchaseCreditNotes',
        ]);
    }

    /**
     * @return array<int,array>
     */
    public function fetchApDownPayments(): array
    {
        return $this->fetchAllWithFallback([
            '/PurchaseDownPayments?$select=DocEntry,DocNum,CardCode,DocDate,DocTotal,DocStatus&$top=200',
            '/PurchaseDownPayments?$top=200',
            '/PurchaseDownPayments',
        ]);
    }

    /**
     * @return array<int,array>
     */
    public function fetchVendorPaymentsDocuments(): array
    {
        return $this->fetchAllWithFallback([
            '/VendorPayments?$select=DocEntry,DocNum,CardCode,DocDate,TransferSum&$top=200',
            '/VendorPayments?$top=200',
            '/VendorPayments',
        ]);
    }

    public function isWarehouseIntegrationEnabled(string $warehouseCode): bool
    {
        $udfField = trim((string) config('omniful.integration_control.warehouse_udf_field', ''));
        if ($udfField === '') {
            return true;
        }

        $allowedValues = (array) config('omniful.integration_control.warehouse_allowed_values', ['y', 'yes', 'true', '1', 'enabled']);
        $allowed = array_values(array_filter(array_map(
            fn ($v) => strtolower(trim((string) $v)),
            $allowedValues
        ), fn ($v) => $v !== ''));
        if ($allowed === []) {
            return true;
        }

        $encodedCode = str_replace("'", "''", $warehouseCode);
        $encodedField = str_replace("'", "''", $udfField);
        $response = $this->get("/Warehouses('{$encodedCode}')?\$select=WarehouseCode,{$encodedField}");

        if ($response->status() === 404) {
            return true;
        }

        if (!$response->successful()) {
            throw new \RuntimeException('SAP warehouse integration-udf lookup failed: ' . $response->status() . ' ' . $response->body());
        }

        $payload = $response->json() ?? [];
        $value = strtolower(trim((string) ($payload[$udfField] ?? '')));
        if ($value === '') {
            return true;
        }

        return in_array($value, $allowed, true);
    }

    /**
     * @return array<int,array>
     */

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

    public function isSupplierIntegrationEnabled(string $supplierCode): bool
    {
        $udfField = trim((string) config('omniful.integration_control.supplier_udf_field', ''));
        if ($udfField === '') {
            return true;
        }

        $allowedValues = (array) config('omniful.integration_control.supplier_allowed_values', ['y', 'yes', 'true', '1', 'enabled']);
        $allowed = array_values(array_filter(array_map(
            fn ($v) => strtolower(trim((string) $v)),
            $allowedValues
        ), fn ($v) => $v !== ''));
        if ($allowed === []) {
            return true;
        }

        $encodedCode = str_replace("'", "''", $supplierCode);
        $encodedField = str_replace("'", "''", $udfField);
        $response = $this->get("/BusinessPartners('{$encodedCode}')?\$select=CardCode,{$encodedField}");

        if ($response->status() === 404) {
            return true;
        }

        if (!$response->successful()) {
            if ($this->isInvalidSapPropertyError($response->body(), $udfField)) {
                Log::warning('SAP supplier integration UDF field not found; bypassing supplier UDF control', [
                    'supplier_code' => $supplierCode,
                    'udf_field' => $udfField,
                    'status' => $response->status(),
                ]);
                return true;
            }

            throw new \RuntimeException('SAP supplier integration-udf lookup failed: ' . $response->status() . ' ' . $response->body());
        }

        $payload = $response->json() ?? [];
        $value = strtolower(trim((string) ($payload[$udfField] ?? '')));
        if ($value === '') {
            return true;
        }

        return in_array($value, $allowed, true);
    }

    private function isInvalidSapPropertyError(string $body, string $field): bool
    {
        $normalized = strtolower($body);
        if (!str_contains($normalized, 'property') || !str_contains($normalized, 'invalid')) {
            return false;
        }

        if ($field === '') {
            return str_contains($normalized, 'property');
        }

        return str_contains($normalized, strtolower($field));
    }

    /**
     * @return array<int,array>
     */

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
    public function fetchCostCenters(): array
    {
        return $this->fetchAllWithFallback([
            "/DistributionRules?\$select=FactorCode,FactorName,InWhichDimension,CostCentreTypeCode,CostCenterTypeCode,CentreCode,CentreName,Active&\$filter=Active%20eq%20'tYES'&\$top=200",
            "/DistributionRules?\$select=FactorCode,InWhichDimension,CostCentreTypeCode,CostCenterTypeCode,CentreCode,CentreName,Active&\$filter=Active%20eq%20'tYES'&\$top=200",
            "/DistributionRules?\$select=FactorCode,FactorName,InWhichDimension,CostCentreTypeCode,CostCenterTypeCode,CentreCode,CentreName,Active&\$top=200",
            "/DistributionRules?\$select=FactorCode,InWhichDimension,CostCentreTypeCode,CostCenterTypeCode,CentreCode,CentreName,Active&\$top=200",
            "/DistributionRules?\$select=FactorCode,InWhichDimension,CostCentreTypeCode,CostCenterTypeCode,CentreCode,CentreName&\$top=200",
            "/DistributionRules?\$top=200",
        ]);
    }

    /**
     * @return array<int,array>
     */
    public function fetchProjects(): array
    {
        return $this->fetchAllWithFallback([
            "/Projects?\$select=Code,Name,Active&\$filter=Active%20eq%20'tYES'&\$top=200",
            "/Projects?\$select=Code,Name,Active&\$top=200",
            "/Projects?\$select=Code,Name&\$top=200",
            "/Projects?\$top=200",
        ]);
    }

    /**
     * @return array<int,array>
     */

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
                $normalized = strtolower($message);
                $isNotFound = str_contains($message, ' 404 ');
                $isInvalidProperty = str_contains($normalized, 'property')
                    && str_contains($normalized, 'invalid');

                if (!$isNotFound && !$isInvalidProperty) {
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
     * @param array<string,mixed> $payload
     * @return array<int,array>
     */
    private function normalizeRowsFromRawPayload(array $payload): array
    {
        if (isset($payload['value']) && is_array($payload['value'])) {
            return array_values(array_filter($payload['value'], fn ($row) => is_array($row)));
        }

        foreach ($payload as $value) {
            if (is_array($value) && array_is_list($value)) {
                return array_values(array_filter($value, fn ($row) => is_array($row)));
            }
        }

        if ($payload === []) {
            return [];
        }

        if (array_is_list($payload)) {
            return array_values(array_filter($payload, fn ($row) => is_array($row)));
        }

        return [$payload];
    }

    /**
     * @return array{series:?int,docDate:string,indicator:?string}
     */
}
