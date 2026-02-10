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

        $method = strtolower((string) config('omniful.sync_methods.' . $resource, 'put'));
        $endpoint = '/' . ltrim($endpoint, '/');
        $base = $this->baseUrl . $endpoint;

        $urlWithCode = str_contains($endpoint, '{code}')
            ? $this->baseUrl . str_replace('{code}', $code, $endpoint)
            : $base . '/' . $code;

        $payloadWithCode = array_merge(['code' => $code], $payload);

        $attempts = [
            [$method, $method === 'put' ? $urlWithCode : $base, $payload],
            ['post', $base, $payloadWithCode],
            ['put', $urlWithCode, $payload],
            ['patch', $urlWithCode, $payload],
        ];

        $last = null;
        foreach ($attempts as [$tryMethod, $tryUrl, $tryPayload]) {
            $response = $this->request($tryMethod, $tryUrl, $tryPayload);
            $last = $response;

            if ($response['ok']) {
                return $response;
            }

            if ($response['status'] === 409 || str_contains($response['body'], 'already exists') || str_contains($response['body'], 'duplicate')) {
                return [
                    'ok' => true,
                    'status' => $response['status'],
                    'body' => $response['body'],
                    'json' => $response['json'],
                ];
            }

            if ($response['status'] !== 404 && $response['status'] !== 405) {
                break;
            }
        }

        return $last ?? [
            'ok' => false,
            'status' => 0,
            'body' => 'No response',
            'json' => null,
        ];
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
        if ($response->status() === 401 && ($this->activeAuth['refresh_token'] ?? '') !== '' && $this->isAccessTokenExpired()) {
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

        $body = '[' . strtoupper($method) . '] ' . $url . ' :: ' . $response->body();
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

