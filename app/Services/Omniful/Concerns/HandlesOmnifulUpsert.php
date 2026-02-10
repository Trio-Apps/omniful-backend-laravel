<?php

namespace App\Services\Omniful\Concerns;

use Illuminate\Support\Facades\Http;

trait HandlesOmnifulUpsert
{
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

}

