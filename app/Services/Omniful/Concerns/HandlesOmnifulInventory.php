<?php

namespace App\Services\Omniful\Concerns;

/**
 * Pushes stock quantities to Omniful's "Push Hub Inventory" endpoint for the
 * SAP -> Omniful Inventory Quantity Push. Hubs are already synced — this only
 * updates the available quantities of a seller's SKUs in a hub.
 *
 *   PUT {base}/sales-channel/public/v1/tenants/hubs/{hub_code}/sellers/{seller_code}/inventory
 *   body: { "sku_detail": [ { "sku_code": "...", "quantity": <int> }, ... ] }
 *
 * Tenant-scoped (same token as the hub sync). Relies on request() / baseUrl /
 * selectAuthContext() provided by the other OmnifulApiClient concerns.
 */
trait HandlesOmnifulInventory
{
    /**
     * @param array<int,array{sku_code:string,quantity:int}> $skuDetail
     * @return array{ok:bool,status:int,body:string,failed_skus:array<int,mixed>}
     */
    public function pushHubInventory(string $hubCode, string $sellerCode, array $skuDetail): array
    {
        if ($this->baseUrl === '') {
            throw new \RuntimeException('Omniful base URL is not configured');
        }

        $hubCode = trim($hubCode);
        $sellerCode = trim($sellerCode);
        if ($hubCode === '' || $sellerCode === '') {
            throw new \RuntimeException('pushHubInventory requires both hub_code and seller_code');
        }

        if ($skuDetail === []) {
            return ['ok' => true, 'status' => 0, 'body' => 'no skus to push', 'failed_skus' => []];
        }

        // Tenant-scoped endpoint (path under /tenants/…), like the hub sync.
        $this->selectAuthContext('hub_inventory');

        $template = (string) config(
            'omniful.inventory_push.endpoint_template',
            '/sales-channel/public/v1/tenants/hubs/{hub_code}/sellers/{seller_code}/inventory'
        );
        $path = strtr($template, [
            '{hub_code}' => rawurlencode($hubCode),
            '{seller_code}' => rawurlencode($sellerCode),
        ]);
        $url = $this->baseUrl . '/' . ltrim($path, '/');

        $response = $this->request('put', $url, ['sku_detail' => array_values($skuDetail)]);

        // failed_skus = null on full success; otherwise the SKUs Omniful rejected.
        $failedSkus = data_get($response['json'] ?? [], 'data.failed_skus');

        return [
            'ok' => (bool) ($response['ok'] ?? false),
            'status' => (int) ($response['status'] ?? 0),
            'body' => (string) ($response['body'] ?? ''),
            'failed_skus' => is_array($failedSkus) ? $failedSkus : [],
        ];
    }
}
