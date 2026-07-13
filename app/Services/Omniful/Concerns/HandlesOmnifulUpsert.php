<?php

namespace App\Services\Omniful\Concerns;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

trait HandlesOmnifulUpsert
{
    /**
     * @param array<string,mixed> $query
     * @return array<int,array<string,mixed>>
     */
    public function fetchList(string $resource, array $query = []): array
    {
        if ($this->baseUrl === '') {
            throw new \RuntimeException('Omniful base URL is not configured');
        }

        $this->selectAuthContext($resource);

        $endpoint = (string) config('omniful.sync_endpoints.' . $resource, '');
        if ($endpoint === '') {
            throw new \RuntimeException('Omniful endpoint is not configured for ' . $resource);
        }

        $baseUrl = $this->baseUrl . '/' . ltrim($endpoint, '/');
        $perPage = max(1, (int) config('omniful.sync_per_page', 100));
        if (!isset($query['per_page'])) {
            $query['per_page'] = $perPage;
        }

        $url = $baseUrl;
        $rows = [];
        $maxPages = max(1, (int) config('omniful.sync_max_pages', 500));
        $page = 0;

        while ($url !== '' && $page < $maxPages) {
            $response = $this->request('get', $url, $query);
            if (!$response['ok']) {
                throw new \RuntimeException('Omniful fetch list failed: HTTP ' . $response['status'] . ' ' . $response['body']);
            }

            $json = $response['json'];
            if (!is_array($json)) {
                break;
            }

            foreach ($this->extractRowsFromListResponse($json) as $row) {
                if (is_array($row)) {
                    $rows[] = $row;
                }
            }

            $page++;

            // 1) Classic "next URL" pagination (data.next / links.next / …).
            $next = $this->extractNextUrlFromListResponse($json, $url);
            if (is_string($next) && trim($next) !== '') {
                $url = $next;
                $query = [];
                continue;
            }

            // 2) Omniful V2 cursor pagination: re-request the SAME endpoint with
            //    search_after = meta.end_cursor while meta.has_next_page is true.
            //    (Without this the list stopped after the first ~page.)
            $hasNext = filter_var(data_get($json, 'meta.has_next_page'), FILTER_VALIDATE_BOOLEAN);
            $endCursor = data_get($json, 'meta.end_cursor');
            if ($hasNext && is_string($endCursor) && trim($endCursor) !== '') {
                $url = $baseUrl;
                $query = ['per_page' => $perPage, 'search_after' => $endCursor];
                continue;
            }

            // 3) Classic page/offset pagination (Omniful hubs & most V1 lists:
            //    meta.current_page / meta.last_page / meta.total). Request the
            //    next page number until the last page is reached.
            $currentPage = (int) data_get($json, 'meta.current_page', 0);
            $lastPage = (int) data_get($json, 'meta.last_page', 0);
            if ($currentPage > 0 && $lastPage > $currentPage) {
                $url = $baseUrl;
                $query = ['per_page' => $perPage, 'page' => $currentPage + 1];
                continue;
            }

            break; // no further pages
        }

        if ($page >= $maxPages) {
            Log::warning('Omniful fetchList hit the page cap; result may be truncated', [
                'resource' => $resource,
                'rows' => count($rows),
                'max_pages' => $maxPages,
            ]);
        }

        return $rows;
    }

    /**
     * @param array<string,mixed> $payload
     */
    public function upsert(string $resource, string $code, array $payload): array
    {
        if ($this->baseUrl === '') {
            throw new \RuntimeException('Omniful base URL is not configured');
        }

        $this->selectAuthContext($resource);

        $endpoint = (string) config('omniful.sync_endpoints.' . $resource, '');
        if ($endpoint === '') {
            throw new \RuntimeException('Omniful endpoint is not configured for ' . $resource);
        }

        // Create, then create-or-update. Per the Omniful API:
        //   - SKU/Kit update = PUT to the SAME collection endpoint (sku_code in
        //     the body identifies the record): /master/skus, /master/skus/kits.
        //   - Suppliers have NO update endpoint (only create) — so an existing
        //     supplier is treated as already in sync (left unchanged).
        // sync_update_methods is empty for resources with no update endpoint.
        $createMethod = strtolower((string) config('omniful.sync_methods.' . $resource, 'post'));
        $updateMethod = strtolower(trim((string) config('omniful.sync_update_methods.' . $resource, '')));
        $base = $this->baseUrl . '/' . ltrim($endpoint, '/');

        $response = $this->request($createMethod, $base, $payload);
        if ($response['ok']) {
            return $response;
        }

        if ($this->isAlreadyExistsResponse($response)) {
            if ($updateMethod === '') {
                // No update endpoint for this resource (e.g. suppliers): the
                // record already exists and cannot be updated via the API.
                return [
                    'ok' => true,
                    'status' => $response['status'],
                    'body' => $response['body'] . ' | already exists — no update endpoint, left unchanged',
                    'json' => $response['json'],
                ];
            }

            return $this->request($updateMethod, $base, $this->stampUpdatedBy($payload));
        }

        return $response;
    }

