<?php

namespace App\Services;

use App\Models\IntegrationSetting;
use Illuminate\Support\Facades\Http;

class OmnifulApiClient
{
    private string $baseUrl;
    private int $timeout;
    private ?string $lastRefreshError = null;
    private array $tenantAuth = [];
    private array $sellerAuth = [];
    private array $activeAuth = [];

    public function __construct()
    {
        $settings = IntegrationSetting::first();

        $this->baseUrl = rtrim((string) ($settings?->omniful_api_url ?? ''), '/');
        $this->timeout = (int) (config('omniful.sync_timeout', 20));
        $this->tenantAuth = [
            'label' => 'tenant',
            'api_key' => trim((string) ($settings?->omniful_api_key ?? '')),
            'api_secret' => trim((string) ($settings?->omniful_api_secret ?? '')),
            'access_token' => trim((string) ($settings?->omniful_access_token ?? '')),
            'refresh_token' => trim((string) ($settings?->omniful_refresh_token ?? '')),
            'expires_at' => $settings?->omniful_access_token_expires_at,
            'token_endpoint' => (string) config('omniful.tenant_token_endpoint', '/sales-channel/public/v1/tenants/token'),
            'columns' => [
                'access' => 'omniful_access_token',
                'refresh' => 'omniful_refresh_token',
                'expires_in' => 'omniful_token_expires_in',
                'expires_at' => 'omniful_access_token_expires_at',
            ],
        ];
        $this->sellerAuth = [
            'label' => 'seller',
            'api_key' => trim((string) ($settings?->omniful_seller_api_key ?? '')),
            'api_secret' => trim((string) ($settings?->omniful_seller_api_secret ?? '')),
            'access_token' => trim((string) ($settings?->omniful_seller_access_token ?? '')),
            'refresh_token' => trim((string) ($settings?->omniful_seller_refresh_token ?? '')),
            'expires_at' => $settings?->omniful_seller_access_token_expires_at,
            'token_endpoint' => (string) config('omniful.seller_token_endpoint', '/sales-channel/public/v1/token'),
            'columns' => [
                'access' => 'omniful_seller_access_token',
                'refresh' => 'omniful_seller_refresh_token',
                'expires_in' => 'omniful_seller_token_expires_in',
                'expires_at' => 'omniful_seller_access_token_expires_at',
            ],
        ];
        $this->activeAuth = $this->tenantAuth;
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

    private function refreshAccessToken(): bool
    {
        $tokenEndpoint = (string) ($this->activeAuth['token_endpoint'] ?? '');
        $url = $this->baseUrl . '/' . ltrim($tokenEndpoint, '/');

        if (($this->activeAuth['refresh_token'] ?? '') === '' || ($this->activeAuth['api_key'] ?? '') === '' || ($this->activeAuth['api_secret'] ?? '') === '') {
            return false;
        }

        $client = Http::timeout($this->timeout)->acceptJson();
        $response = $client->post($url, [
            'refresh_token' => $this->activeAuth['refresh_token'],
            'grant_type' => 'refresh_token',
            'client_id' => $this->activeAuth['api_key'],
            'client_secret' => $this->activeAuth['api_secret'],
        ]);

        if (!$response->successful()) {
            $this->lastRefreshError = $response->body();
            return false;
        }

        $payload = $response->json() ?? [];
        $data = $payload['data'] ?? [];
        $accessToken = $data['access_token'] ?? null;
        $refreshToken = $data['refresh_token'] ?? null;
        $expiresIn = $data['expires_in'] ?? null;

        if (!$accessToken) {
            $this->lastRefreshError = $response->body();
            return false;
        }

        $this->activeAuth['access_token'] = (string) $accessToken;
        if ($refreshToken) {
            $this->activeAuth['refresh_token'] = (string) $refreshToken;
        }

        $expiresAt = null;
        if (is_numeric($expiresIn)) {
            $expiresAt = $this->calculateExpiresAt((int) $expiresIn);
        }
        $this->activeAuth['expires_at'] = $expiresAt;

        $columns = $this->activeAuth['columns'] ?? [];
        IntegrationSetting::updateOrCreate(['id' => 1], [
            $columns['access'] ?? 'omniful_access_token' => $this->activeAuth['access_token'],
            $columns['refresh'] ?? 'omniful_refresh_token' => $this->activeAuth['refresh_token'],
            $columns['expires_in'] ?? 'omniful_token_expires_in' => is_numeric($expiresIn) ? (int) $expiresIn : null,
            $columns['expires_at'] ?? 'omniful_access_token_expires_at' => $expiresAt,
        ]);

        return true;
    }

    private function isAccessTokenExpired(): bool
    {
        $expiresAt = $this->activeAuth['expires_at'] ?? null;
        if (!$expiresAt) {
            return false;
        }

        return now()->greaterThanOrEqualTo($expiresAt->copy()->subMinutes(2));
    }

    private function selectAuthContext(string $resource): void
    {
        if ($resource === 'suppliers') {
            $this->activeAuth = $this->sellerAuth;
        } else {
            $this->activeAuth = $this->tenantAuth;
        }
    }

    private function calculateExpiresAt(int $expiresIn): ?\Illuminate\Support\Carbon
    {
        if ($expiresIn <= 0) {
            return null;
        }

        $seconds = $expiresIn;
        if ($expiresIn >= 100000000000000) {
            $seconds = (int) floor($expiresIn / 1000000000);
        } elseif ($expiresIn >= 100000000000) {
            $seconds = (int) floor($expiresIn / 1000000);
        } elseif ($expiresIn >= 1000000000) {
            $seconds = (int) floor($expiresIn / 1000);
        }

        return now()->addSeconds($seconds);
    }
}
