<?php

namespace App\Services\MasterData;

use App\Models\SapItem;
use App\Models\SapSyncEvent;
use App\Services\IntegrationDirectionService;
use App\Services\OmnifulApiClient;
use App\Services\SapServiceLayerClient;
use Illuminate\Support\Arr;

class SapItemSyncService
{
    private function syncDisabled(): bool
    {
        return !app(IntegrationDirectionService::class)->isDomainEnabled('items');
    }

    public function syncFromSap(SapServiceLayerClient $client): array
    {
        if ($this->syncDisabled()) {
            return ['total' => 0, 'synced' => 0, 'pending' => 0, 'skipped' => 0, 'disabled' => true];
        }

        $rows = $client->fetchItems();
        $synced = 0;
        $pending = 0;

        foreach ($rows as $row) {
            $code = $row['ItemCode'] ?? null;
            if (!$code) {
                continue;
            }
            $record = SapItem::updateOrCreate(
                ['code' => $code],
                [
                    'name' => $row['ItemName'] ?? null,
                    'uom_group_entry' => $row['UoMGroupEntry'] ?? null,
                    'payload' => $row,
                    'synced_at' => now(),
                    'status' => 'synced',
                    'error' => null,
                ]
            );
            $synced++;

            if (!$record->omniful_status) {
                $record->omniful_status = 'pending';
                $record->save();
                $pending++;
            }
        }

        return [
            'total' => count($rows),
            'synced' => $synced,
            'pending' => $pending,
            'skipped' => 0,
        ];
    }