    /**
     * Add updated_by to an update payload. Handles both a single object payload
     * (suppliers) and a list payload (items/kits send [{...}]).
     *
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    private function stampUpdatedBy(array $payload): array
    {
        $updatedBy = trim((string) config('omniful.updated_by', 'Sap'));
        if ($updatedBy === '') {
            return $payload;
        }

        if (array_is_list($payload)) {
            return array_map(static function ($row) use ($updatedBy) {
                if (is_array($row)) {
                    $row['updated_by'] = $updatedBy;
                }

                return $row;
            }, $payload);
        }

        $payload['updated_by'] = $updatedBy;

        return $payload;
    }

    /**
     * Detect Omniful's "record already exists" conflict so a create can be
     * retried as an update.
     *
     * @param array<string,mixed> $response
     */
    private function isAlreadyExistsResponse(array $response): bool
    {
        if ((int) ($response['status'] ?? 0) === 409) {
            return true;
        }

        $body = strtolower((string) ($response['body'] ?? ''));
        foreach (['already exist', 'already created', 'duplicate', 'has already been taken'] as $needle) {
            if ($body !== '' && str_contains($body, $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string,mixed> $payload
     */

    /**
     * @param array<string,mixed> $payload
     */
    private function request(string $method, string $url, array $payload): array
    {
        if (($this->activeAuth['access_token'] ?? '') !== '' && $this->isAccessTokenExpired()) {
            $this->refreshAccessToken();
        }

        $client = Http::timeout($this->timeout)->acceptJson();
        if (($this->activeAuth['access_token'] ?? '') !== '') {
            $client = $client->withToken($this->activeAuth['access_token']);
        } elseif (($this->activeAuth['api_key'] ?? '') !== '' && ($this->activeAuth['api_secret'] ?? '') !== '') {
            $client = $client->withBasicAuth($this->activeAuth['api_key'], $this->activeAuth['api_secret']);
        }

        $response = $client->{$method}($url, $payload);
        // A 401 means the server rejected the token regardless of our local
        // expiry clock (the token may be stale/revoked, or our stored expiry is
        // wrong). Attempt a single refresh whenever we have refresh credentials.
        if ($response->status() === 401 && ($this->activeAuth['refresh_token'] ?? '') !== '') {
            if ($this->refreshAccessToken()) {
                $client = Http::timeout($this->timeout)->acceptJson();
                if (($this->activeAuth['access_token'] ?? '') !== '') {
                    $client = $client->withToken($this->activeAuth['access_token']);
                } elseif (($this->activeAuth['api_key'] ?? '') !== '' && ($this->activeAuth['api_secret'] ?? '') !== '') {
                    $client = $client->withBasicAuth($this->activeAuth['api_key'], $this->activeAuth['api_secret']);
                }
                $response = $client->{$method}($url, $payload);
            }
        }

        // Surface which auth context/token was actually used so 401s are
        // diagnosable (e.g. seller vs tenant, bearer token present or not).
        $authLabel = (string) ($this->activeAuth['label'] ?? 'unknown');
        $authMode = ($this->activeAuth['access_token'] ?? '') !== ''
            ? 'bearer'
            : ((($this->activeAuth['api_key'] ?? '') !== '' && ($this->activeAuth['api_secret'] ?? '') !== '') ? 'basic' : 'none');
        $hasRefresh = ($this->activeAuth['refresh_token'] ?? '') !== '' ? 'yes' : 'no';

        $body = '[' . strtoupper($method) . '] ' . $url
            . ' (auth=' . $authLabel . '/' . $authMode
            . ', env=' . $this->settingsEnvironment . '#' . ((string) ($this->settingsId ?? '?'))
            . ', refresh=' . $hasRefresh . ') :: ' . $response->body();
        if ($response->status() === 401 && $this->lastRefreshError) {
            $body .= ' | Refresh error: ' . $this->lastRefreshError;
        }

        return [
            'ok' => $response->successful(),
            'status' => $response->status(),
            'body' => $body,
            'json' => $response->json(),
        ];
    }

    /**
     * @param array<string,mixed> $json
     * @return array<int,mixed>
     */
    private function extractRowsFromListResponse(array $json): array
    {
        $candidates = [
            data_get($json, 'data.items'),
            data_get($json, 'data.results'),
            data_get($json, 'data.data'),
            data_get($json, 'data.value'),
            data_get($json, 'data'),
            data_get($json, 'items'),
            data_get($json, 'results'),
            data_get($json, 'value'),
        ];

        foreach ($candidates as $candidate) {
            if (is_array($candidate)) {
                $isList = array_keys($candidate) === range(0, count($candidate) - 1);
                if ($isList) {
                    return $candidate;
                }
            }
        }

        return [];
    }

    /**
     * @param array<string,mixed> $json
     */
    private function extractNextUrlFromListResponse(array $json, string $currentUrl): ?string
    {
        $next = data_get($json, 'data.next')
            ?? data_get($json, 'data.next_url')
            ?? data_get($json, 'data.next_page_url')
            ?? data_get($json, 'next')
            ?? data_get($json, 'next_url')
            ?? data_get($json, 'next_page_url')
            ?? data_get($json, 'links.next')
            ?? data_get($json, 'pagination.next')
            ?? data_get($json, 'pagination.next_url');

        if (!is_string($next) || trim($next) === '') {
            return null;
        }

        $next = trim($next);
        if (str_starts_with($next, 'http://') || str_starts_with($next, 'https://')) {
            return $next;
        }

        $baseUrl = preg_replace('/\/+$/', '', $this->baseUrl);
        if (!$baseUrl) {
            return null;
        }

        if (str_starts_with($next, '/')) {
            return $baseUrl . $next;
        }

        $parsed = parse_url($currentUrl);
        $path = $parsed['path'] ?? '';
        $prefix = rtrim(str_replace(basename($path), '', $path), '/');

        return $baseUrl . $prefix . '/' . ltrim($next, '/');
    }

}
