<?php

namespace App\Services\Sap\Concerns;

use Illuminate\Support\Facades\Http;

trait HandlesSapHttp
{
    private function get(string $path)
    {
        $cookies = $this->login();

        $client = Http::timeout(30)->acceptJson();
        if (!$this->verifySsl) {
            $client = $client->withoutVerifying();
        }

        $response = $client->withHeaders([
            'Cookie' => $cookies,
        ])->get($this->baseUrl . $path);

        $this->logout($cookies);

        return $response;
    }


    private function post(string $path, array|object $body)
    {
        $cookies = $this->login();

        $client = Http::timeout(30)->acceptJson();
        if (!$this->verifySsl) {
            $client = $client->withoutVerifying();
        }

        $response = $client->withHeaders([
            'Cookie' => $cookies,
        ])->post($this->baseUrl . $path, $body);

        $this->logout($cookies);

        return $response;
    }


    private function patch(string $path, array $body)
    {
        $cookies = $this->login();

        $client = Http::timeout(30)->acceptJson();
        if (!$this->verifySsl) {
            $client = $client->withoutVerifying();
        }

        $response = $client->withHeaders([
            'Cookie' => $cookies,
        ])->patch($this->baseUrl . $path, $body);

        $this->logout($cookies);

        return $response;
    }


    private function delete(string $path)
    {
        $cookies = $this->login();

        $client = Http::timeout(30)->acceptJson();
        if (!$this->verifySsl) {
            $client = $client->withoutVerifying();
        }

        $response = $client->withHeaders([
            'Cookie' => $cookies,
        ])->delete($this->baseUrl . $path);

        $this->logout($cookies);

        return $response;
    }


    private function login(): string
    {
        if ($this->baseUrl === '' || $this->companyDb === '' || $this->username === '' || $this->password === '') {
            throw new \RuntimeException('SAP credentials are incomplete');
        }

        $client = Http::timeout(20)->acceptJson();
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

        return $cookie;
    }


    private function logout(string $cookie): void
    {
        $client = Http::timeout(10)->acceptJson();
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
}

