<?php

namespace App\Services\Sap\Concerns;

use Illuminate\Support\Facades\Log;

trait HandlesSapMasterDataFetch
{
    /**
     * @return array<int,array>
     */
    public function fetchWarehouses(): array
    {
        return $this->fetchAll('/Warehouses?$select=WarehouseCode,WarehouseName,EnableBinLocations');
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
     * @return array{series:?int,docDate:string,indicator:?string}
     */
}

