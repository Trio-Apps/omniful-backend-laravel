<?php

namespace App\Services\Omniful\Concerns;

use Illuminate\Support\Facades\Log;

/**
 * Read-only PULL of seller orders from Omniful for the backfill feature.
 *
 * Orders are SELLER-scoped: the tenant token gets 401, the seller token works.
 * The list endpoint (V2) supports created_from/created_to + cursor pagination
 * but omits order_items; the per-order detail endpoint (V1) returns the full
 * order incl. order_items. Every call is throttled and 429-aware because the
 * Omniful API exposes no rate-limit headers to pace against.
 */
trait HandlesOmnifulOrderFetch
{
    /**
     * One page of seller orders created within [createdFrom, createdTo].
     * Dates are Y-m-d. $searchAfter is the previous page's meta.end_cursor.
     *
     * @return array{ok:bool,status:int,rows:array<int,array<string,mixed>>,end_cursor:string,has_next:bool,rl_hits:int,body:string}
     */
    public function fetchSellerOrdersPage(string $createdFrom, string $createdTo, int $perPage, ?string $searchAfter = null): array
    {
        if ($this->baseUrl === '') {
            throw new \RuntimeException('Omniful base URL is not configured');
        }

        $endpoint = (string) config('omniful.order_backfill.list_endpoint', '/sales-channel/public/v2/seller/orders');
        $query = [
            'created_from' => $createdFrom,
            'created_to' => $createdTo,
            'per_page' => max(1, $perPage),
        ];
        if (is_string($searchAfter) && trim($searchAfter) !== '') {
            $query['search_after'] = $searchAfter;
        }

        $url = $this->baseUrl . $endpoint . '?' . http_build_query($query);
        $res = $this->getSellerOrdersJson($url);
        $json = is_array($res['json'] ?? null) ? $res['json'] : [];

        return [
            'ok' => (bool) ($res['ok'] ?? false),
            'status' => (int) ($res['status'] ?? 0),
            'rows' => array_values(array_filter((array) data_get($json, 'data', []), 'is_array')),
            'end_cursor' => (string) (data_get($json, 'meta.end_cursor') ?? ''),
            'has_next' => (bool) data_get($json, 'meta.has_next_page', false),
            'rl_hits' => (int) ($res['rl_hits'] ?? 0),
            'body' => (string) ($res['body'] ?? ''),
        ];
    }

    /**
     * Full order detail (incl. order_items) by Omniful hash id (data.id).
     *
     * @return array{ok:bool,status:int,order:?array<string,mixed>,rl_hits:int,body:string}
     */
    public function fetchSellerOrderDetail(string $hashId): array
    {
        $hashId = trim($hashId);
        if ($hashId === '') {
            return ['ok' => false, 'status' => 0, 'order' => null, 'rl_hits' => 0, 'body' => ''];
        }

        $template = (string) config('omniful.order_backfill.detail_endpoint', '/sales-channel/public/v1/seller/orders/{id}');
        $endpoint = str_replace('{id}', rawurlencode($hashId), $template);
        $url = $this->baseUrl . $endpoint;

        $res = $this->getSellerOrdersJson($url);
        $order = data_get($res['json'] ?? null, 'data');
        if (is_array($order) && array_is_list($order)) {
            $order = $order[0] ?? null;
        }

        return [
            'ok' => ((bool) ($res['ok'] ?? false)) && is_array($order),
            'status' => (int) ($res['status'] ?? 0),
            'order' => is_array($order) ? $order : null,
            'rl_hits' => (int) ($res['rl_hits'] ?? 0),
            'body' => (string) ($res['body'] ?? ''),
        ];
    }

    /**
     * GET with the SELLER auth context, a proactive throttle between calls, and
     * exponential backoff on HTTP 429. Reuses the base request() token-refresh.
     *
     * @return array<string,mixed>
     */
    private function getSellerOrdersJson(string $url): array
    {
        // Force the seller token — orders are seller-scoped (tenant → 401).
        $this->activeAuth = $this->sellerAuth;

        $maxRetries = max(0, (int) config('omniful.order_backfill.rate_limit_max_retries', 8));
        $throttleMs = max(0, (int) config('omniful.order_backfill.throttle_ms', 400));
        $cap = max(1, (int) config('omniful.order_backfill.rate_limit_backoff_cap_s', 120));
        $hits = 0;

        for ($attempt = 1; ; $attempt++) {
            $res = $this->request('get', $url, []);
            $status = (int) ($res['status'] ?? 0);

            if ($status !== 429) {
                if ($throttleMs > 0) {
                    usleep($throttleMs * 1000);
                }
                $res['rl_hits'] = $hits;

                return $res;
            }

            $hits++;
            if ($attempt > $maxRetries) {
                $res['rl_hits'] = $hits;

                return $res;
            }

            $wait = (int) min($cap, 2 ** min($attempt, 10));
            Log::warning('Omniful backfill: HTTP 429 rate limited; backing off', [
                'attempt' => $attempt,
                'wait_s' => $wait,
            ]);
            sleep($wait);
        }
    }
}
