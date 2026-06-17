<?php

namespace App\Services\Omniful\Concerns;

use Illuminate\Support\Facades\Http;

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

        $url = $this->baseUrl . '/' . ltrim($endpoint, '/');
        $rows = [];
        $maxPages = 20;
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

            $pageRows = $this->extractRowsFromListResponse($json);
            foreach ($pageRows as $row) {
                if (is_array($row)) {
                    $rows[] = $row;
                }
            }

            $next = $this->extractNextUrlFromListResponse($json, $url);
            $url = $next ?? '';
            $query = [];
            $page++;
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

        // True create-or-update: POST to create; if Omniful says the record
        // already exists, update it with PUT.
        $createMethod = strtolower((string) config('omniful.sync_methods.' . $resource, 'post'));
        $updateMethod = strtolower((string) config('omniful.sync_update_methods.' . $resource, 'put'));
        $base = $this->baseUrl . '/' . ltrim($endpoint, '/');

        $response = $this->request($createMethod, $base, $payload);
        if ($response['ok']) {
            return $response;
        }

        if ($this->isAlreadyExistsResponse($response)) {
            return $this->updateExisting($updateMethod, $base, $code, $payload);
        }

        return $response;
    }

    /**
     * Update an already-existing record, stamping updated_by. Tries the RESTful
     * per-record route <base>/{code} first (with a single object body) — this is
     * what suppliers AND single master SKUs/kits use — and falls back to the
     * collection endpoint (with the original payload shape) on 404/405.
     *
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    private function updateExisting(string $method, string $base, string $code, array $payload): array
    {
        $payload = $this->stampUpdatedBy($payload);
        $code = trim($code);

        // The single object to update at <base>/{code}: the payload itself for
        // an object body (suppliers), or the sole element for a one-item list
        // (items/kits are pushed one at a time as [{...}]).
        $single = null;
        if (!array_is_list($payload)) {
            $single = $payload;
        } elseif (count($payload) === 1 && isset($payload[0]) && is_array($payload[0])) {
            $single = $payload[0];
        }

        if ($single !== null && $code !== '') {
            $response = $this->request($method, $base . '/' . rawurlencode($code), $single);
            if (($response['ok'] ?? false) || !in_array((int) ($response['status'] ?? 0), [404, 405], true)) {
                return $response;
            }
            // Per-record route absent -> fall through to the collection update.
        }

        return $this->request($method, $base, $payload);
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
