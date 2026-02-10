<?php

namespace App\Services\MasterData;

use App\Models\SapSupplier;
use App\Services\OmnifulApiClient;
use App\Services\SapServiceLayerClient;

class SapSupplierSyncService
{
    public function syncFromSap(SapServiceLayerClient $client): void
    {
        $rows = $client->fetchSuppliers();
        foreach ($rows as $row) {
            $code = $row['CardCode'] ?? null;
            if (!$code) {
                continue;
            }
            $enabled = $client->isSupplierIntegrationEnabled((string) $code);
            SapSupplier::updateOrCreate(
                ['code' => $code],
                [
                    'name' => $row['CardName'] ?? null,
                    'email' => $row['EmailAddress'] ?? null,
                    'phone' => $row['Phone1'] ?? null,
                    'payload' => $row,
                    'synced_at' => now(),
                    'status' => 'synced',
                    'error' => null,
                ]
            );
            $record = SapSupplier::where('code', $code)->first();
            if ($record) {
                if ($enabled) {
                    if (!$record->omniful_status || $record->omniful_status === 'skipped') {
                        $record->omniful_status = 'pending';
                        $record->omniful_error = null;
                        $record->save();
                    }
                } else {
                    $record->omniful_status = 'skipped';
                    $record->omniful_error = 'Skipped by supplier integration UDF control';
                    $record->save();
                }
            }
        }
    }

    public function pushToOmniful(OmnifulApiClient $client): array
    {
        $records = SapSupplier::query()
            ->whereNull('omniful_status')
            ->orWhere('omniful_status', '!=', 'synced')
            ->orderBy('code')
            ->get();

        $ok = 0;
        $failed = 0;
        $errors = [];

        foreach ($records as $record) {
            $record->omniful_status = 'syncing';
            $record->omniful_error = null;
            $record->save();

            try {
                $sapClient = app(SapServiceLayerClient::class);
                if (!$sapClient->isSupplierIntegrationEnabled((string) $record->code)) {
                    $record->omniful_status = 'skipped';
                    $record->omniful_error = 'Skipped by supplier integration UDF control';
                    $record->save();
                    continue;
                }

                $usedFallbacks = [];
                $name = $record->name ?: $record->code;
                if (!$record->name) {
                    $usedFallbacks[] = 'name';
                }
                $email = $record->email ?: ($record->code . '@sap.local');
                if (!$record->email) {
                    $usedFallbacks[] = 'email';
                }
                $phone = $record->phone ?: '0000000000';
                if (!$record->phone) {
                    $usedFallbacks[] = 'phone';
                }
                $payload = [
                    'name' => $name,
                    'email' => $email,
                    'phone' => $phone,
                    'code' => $record->code,
                ];

                $response = $client->upsert('suppliers', $record->code, $payload);
                if (!$response['ok']) {
                    throw new \RuntimeException('HTTP ' . $response['status'] . ' ' . $response['body']);
                }

                $record->omniful_status = 'synced';
                $record->omniful_error = $usedFallbacks
                    ? ('Filled defaults for: ' . implode(', ', $usedFallbacks))
                    : null;
                $record->omniful_synced_at = now();
                $record->save();
                $ok++;
            } catch (\Throwable $e) {
                $record->omniful_status = 'failed';
                $record->omniful_error = $e->getMessage();
                $record->save();
                $failed++;
                $errors[] = $record->code . ': ' . $e->getMessage();
            }
        }

        return ['ok' => $ok, 'failed' => $failed, 'errors' => $errors];
    }

    public function syncFromOmniful(OmnifulApiClient $omnifulClient, SapServiceLayerClient $sapClient): array
    {
        $rows = $omnifulClient->fetchList('suppliers');

        $ok = 0;
        $failed = 0;
        $errors = [];

        foreach ($rows as $row) {
            try {
                $payload = [
                    'code' => data_get($row, 'code') ?? data_get($row, 'supplier_code') ?? data_get($row, 'id'),
                    'name' => data_get($row, 'name'),
                    'email' => data_get($row, 'email'),
                    'phone' => data_get($row, 'phone') ?? data_get($row, 'phone_number'),
                ];

                $result = $sapClient->syncSupplierFromOmniful($payload);
                if (($result['status'] ?? '') === 'skipped_by_udf') {
                    continue;
                }
                $ok++;
            } catch (\Throwable $e) {
                $failed++;
                $errors[] = ($row['code'] ?? $row['id'] ?? 'unknown') . ': ' . $e->getMessage();
            }
        }

        $this->syncFromSap($sapClient);

        return ['ok' => $ok, 'failed' => $failed, 'errors' => $errors];
    }
}
