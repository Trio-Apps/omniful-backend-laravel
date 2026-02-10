<?php

namespace App\Services\MasterData;

use App\Models\SapWarehouse;
use App\Services\OmnifulApiClient;
use App\Services\SapServiceLayerClient;

class SapWarehouseSyncService
{
    public function syncFromSap(SapServiceLayerClient $client): void
    {
        $rows = $client->fetchWarehouses();
        foreach ($rows as $row) {
            $code = $row['WarehouseCode'] ?? null;
            if (!$code) {
                continue;
            }
            SapWarehouse::updateOrCreate(
                ['code' => $code],
                [
                    'name' => $row['WarehouseName'] ?? null,
                    'payload' => $row,
                    'synced_at' => now(),
                    'status' => 'synced',
                    'error' => null,
                ]
            );
            $record = SapWarehouse::where('code', $code)->first();
            if ($record && !$record->omniful_status) {
                $record->omniful_status = 'pending';
                $record->save();
            }
        }
    }

    public function pushToOmniful(OmnifulApiClient $client): array
    {
        $records = SapWarehouse::query()
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
                $defaults = config('omniful.hub_defaults', []);
                $email = (string) ($defaults['email'] ?? '');
                if ($email === '') {
                    $domain = (string) ($defaults['email_domain'] ?? 'hub.local');
                    $local = strtolower(preg_replace('/[^a-zA-Z0-9._-]+/', '-', (string) $record->code));
                    $email = $local . '@' . $domain;
                }

                $configuration = $defaults['configuration'] ?? [];
                if (!is_array($configuration) || $configuration === []) {
                    $configuration = [
                        'inventory' => true,
                        'picking' => true,
                        'packing' => true,
                        'putaway' => true,
                        'cycle_count' => true,
                        'schedule_order' => true,
                    ];
                }

                $currency = ['code' => (string) ($defaults['currency_code'] ?? 'SAR')];
                if (!empty($defaults['currency_name'])) {
                    $currency['name'] = (string) $defaults['currency_name'];
                }
                if (!empty($defaults['currency_symbol'])) {
                    $currency['symbol'] = (string) $defaults['currency_symbol'];
                }

                $payload = [
                    'code' => $record->code,
                    'name' => $record->name ?: $record->code,
                    'type' => (string) ($defaults['type'] ?? 'warehouse'),
                    'email' => $email,
                    'phone_number' => (string) ($defaults['phone_number'] ?? '0000000000'),
                    'country_code' => (string) ($defaults['country_code'] ?? 'SA'),
                    'country_calling_code' => (string) ($defaults['country_calling_code'] ?? '+966'),
                    'address' => [
                        'address_line1' => (string) ($defaults['address_line1'] ?? 'N/A'),
                        'address_line2' => (string) ($defaults['address_line2'] ?? ''),
                        'city' => (string) ($defaults['city'] ?? 'Riyadh'),
                        'state' => (string) ($defaults['state'] ?? ''),
                        'country' => (string) ($defaults['country'] ?? ($defaults['country_code'] ?? 'SA')),
                        'postal_code' => (string) ($defaults['postal_code'] ?? '00000'),
                    ],
                    'currency' => $currency,
                    'timezone' => (string) ($defaults['timezone'] ?? 'Asia/Riyadh'),
                    'configuration' => $configuration,
                ];

                $response = $client->upsert('warehouses', $record->code, $payload);
                if (!$response['ok']) {
                    $payloadJson = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                    throw new \RuntimeException('HTTP ' . $response['status'] . ' ' . $response['body'] . ' | Payload: ' . $payloadJson);
                }

                $record->omniful_status = 'synced';
                $record->omniful_error = null;
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
}

