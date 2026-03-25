<?php

namespace App\Services\MasterData;

use App\Models\SapWarehouse;
use App\Services\OmnifulCityStateResolver;
use App\Services\OmnifulApiClient;
use App\Services\SapServiceLayerClient;
use Illuminate\Support\Arr;

class SapWarehouseSyncService
{
    public function syncFromSap(SapServiceLayerClient $client): array
    {
        $rows = $client->fetchWarehouses();
        $synced = 0;
        $pending = 0;
        $skipped = 0;

        foreach ($rows as $row) {
            $code = $row['WarehouseCode'] ?? null;
            if (!$code) {
                continue;
            }
            $enabled = $client->isWarehouseIntegrationEnabled((string) $code);
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
            $synced++;
            $record = SapWarehouse::where('code', $code)->first();
            if ($record) {
                if ($enabled) {
                    if (!$record->omniful_status || $record->omniful_status === 'skipped') {
                        $record->omniful_status = 'pending';
                        $record->omniful_error = null;
                        $record->save();
                        $pending++;
                    }
                } else {
                    $record->omniful_status = 'skipped';
                    $record->omniful_error = 'Skipped by warehouse integration UDF control';
                    $record->save();
                    $skipped++;
                }
            }
        }

        return [
            'total' => count($rows),
            'synced' => $synced,
            'pending' => $pending,
            'skipped' => $skipped,
        ];
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
                $sapClient = app(SapServiceLayerClient::class);
                if (!$sapClient->isWarehouseIntegrationEnabled((string) $record->code)) {
                    $record->omniful_status = 'skipped';
                    $record->omniful_error = 'Skipped by warehouse integration UDF control';
                    $record->save();
                    continue;
                }

                $defaults = config('omniful.hub_defaults', []);
                $warehousePayload = is_array($record->payload) ? $record->payload : [];

                $sapStreet = trim((string) ($warehousePayload['Street'] ?? ''));
                $sapZipCode = trim((string) ($warehousePayload['ZipCode'] ?? ''));
                $sapCity = trim((string) ($warehousePayload['City'] ?? ''));
                $sapState = trim((string) ($warehousePayload['State'] ?? ''));
                $sapCountry = trim((string) ($warehousePayload['Country'] ?? ''));

                $email = (string) ($defaults['email'] ?? '');
                if ($email === '') {
                    $domain = (string) ($defaults['email_domain'] ?? 'hub.local');
                    $local = strtolower(preg_replace('/[^a-zA-Z0-9._-]+/', '-', (string) $record->code));
                    $email = $local . '@' . $domain;
                }

                $configuration = $defaults['configuration'] ?? [];
                if (!is_array($configuration)) {
                    $configuration = [];
                }

                $currencyCode = (string) ($defaults['currency_code'] ?? 'SAR');
                $currencyName = (string) ($defaults['currency_name'] ?? 'Saudi Riyal');
                $currencyDisplayName = (string) ($defaults['currency_display_name'] ?? ($currencyCode . ' (' . $currencyName . ')'));
                $currency = [
                    'name' => $currencyName,
                    'code' => $currencyCode,
                    'display_name' => $currencyDisplayName,
                ];

                $fallbackCountry = (string) ($defaults['country'] ?? 'Saudi Arabia');
                $fallbackCountryCode = (string) ($defaults['country_code'] ?? 'SA');
                $fallbackCity = (string) ($defaults['city'] ?? 'Riyadh');
                $resolvedLocation = app(OmnifulCityStateResolver::class)->resolve(
                    $sapCity !== '' ? $sapCity : $fallbackCity,
                    $sapCountry !== '' ? $sapCountry : $fallbackCountry
                );

                $addressLine1 = $sapStreet !== '' ? $sapStreet : (string) ($defaults['address_line1'] ?? 'N/A');
                $postalCode = $sapZipCode !== '' ? $sapZipCode : (string) ($defaults['postal_code'] ?? '00000');
                $stateName = $sapState !== '' ? $sapState : (string) ($resolvedLocation['state_name'] ?? ($defaults['state'] ?? ''));
                $countryName = $sapCountry !== '' ? $sapCountry : (string) ($resolvedLocation['country_name'] ?? $fallbackCountry);
                $cityName = (string) ($resolvedLocation['city_name'] ?? $fallbackCity);
                $phoneNumber = preg_replace('/\D+/', '', (string) ($defaults['phone_number'] ?? '555555555')) ?: '555555555';
                $services = $defaults['services'] ?? ['wms'];
                if (!is_array($services) || $services === []) {
                    $services = ['wms'];
                }
                $workingHours = $defaults['working_hours'] ?? [];
                if (!is_array($workingHours) || $workingHours === []) {
                    $workingHours = [
                        'monday' => [['start_time' => 900, 'end_time' => 2359]],
                        'tuesday' => [['start_time' => 900, 'end_time' => 2359]],
                        'wednesday' => [['start_time' => 900, 'end_time' => 2359]],
                        'thursday' => [['start_time' => 900, 'end_time' => 2359]],
                        'friday' => [['start_time' => 900, 'end_time' => 2359]],
                        'saturday' => [['start_time' => 900, 'end_time' => 2359]],
                        'sunday' => [['start_time' => 900, 'end_time' => 2359]],
                    ];
                }

                $payload = [
                    'code' => $record->code,
                    'name' => $record->name ?: $record->code,
                    'type' => (string) ($defaults['type'] ?? 'warehouse'),
                    'email' => $email,
                    'phone_number' => $phoneNumber,
                    'country_code' => $fallbackCountryCode,
                    'country_calling_code' => (string) ($defaults['country_calling_code'] ?? '+966'),
                    'services' => array_values($services),
                    'address' => [
                        'address_line1' => $addressLine1,
                        'address_line2' => (string) ($defaults['address_line2'] ?? ''),
                        'building_number' => (string) ($defaults['building_number'] ?? ''),
                        'city_name' => $cityName,
                        'state_name' => $stateName,
                        'country' => [
                            'name' => $countryName,
                            'code' => $fallbackCountryCode,
                        ],
                        'postal_code' => $postalCode,
                    ],
                    'currency' => $currency,
                    'timezone' => (string) ($defaults['timezone'] ?? 'Asia/Riyadh'),
                    'working_hours' => $workingHours,
                    'configuration' => $configuration,
                    'is_click_and_collect' => (bool) ($defaults['is_click_and_collect'] ?? false),
                    'is_pos_enabled' => (bool) ($defaults['is_pos_enabled'] ?? false),
                    'is_wms_enabled' => (bool) ($defaults['is_wms_enabled'] ?? true),
                ];

                if ($latitude = Arr::get($defaults, 'address.latitude')) {
                    $payload['address']['latitude'] = $latitude;
                }
                if ($longitude = Arr::get($defaults, 'address.longitude')) {
                    $payload['address']['longitude'] = $longitude;
                }
                if ($nationalAddressCode = Arr::get($defaults, 'address.national_address_code')) {
                    $payload['address']['national_address_code'] = $nationalAddressCode;
                }
                if ($additionalNumber = Arr::get($defaults, 'address.additional_number')) {
                    $payload['address']['additional_number'] = $additionalNumber;
                }
                if ($street = Arr::get($defaults, 'address.street')) {
                    $payload['address']['street'] = $street;
                }
                if ($area = Arr::get($defaults, 'address.area')) {
                    $payload['address']['area'] = $area;
                }

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

    public function syncFromOmniful(OmnifulApiClient $omnifulClient, SapServiceLayerClient $sapClient): array
    {
        $rows = $omnifulClient->fetchList('warehouses');

        $ok = 0;
        $failed = 0;
        $errors = [];

        foreach ($rows as $row) {
            try {
                $payload = [
                    'code' => data_get($row, 'code') ?? data_get($row, 'hub_code') ?? data_get($row, 'id'),
                    'name' => data_get($row, 'name') ?? data_get($row, 'hub_name'),
                ];

                $result = $sapClient->syncWarehouseFromOmniful($payload);
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