    public function pushToOmniful(OmnifulApiClient $client, ?SapSyncEvent $event = null): array
    {
        if ($this->syncDisabled()) {
            return ['ok' => 0, 'failed' => 0, 'errors' => [], 'cancelled' => false, 'disabled' => true];
        }

        $records = SapItem::query()
            ->whereNull('omniful_status')
            ->orWhere('omniful_status', '!=', 'synced')
            ->orderBy('code')
            ->get();

        $ok = 0;
        $failed = 0;
        $errors = [];

        foreach ($records as $record) {
            if ($event?->fresh()?->sap_status === 'cancel_requested') {
                return ['ok' => $ok, 'failed' => $failed, 'errors' => $errors, 'cancelled' => true];
            }

            $record->omniful_status = 'syncing';
            $record->omniful_error = null;
            $record->save();

            try {
                $sapClient = app(SapServiceLayerClient::class);
                if (!$sapClient->isItemIntegrationEnabled((string) $record->code)) {
                    $record->omniful_status = 'skipped';
                    $record->omniful_error = 'Skipped by item integration UDF control';
                    $record->save();
                    continue;
                }

                $payload = $this->buildOmnifulPayload($record);

                $response = $client->upsert('items', $record->code, [$payload]);
                if (!$response['ok']) {
                    throw new \RuntimeException('HTTP ' . $response['status'] . ' ' . $response['body']);
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

        return ['ok' => $ok, 'failed' => $failed, 'errors' => $errors, 'cancelled' => false];
    }

    public function syncFromOmniful(OmnifulApiClient $omnifulClient, SapServiceLayerClient $sapClient): array
    {
        if ($this->syncDisabled()) {
            return ['ok' => 0, 'failed' => 0, 'errors' => [], 'disabled' => true];
        }

        $rows = $omnifulClient->fetchList('items');

        $ok = 0;
        $failed = 0;
        $errors = [];

        foreach ($rows as $row) {
            try {
                $payload = $this->normalizeOmnifulItemPayload($row);
                $result = $sapClient->syncProductFromOmniful($payload, 'item.update');
                if (($result['status'] ?? '') === 'skipped_by_udf') {
                    continue;
                }
                $ok++;
            } catch (\Throwable $e) {
                $failed++;
                $errors[] = ($row['code'] ?? $row['seller_sku_code'] ?? 'unknown') . ': ' . $e->getMessage();
            }
        }

        $this->syncFromSap($sapClient);

        return ['ok' => $ok, 'failed' => $failed, 'errors' => $errors];
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function normalizeOmnifulItemPayload(array $row): array
    {
        $code = data_get($row, 'seller_sku_code')
            ?? data_get($row, 'sku_code')
            ?? data_get($row, 'code')
            ?? data_get($row, 'id');

        return [
            'seller_sku_code' => is_scalar($code) ? (string) $code : null,
            'name' => data_get($row, 'name') ?? data_get($row, 'product.name'),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function buildOmnifulPayload(SapItem $record): array
    {
        $source = is_array($record->payload) ? $record->payload : [];
        $defaults = (array) config('omniful.item_push_defaults', []);

        $code = (string) $record->code;
        $name = trim((string) ($record->name ?? Arr::get($source, 'ItemName', $code)));
        $description = trim((string) (Arr::get($source, 'ForeignName') ?: ($defaults['description'] ?? $name)));
        $barcode = trim((string) Arr::get($source, 'BarCode', ''));
        if ($barcode === '' && (bool) ($defaults['barcode_fallback_to_code'] ?? true)) {
            $barcode = $code;
        }

        $unit = $this->normalizeUnit(
            (string) (Arr::get($source, 'SalesUnit')
                ?: Arr::get($source, 'InventoryUOM')
                ?: Arr::get($source, 'PurchaseUnit')
                ?: ($defaults['unit'] ?? 'pcs'))
        );

        $cost = $this->normalizePrice(Arr::get($source, 'AvgStdPrice'), (float) ($defaults['cost'] ?? 1));
        $retailPrice = $this->normalizePrice(null, (float) ($defaults['retail_price'] ?? max($cost, 1)));
        $sellingPrice = $this->normalizePrice(null, (float) ($defaults['selling_price'] ?? min($retailPrice, max($cost, 1))));

        $status = strtolower((string) ($defaults['status'] ?? 'live'));
        if ((string) Arr::get($source, 'Valid') === 'tNO') {
            $status = 'draft';
        }

        $payload = [
            'name' => $name !== '' ? $name : $code,
            'description' => $description !== '' ? $description : $name,
            'sku_code' => $code,
            'handling_type' => strtolower((string) ($defaults['handling_type'] ?? 'cold')),
            'type' => strtolower((string) ($defaults['type'] ?? 'simple')),
            'status' => $status,
            'unit' => $unit,
            'barcodes' => array_values(array_filter([$barcode])),
            'cost' => $cost,
            'retail_price' => $retailPrice,
            'selling_price' => $sellingPrice,
            'is_perishable' => (bool) ($defaults['is_perishable'] ?? false),
            'is_weighted' => (bool) ($defaults['is_weighted'] ?? false),
            'configuration' => [
                'weight' => [
                    'min' => (string) ($defaults['weight_min'] ?? '0.1 kg'),
                    'max' => (string) ($defaults['weight_max'] ?? '0.1 kg'),
                    'type' => (string) ($defaults['weight_type'] ?? 'fixed'),
                ],
            ],
        ];

        $manufacturer = trim((string) ($defaults['manufacturer_name'] ?? ''));
        if ($manufacturer !== '') {
            $payload['manufacturer_name'] = $manufacturer;
        }

        $brand = trim((string) ($defaults['brand_name'] ?? ''));
        if ($brand !== '') {
            $payload['brand_name'] = $brand;
        }

        $countryOfOrigin = trim((string) ($defaults['country_of_origin'] ?? ''));
        if ($countryOfOrigin !== '') {
            $payload['country_of_origin'] = $countryOfOrigin;
        }

        return $payload;
    }

    private function normalizeUnit(string $value): string
    {
        $value = trim($value);

        return $value !== '' ? $value : 'pcs';
    }

    private function normalizePrice(mixed $value, float $fallback): float
    {
        if (is_numeric($value)) {
            $number = (float) $value;
            if ($number > 0) {
                return $number;
            }
        }

        return $fallback > 0 ? $fallback : 1.0;
    }
}
