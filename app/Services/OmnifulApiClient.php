<?php

namespace App\Services;

use App\Models\IntegrationSetting;
use Illuminate\Support\Facades\Http;

class OmnifulApiClient
{
    private string $baseUrl;
    private string $apiKey;
    private string $apiSecret;
    private string $accessToken;
    private int $timeout;
    private string $refreshToken;
    private ?string $lastRefreshError = null;

    public function __construct()
    {
        $settings = IntegrationSetting::first();

        $this->baseUrl = rtrim((string) ($settings?->omniful_api_url ?? ''), '/');
        $this->apiKey = trim((string) ($settings?->omniful_api_key ?? ''));
        $this->apiSecret = trim((string) ($settings?->omniful_api_secret ?? ''));
        $this->accessToken = trim((string) ($settings?->omniful_access_token ?? ''));
        $this->refreshToken = trim((string) ($settings?->omniful_refresh_token ?? ''));
        $this->timeout = (int) (config('omniful.sync_timeout', 20));
    }

    /**
     * @param array<string,mixed> $payload
     */
    public function upsert(string $resource, string $code, array $payload): array
    {
        if ($this->baseUrl === '') {
            throw new \RuntimeException('Omniful base URL is not configured');
        }

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
        $client = Http::timeout($this->timeout)->acceptJson();
        if ($this->accessToken !== '') {
            $client = $client->withToken($this->accessToken);
        }

        if ($this->accessToken !== '') {
            $client = $client->withToken($this->accessToken);
        } elseif ($this->apiKey !== '' && $this->apiSecret !== '') {
            $client = $client->withBasicAuth($this->apiKey, $this->apiSecret);
        }

        $response = $client->{$method}($url, $payload);
        if ($response->status() === 401 && $this->refreshToken !== '') {
            if ($this->refreshAccessToken()) {
                $client = Http::timeout($this->timeout)->acceptJson();
                if ($this->accessToken !== '') {
                    $client = $client->withToken($this->accessToken);
                } elseif ($this->apiKey !== '' && $this->apiSecret !== '') {
                    $client = $client->withBasicAuth($this->apiKey, $this->apiSecret);
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
        $tokenEndpoint = (string) config('omniful.token_endpoint', '/sales-channel/public/v1/tenants/token');
        $url = $this->baseUrl . '/' . ltrim($tokenEndpoint, '/');

        if ($this->refreshToken === '' || $this->apiKey === '' || $this->apiSecret === '') {
            return false;
        }

        $client = Http::timeout($this->timeout)->acceptJson();
        $response = $client->post($url, [
            'refresh_token' => $this->refreshToken,
            'grant_type' => 'refresh_token',
            'client_id' => $this->apiKey,
            'client_secret' => $this->apiSecret,
        ]);

        if (!$response->successful()) {
            $this->lastRefreshError = $response->body();
            return false;
        }

        $payload = $response->json() ?? [];
        $data = $payload['data'] ?? [];
        $accessToken = $data['access_token'] ?? null;
        $refreshToken = $data['refresh_token'] ?? null;

        if (!$accessToken) {
            $this->lastRefreshError = $response->body();
            return false;
        }

        $this->accessToken = (string) $accessToken;
        if ($refreshToken) {
            $this->refreshToken = (string) $refreshToken;
        }

        IntegrationSetting::updateOrCreate(['id' => 1], [
            'omniful_access_token' => $this->accessToken,
            'omniful_refresh_token' => $this->refreshToken,
        ]);

        return true;
    }
}
