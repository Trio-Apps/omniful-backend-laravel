<?php

namespace App\Services\Sap\Concerns;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

trait HandlesSapHttp
{
    private function get(string $path)
    {
        $cookies = $this->login();
        $startedAt = microtime(true);
        $response = null;

        try {
            $client = Http::timeout($this->sapHttpTimeout())->acceptJson();
            if (!$this->verifySsl) {
                $client = $client->withoutVerifying();
            }

            $response = $client->withHeaders([
                'Cookie' => $cookies,
            ])->get($this->baseUrl . $path);

            if ($this->sapSessionExpired($response)) {
                $cookies = $this->login(true);
                $response = $client->withHeaders([
                    'Cookie' => $cookies,
                ])->get($this->baseUrl . $path);
            }

            return $response;
        } finally {
            $durationMs = (int) round((microtime(true) - $startedAt) * 1000);
            Log::info('SAP HTTP request', [
                'method' => 'GET',
                'path' => $path,
                'duration_ms' => $durationMs,
                'status' => $response?->status(),
            ]);
        }
    }


    private function post(string $path, array|object $body)
    {
        $body = $this->normalizeSapPostAmounts($body);
        $cookies = $this->login();
        $startedAt = microtime(true);
        $response = null;

        try {
            $client = Http::timeout($this->sapPostTimeout())->acceptJson();
            if (!$this->verifySsl) {
                $client = $client->withoutVerifying();
            }

            $response = $client->withHeaders([
                'Cookie' => $cookies,
            ])->post($this->baseUrl . $path, $body);

            if ($this->sapSessionExpired($response)) {
                $cookies = $this->login(true);
                $response = $client->withHeaders([
                    'Cookie' => $cookies,
                ])->post($this->baseUrl . $path, $body);
            }

            return $response;
        } finally {
            $durationMs = (int) round((microtime(true) - $startedAt) * 1000);
            Log::info('SAP HTTP request', [
                'method' => 'POST',
                'path' => $path,
                'duration_ms' => $durationMs,
                'status' => $response?->status(),
                'meta' => $this->buildSapPostMeta($path, $body),
            ]);
        }
    }

    private function buildSapPostMeta(string $path, array|object $body): array
    {
        if ($path !== '/BusinessPartners' && $path !== '/Items') {
            return [];
        }

        $payload = is_object($body) ? (array) $body : $body;
        $keys = array_values(array_map('strval', array_keys($payload)));

        $phoneKeys = ['Phone1', 'Phone2', 'Cellular', 'MobilePhone'];
        $presentPhoneKeys = [];
        foreach ($phoneKeys as $key) {
            if (array_key_exists($key, $payload) && trim((string) $payload[$key]) !== '') {
                $presentPhoneKeys[] = $key;
            }
        }

        $meta = [
            'keys' => $keys,
            'path' => $path,
        ];

        if ($path === '/BusinessPartners') {
            $meta['has_phone_fields'] = $presentPhoneKeys !== [];
            $meta['phone_keys'] = $presentPhoneKeys;
            $meta['card_code'] = (string) ($payload['CardCode'] ?? '');
            $meta['card_type'] = (string) ($payload['CardType'] ?? '');
        }

        if ($path === '/Items') {
            $udfKeys = array_values(array_filter($keys, fn ($k) => str_starts_with($k, 'U_')));
            $meta['item_code'] = (string) ($payload['ItemCode'] ?? '');
            $meta['item_type'] = $payload['ItemType'] ?? null;
            $meta['inventory_item'] = $payload['InventoryItem'] ?? null;
            $meta['purchase_item'] = $payload['PurchaseItem'] ?? null;
            $meta['sales_item'] = $payload['SalesItem'] ?? null;
            $meta['udf_keys'] = $udfKeys;
        }

        return $meta;
    }

    private function normalizeSapPostAmounts(array|object $body): array|object
    {
        if (is_array($body)) {
            foreach ($body as $key => $value) {
                $body[$key] = $this->normalizeSapPostAmountValue((string) $key, $value);
            }

            return $body;
        }

        foreach (get_object_vars($body) as $key => $value) {
            $body->{$key} = $this->normalizeSapPostAmountValue((string) $key, $value);
        }

        return $body;
    }

    private function normalizeSapPostAmountValue(string $key, mixed $value): mixed
    {
        if (is_array($value) || is_object($value)) {
            return $this->normalizeSapPostAmounts($value);
        }

        if (!$this->isSapAmountField($key) || !is_numeric($value)) {
            return $value;
        }

        return round((float) $value, 2);
    }

    private function isSapAmountField(string $key): bool
    {
        return in_array($key, [
            'UnitPrice',
            'Price',
            'LineTotal',
            'GrossTotal',
            'DocTotal',
            'SumApplied',
            'TransferSum',
            'CashSum',
            'CheckSum',
            'CreditSum',
            'Debit',
            'Credit',
            'VatSum',
            'TaxTotal',
            'DiscountSum',
            'Total',
            'Amount',
        ], true);
    }


