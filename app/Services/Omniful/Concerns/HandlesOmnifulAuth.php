<?php

namespace App\Services\Omniful\Concerns;

use App\Models\IntegrationSetting;
use Illuminate\Support\Facades\Http;

trait HandlesOmnifulAuth
{
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

