<?php

namespace App\Services\Connections;

use App\Models\IntegrationSetting;
use Illuminate\Support\Facades\Http;

class IntegrationConnectionTester
{
    public function testSapConnection(array $state): array
    {
        $baseUrl = trim((string) ($state['sap_service_layer_url'] ?? ''));
        $companyDb = trim((string) ($state['sap_company_db'] ?? ''));
        $username = trim((string) ($state['sap_username'] ?? ''));
        $password = (string) ($state['sap_password'] ?? '');

        if ($baseUrl === '' || $companyDb === '' || $username === '' || $password === '') {
            return ['ok' => false, 'message' => 'Missing SAP credentials'];
        }

        $baseUrl = rtrim($baseUrl, '/');
        $loginUrl = $baseUrl . '/Login';

        $client = Http::timeout(15)->acceptJson();
        if (array_key_exists('sap_ssl_verify', $state) && $state['sap_ssl_verify'] === false) {
            $client = $client->withoutVerifying();
        }

        try {
            $response = $client->post($loginUrl, [
                'CompanyDB' => $companyDb,
                'UserName' => $username,
                'Password' => $password,
            ]);
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => 'Request failed: ' . $e->getMessage()];
        }

        if (!$response->successful()) {
            return ['ok' => false, 'message' => 'Login failed (HTTP ' . $response->status() . ')'];
        }

        $payload = $response->json();
        $sessionId = $payload['SessionId'] ?? null;
        $routeId = $payload['RouteId'] ?? null;

        if ($sessionId) {
            $cookie = 'B1SESSION=' . $sessionId;
            if ($routeId) {
                $cookie .= '; ROUTEID=' . $routeId;
            }

            try {
                $client->withHeaders(['Cookie' => $cookie])->post($baseUrl . '/Logout');
            } catch (\Throwable) {
                // Ignore logout errors; login success is enough for connectivity.
            }
        }

        return ['ok' => true, 'message' => 'Connected'];
    }

    public function testOmnifulTenantConnection(array $state): array
    {
        $baseUrl = trim((string) ($state['omniful_api_url'] ?? ''));
        if ($baseUrl === '') {
            return ['ok' => false, 'message' => 'Missing API base URL'];
        }

        $baseUrl = rtrim($baseUrl, '/');
        return $this->refreshOmnifulTokens(
            $baseUrl,
            trim((string) ($state['omniful_api_key'] ?? '')),
            trim((string) ($state['omniful_api_secret'] ?? '')),
            trim((string) ($state['omniful_refresh_token'] ?? '')),
            trim((string) ($state['omniful_access_token'] ?? '')),
            [
                'access' => 'omniful_access_token',
                'refresh' => 'omniful_refresh_token',
                'expires_in' => 'omniful_token_expires_in',
                'expires_at' => 'omniful_access_token_expires_at',
            ],
            'Omniful tenant',
            (string) config('omniful.tenant_token_endpoint', '/sales-channel/public/v1/tenants/token')
        );
    }

    public function testOmnifulSellerConnection(array $state): array
    {
        $baseUrl = trim((string) ($state['omniful_api_url'] ?? ''));
        if ($baseUrl === '') {
            return ['ok' => false, 'message' => 'Missing API base URL'];
        }

        $baseUrl = rtrim($baseUrl, '/');

        return $this->refreshOmnifulTokens(
            $baseUrl,
            trim((string) ($state['omniful_seller_api_key'] ?? '')),
            trim((string) ($state['omniful_seller_api_secret'] ?? '')),
            trim((string) ($state['omniful_seller_refresh_token'] ?? '')),
            trim((string) ($state['omniful_seller_access_token'] ?? '')),
            [
                'access' => 'omniful_seller_access_token',
                'refresh' => 'omniful_seller_refresh_token',
                'expires_in' => 'omniful_seller_token_expires_in',
                'expires_at' => 'omniful_seller_access_token_expires_at',
            ],
            'Omniful seller',
            (string) config('omniful.seller_token_endpoint', '/sales-channel/public/v1/token')
        );
    }

    private function refreshOmnifulTokens(
        string $baseUrl,
        string $clientId,
        string $clientSecret,
        string $refreshToken,
        string $accessToken,
        array $columns,
        string $label,
        string $tokenEndpoint
    ): array {
        if ($clientId === '' || $clientSecret === '' || $refreshToken === '') {
            return ['ok' => false, 'message' => "Missing {$label} client_id/secret/refresh_token"];
        }

        $tokenUrl = $baseUrl . '/' . ltrim($tokenEndpoint, '/');

        $client = Http::timeout(20)->acceptJson();
        if ($accessToken !== '') {
            $client = $client->withToken($accessToken);
        }

        try {
            $response = $client->post($tokenUrl, [
                'refresh_token' => $refreshToken,
                'grant_type' => 'refresh_token',
                'client_id' => $clientId,
                'client_secret' => $clientSecret,
            ]);
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => 'Request failed: ' . $e->getMessage()];
        }

        if (!$response->successful()) {
            return ['ok' => false, 'message' => 'Token refresh failed (HTTP ' . $response->status() . '): ' . $response->body()];
        }

        $payload = $response->json() ?? [];
        $data = $payload['data'] ?? [];
        $newAccess = $data['access_token'] ?? null;
        $newRefresh = $data['refresh_token'] ?? null;
        $expiresIn = $data['expires_in'] ?? null;

        if (!$newAccess) {
            return ['ok' => false, 'message' => 'Token refresh failed: missing access_token'];
        }

        $expiresAt = null;
        if (is_numeric($expiresIn)) {
            $seconds = (int) $expiresIn;
            if ($seconds >= 100000000000000) {
                $seconds = (int) floor($seconds / 1000000000);
            } elseif ($seconds >= 100000000000) {
                $seconds = (int) floor($seconds / 1000000);
            } elseif ($seconds >= 1000000000) {
                $seconds = (int) floor($seconds / 1000);
            }
            $expiresAt = now()->addSeconds($seconds);
        }

        IntegrationSetting::updateOrCreate(['id' => 1], [
            $columns['access'] => (string) $newAccess,
            $columns['refresh'] => $newRefresh ? (string) $newRefresh : $refreshToken,
            $columns['expires_in'] => is_numeric($expiresIn) ? (int) $expiresIn : null,
            $columns['expires_at'] => $expiresAt,
        ]);

        return ['ok' => true, 'message' => 'Token refreshed'];
    }
}