    private function patch(string $path, array $body)
    {
        $cookies = $this->login();
        $startedAt = microtime(true);
        $response = null;

        try {
            $client = Http::timeout($this->sapHttpTimeout())->acceptJson();
            if (!$this->verifySsl) {
                $client = $client->withoutVerifying();
            }

            $response = $client->withHeaders([
                'Cookie' => $cookies,
            ])->patch($this->baseUrl . $path, $body);

            if ($this->sapSessionExpired($response)) {
                $cookies = $this->login(true);
                $response = $client->withHeaders([
                    'Cookie' => $cookies,
                ])->patch($this->baseUrl . $path, $body);
            }

            return $response;
        } finally {
            $durationMs = (int) round((microtime(true) - $startedAt) * 1000);
            Log::info('SAP HTTP request', [
                'method' => 'PATCH',
                'path' => $path,
                'duration_ms' => $durationMs,
                'status' => $response?->status(),
            ]);
        }
    }


    private function delete(string $path)
    {
        $cookies = $this->login();
        $startedAt = microtime(true);
        $response = null;

        try {
            $client = Http::timeout($this->sapHttpTimeout())->acceptJson();
            if (!$this->verifySsl) {
                $client = $client->withoutVerifying();
            }

            $response = $client->withHeaders([
                'Cookie' => $cookies,
            ])->delete($this->baseUrl . $path);

            if ($this->sapSessionExpired($response)) {
                $cookies = $this->login(true);
                $response = $client->withHeaders([
                    'Cookie' => $cookies,
                ])->delete($this->baseUrl . $path);
            }

            return $response;
        } finally {
            $durationMs = (int) round((microtime(true) - $startedAt) * 1000);
            Log::info('SAP HTTP request', [
                'method' => 'DELETE',
                'path' => $path,
                'duration_ms' => $durationMs,
                'status' => $response?->status(),
            ]);
        }
    }


    private function login(bool $forceRefresh = false): string
    {
        if ($this->baseUrl === '' || $this->companyDb === '' || $this->username === '' || $this->password === '') {
            throw new \RuntimeException('SAP credentials are incomplete');
        }

        $cacheKey = $this->sapSessionCacheKey();
        if ($forceRefresh) {
            Cache::forget($cacheKey);
        }

        $cachedCookie = Cache::get($cacheKey);
        if (is_string($cachedCookie) && trim($cachedCookie) !== '') {
            return $cachedCookie;
        }

        $client = Http::timeout($this->sapLoginTimeout())->acceptJson();
        if (!$this->verifySsl) {
            $client = $client->withoutVerifying();
        }

        $response = $client->post($this->baseUrl . '/Login', [
            'CompanyDB' => $this->companyDb,
            'UserName' => $this->username,
            'Password' => $this->password,
        ]);

        if (!$response->successful()) {
            throw new \RuntimeException('SAP login failed: ' . $response->status() . ' ' . $response->body());
        }

        $payload = $response->json() ?? [];
        $sessionId = $payload['SessionId'] ?? null;
        $routeId = $payload['RouteId'] ?? null;

        if (!$sessionId) {
            throw new \RuntimeException('SAP login failed: missing SessionId');
        }

        $cookie = 'B1SESSION=' . $sessionId;
        if ($routeId) {
            $cookie .= '; ROUTEID=' . $routeId;
        }

        Cache::put($cacheKey, $cookie, now()->addMinutes($this->sapSessionCacheMinutes()));

        return $cookie;
    }

    private function sapSessionExpired(Response $response): bool
    {
        if (in_array($response->status(), [401, 403], true)) {
            return true;
        }

        $body = strtolower($response->body());

        return str_contains($body, 'invalid session')
            || str_contains($body, 'session timeout')
            || str_contains($body, 'session expired')
            || str_contains($body, 'b1session');
    }

    private function sapSessionCacheKey(): string
    {
        return 'sap_service_layer_session:' . sha1(implode('|', [
            $this->baseUrl,
            $this->companyDb,
            $this->username,
        ]));
    }

    private function sapSessionCacheMinutes(): int
    {
        return max(1, (int) config('services.sap.session_cache_minutes', 25));
    }


    private function logout(string $cookie): void
    {
        $client = Http::timeout($this->sapLogoutTimeout())->acceptJson();
        if (!$this->verifySsl) {
            $client = $client->withoutVerifying();
        }

        try {
            $client->withHeaders(['Cookie' => $cookie])->post($this->baseUrl . '/Logout');
        } catch (\Throwable) {
            // ignore logout errors
        }
    }


    private function formatDate(?string $value): string
    {
        if (!$value) {
            return now()->format('Y-m-d');
        }

        try {
            return \Illuminate\Support\Carbon::parse($value)->format('Y-m-d');
        } catch (\Throwable) {
            return now()->format('Y-m-d');
        }
    }

    private function sapHttpTimeout(): int
    {
        return max(1, (int) config('services.sap.http_timeout', 60));
    }

    private function sapPostTimeout(): int
    {
        return max(1, (int) config('services.sap.post_timeout', 120));
    }

    private function sapLoginTimeout(): int
    {
        return max(1, (int) config('services.sap.login_timeout', 30));
    }

    private function sapLogoutTimeout(): int
    {
        return max(1, (int) config('services.sap.logout_timeout', 10));
    }
}
