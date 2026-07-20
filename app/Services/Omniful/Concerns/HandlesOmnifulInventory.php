<?php

namespace App\Services\Omniful\Concerns;

/**
 * Pushes stock quantities to Omniful's "Post Hub Inventory" endpoint for the
 * SAP -> Omniful Inventory Quantity Push. Hubs are already synced — this only
 * updates the available quantities of a seller's SKUs in a hub.
 *
 *   POST {base}/sales-channel/public/v1/inventory/hubs/{hub_code}   (SELLER token)
 *   body: { "sku_detail": [ { "sku_code": "...", "quantity": <int> }, ... ] }
 *
 * Seller-scoped: identified by the SELLER token (the tenant token gets 401, and
 * the old /tenants/hubs/{hub}/sellers/{seller}/inventory PUT returns "Invalid hub
 * code"). Requires "inventory sync" enabled for the seller in Omniful.
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

        // Seller-scoped endpoint — the SELLER token identifies the seller (the
        // tenant token gets 401 here). {seller_code} is kept substitutable for
        // any tenant-scoped variant, but the default path carries no seller.
        $this->activeAuth = $this->sellerAuth;

        $template = (string) config(
            'omniful.inventory_push.endpoint_template',
            '/sales-channel/public/v1/inventory/hubs/{hub_code}'
        );
        $path = strtr($template, [
            '{hub_code}' => rawurlencode($hubCode),
            '{seller_code}' => rawurlencode($sellerCode),
        ]);
        $url = $this->baseUrl . '/' . ltrim($path, '/');

        // Shared global pacing: inventory push + order backfill share the seller
        // token / Omniful rate-limit bucket, so they can't 429 each other.
        \App\Support\OmnifulRateLimiter::throttle();

        $response = $this->request('post', $url, ['sku_detail' => array_values($skuDetail)]);

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
