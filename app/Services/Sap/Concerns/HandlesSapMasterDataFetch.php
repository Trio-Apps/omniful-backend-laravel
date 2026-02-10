<?php

namespace App\Services\Sap\Concerns;

trait HandlesSapMasterDataFetch
{
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
}

