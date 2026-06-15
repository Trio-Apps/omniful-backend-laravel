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

        // Pull ONLY the not-yet-integrated items (U_omInt = N) into the local
        // mirror — not the whole catalogue. The push step then classifies each
        // as SKU/KIT and stamps the flag to Y.
        $rows = $client->fetchItemsPendingIntegration();
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

        $delayMs = max(0, (int) config('omniful.push_batch.delay_ms', 200));
        $integration = app(SapItemIntegrationService::class);

        $ok = 0;
        $failed = 0;
        $errors = [];
        $summary = ['kit' => 0, 'sku' => 0, 'ignored' => 0, 'skipped' => 0];

        // Push step: classify each mirrored item as SKU (inventory) or KIT
        // (sales-only ZIDCOMBO combo), push it to Omniful and stamp the SAP
        // integration flag(s) to Y — the agreed U_omInt flow, per record.
        foreach ($records as $record) {
            if ($event?->fresh()?->sap_status === 'cancel_requested') {
                return ['ok' => $ok, 'failed' => $failed, 'errors' => $errors, 'cancelled' => true];
            }

            $rawItem = is_array($record->payload) ? $record->payload : [];
            if (trim((string) ($rawItem['ItemCode'] ?? '')) === '') {
                $rawItem['ItemCode'] = $record->code;
            }

            try {
                $outcome = $integration->integrateSapItem($rawItem);
                $summary[$outcome] = ($summary[$outcome] ?? 0) + 1;

                if (in_array($outcome, ['kit', 'sku'], true)) {
                    $record->update([
                        'omniful_status' => 'synced',
                        'omniful_error' => null,
                        'omniful_synced_at' => now(),
                    ]);
                    $ok++;
                } else {
                    $record->update([
                        'omniful_status' => 'skipped',
                        'omniful_error' => $outcome === 'ignored'
                            ? 'Sales-only item with no ZIDCOMBO sub-items'
                            : 'Not an inventory item or sellable bundle',
                    ]);
                }
            } catch (\Throwable $e) {
                $record->update([
                    'omniful_status' => 'failed',
                    'omniful_error' => $e->getMessage(),
                ]);
                $failed++;
                $errors[] = $record->code . ': ' . $e->getMessage();
            }

            if ($delayMs > 0) {
                usleep($delayMs * 1000);
            }
        }

        return ['ok' => $ok, 'failed' => $failed, 'errors' => $errors, 'cancelled' => false, 'summary' => $summary];
    }

    /**
     * POST a bulk payload to Omniful, retrying with exponential backoff when
     * the API returns 429 (Too Many Requests). The $code is irrelevant for a
     * list payload (Omniful posts the whole array to the base endpoint).
     *
     * @param array<int,array<string,mixed>> $payloads
     * @return array<string,mixed>
     */
    private function pushBatchWithBackoff(OmnifulApiClient $client, string $resource, array $payloads): array
    {
        $delays = [2, 5, 10]; // seconds
        $attempt = 0;

        while (true) {
            $response = $client->upsert($resource, 'bulk', $payloads);
            if ($response['ok'] ?? false) {
                return $response;
            }

            $status = (int) ($response['status'] ?? 0);
            if ($status === 429 && $attempt < count($delays)) {
                sleep($delays[$attempt]);
                $attempt++;
                continue;
            }

            return $response;
        }
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
