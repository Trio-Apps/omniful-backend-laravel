<?php

namespace App\Services\MasterData;

use App\Services\OmnifulApiClient;
use App\Services\SapServiceLayerClient;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;

/**
 * SAP -> Omniful item/bundle integration.
 *
 * Reads OITM items still flagged "not integrated" (U_omInt = N) and, per item:
 *   - Sales-only item (SalesItem=tYES, InventoryItem=tNO) => treated as a
 *     bundle/combo. Look it up in the ZIDCOMBO UDO:
 *       * has sub-item lines  -> create a KIT in Omniful, then stamp
 *         U_omInt = Y and U_OmBInt = Y.
 *       * no sub-item lines   -> ignore (do NOT integrate, leave the flag).
 *   - Inventory item (InventoryItem=tYES) => push to Omniful as a SKU, then
 *     stamp U_omInt = Y.
 *
 * This flow is independent of the (currently stopped) bidirectional item
 * sync: it pushes straight through OmnifulApiClient and is driven solely by
 * the SAP UDF flags.
 */
class SapItemIntegrationService
{
    public function run(int $limit = 0): array
    {
        $sap = app(SapServiceLayerClient::class);
        $omniful = app(OmnifulApiClient::class);

        $configuredLimit = (int) config('omniful.item_integration.batch_limit', 0);
        $effectiveLimit = $limit > 0 ? $limit : $configuredLimit;

        $items = $sap->fetchItemsPendingIntegration($effectiveLimit);

        $summary = [
            'total' => count($items),
            'skus_created' => 0,
            'kits_created' => 0,
            'ignored_no_combo' => 0,
            'skipped_other' => 0,
            'failed' => 0,
            'errors' => [],
        ];

        foreach ($items as $item) {
            $itemCode = trim((string) ($item['ItemCode'] ?? ''));
            if ($itemCode === '') {
                continue;
            }

            try {
                switch ($this->integrateSapItem($item)) {
                    case 'kit':
                        $summary['kits_created']++;
                        break;
                    case 'sku':
                        $summary['skus_created']++;
                        break;
                    case 'ignored':
                        $summary['ignored_no_combo']++;
                        break;
                    default:
                        $summary['skipped_other']++;
                }
            } catch (\Throwable $e) {
                $summary['failed']++;
                $summary['errors'][] = $itemCode . ': ' . $e->getMessage();
                Log::warning('SAP item integration failed for item', [
                    'item_code' => $itemCode,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $summary;
    }

    /**
     * Integrate a single raw SAP item record into Omniful and stamp its
     * integration flag(s). Returns the outcome:
     *   'kit'     - sales-only combo pushed as an Omniful KIT (U_omInt+U_OmBInt=Y)
     *   'sku'     - inventory item pushed as an Omniful SKU (U_omInt=Y)
     *   'ignored' - sales-only but no ZIDCOMBO sub-items: not integrated
     *   'skipped' - neither a sellable bundle nor an inventory item
     *
     * @param array<string,mixed> $item Raw Service Layer OITM record.
     */
    public function integrateSapItem(array $item): string
    {
        $sap = app(SapServiceLayerClient::class);
        $omniful = app(OmnifulApiClient::class);

        $itemCode = trim((string) ($item['ItemCode'] ?? ''));
        if ($itemCode === '') {
            return 'skipped';
        }

        $salesItem = strtoupper(trim((string) ($item['SalesItem'] ?? ''))) === 'TYES';
        $inventoryItem = strtoupper(trim((string) ($item['InventoryItem'] ?? ''))) === 'TYES';

        // Sales-only (and NOT inventory) => bundle/combo candidate.
        if ($salesItem && !$inventoryItem) {
            $lines = $sap->fetchComboLinesFromUdo($itemCode);
            if ($lines === []) {
                return 'ignored';
            }

            $this->pushKit($omniful, $item, $lines);
            $sap->markItemIntegrated($itemCode, true);

            return 'kit';
        }

        // Inventory item => integrate as a SKU.
        if ($inventoryItem) {
            $this->pushSku($omniful, $item);
            $sap->markItemIntegrated($itemCode, false);

            return 'sku';
        }

        return 'skipped';
    }

    /**
     * Build the exact Omniful payload that would be pushed for a raw SAP item,
     * WITHOUT sending anything to Omniful or stamping any flags. Used by the
     * SAP Items page to preview/debug per-item payloads. For sales-only combos
     * this performs a read-only SAP lookup of the ZIDCOMBO sub-item lines.
     *
     * @param array<string,mixed> $item Raw Service Layer OITM record.
     * @return array{type:string,resource:?string,payload:?array<string,mixed>,note:?string}
     */
    public function previewPayload(array $item): array
    {
        $sap = app(SapServiceLayerClient::class);

        $itemCode = trim((string) ($item['ItemCode'] ?? ''));
        if ($itemCode === '') {
            return ['type' => 'skipped', 'resource' => null, 'payload' => null, 'note' => 'Item has no ItemCode.'];
        }

        $salesItem = strtoupper(trim((string) ($item['SalesItem'] ?? ''))) === 'TYES';
        $inventoryItem = strtoupper(trim((string) ($item['InventoryItem'] ?? ''))) === 'TYES';

        if ($salesItem && !$inventoryItem) {
            $lines = $sap->fetchComboLinesFromUdo($itemCode);
            if ($lines === []) {
                return [
                    'type' => 'ignored',
                    'resource' => null,
                    'payload' => null,
                    'note' => 'Sales-only item with no ZIDCOMBO sub-items — not integrated.',
                ];
            }

            return ['type' => 'kit', 'resource' => 'kits', 'payload' => $this->buildKitPayload($item, $lines), 'note' => null];
        }

        if ($inventoryItem) {
            return ['type' => 'sku', 'resource' => 'items', 'payload' => $this->buildSkuPayload($item), 'note' => null];
        }

        return [
            'type' => 'skipped',
            'resource' => null,
            'payload' => null,
            'note' => 'Neither a sellable bundle nor an inventory item.',
        ];
    }

    /**
     * @param array<string,mixed> $item
     */
    private function pushSku(OmnifulApiClient $omniful, array $item): void
    {
        $payload = $this->buildSkuPayload($item);
        $code = (string) ($payload['sku_code'] ?? '');

        $response = $omniful->upsert('items', $code, [$payload]);
        if (!($response['ok'] ?? false)) {
            throw new \RuntimeException('Omniful SKU push failed: HTTP ' . ($response['status'] ?? 0) . ' ' . ($response['body'] ?? ''));
        }
    }

    /**
     * @param array<string,mixed> $item
     * @param array<int,array{item_code:string,quantity:float}> $lines
     */
    private function pushKit(OmnifulApiClient $omniful, array $item, array $lines): void
    {
        $payload = $this->buildKitPayload($item, $lines);
        $code = (string) ($payload['sku_code'] ?? '');

        $response = $omniful->upsert('kits', $code, [$payload]);
        if (!($response['ok'] ?? false)) {
            throw new \RuntimeException('Omniful KIT push failed: HTTP ' . ($response['status'] ?? 0) . ' ' . ($response['body'] ?? ''));
        }
    }

    /**
     * @param array<string,mixed> $item
     * @return array<string,mixed>
     */
    private function buildSkuPayload(array $item): array
    {
        $defaults = (array) config('omniful.item_push_defaults', []);

        $code = (string) ($item['ItemCode'] ?? '');
        $name = trim((string) ($item['ItemName'] ?? $code));
        $description = trim((string) (($item['ForeignName'] ?? '') ?: ($defaults['description'] ?? $name)));
        $barcode = trim((string) ($item['BarCode'] ?? ''));
        if ($barcode === '' && (bool) ($defaults['barcode_fallback_to_code'] ?? true)) {
            $barcode = $code;
        }

        $unit = $this->normalizeUnit((string) (
            ($item['SalesUnit'] ?? '')
            ?: ($item['InventoryUOM'] ?? '')
            ?: ($item['PurchaseUnit'] ?? '')
            ?: ($defaults['unit'] ?? 'pcs')
        ));

        $cost = $this->normalizePrice($item['AvgStdPrice'] ?? null, (float) ($defaults['cost'] ?? 1));
        $retailPrice = $this->normalizePrice(null, (float) ($defaults['retail_price'] ?? max($cost, 1)));
        $sellingPrice = $this->normalizePrice(null, (float) ($defaults['selling_price'] ?? min($retailPrice, max($cost, 1))));

        $status = strtolower((string) ($defaults['status'] ?? 'live'));
        if ((string) ($item['Valid'] ?? '') === 'tNO') {
            $status = 'draft';
        }

        return [
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
    }

    /**
     * @param array<string,mixed> $item
     * @param array<int,array{item_code:string,quantity:float}> $lines
     * @return array<string,mixed>
     */
    private function buildKitPayload(array $item, array $lines): array
    {
        $defaults = (array) config('omniful.item_push_defaults', []);

        $code = (string) ($item['ItemCode'] ?? '');
        $name = trim((string) ($item['ItemName'] ?? $code));
        $description = trim((string) (($item['ForeignName'] ?? '') ?: ($defaults['description'] ?? $name)));

        $childSkus = [];
        foreach ($lines as $line) {
            $childSkus[] = [
                'seller_sku_code' => (string) $line['item_code'],
                'quantity' => (float) $line['quantity'],
            ];
        }

        return [
            'sku_code' => $code,
            'name' => $name !== '' ? $name : $code,
            'description' => $description !== '' ? $description : $name,
            'retail_price' => $this->normalizePrice(null, (float) ($defaults['retail_price'] ?? 1)),
            'selling_price' => $this->normalizePrice(null, (float) ($defaults['selling_price'] ?? 1)),
            'currency' => (string) config('omniful.item_integration.kit_currency', 'SAR'),
            'child_skus' => $childSkus,
        ];
    }

    private function normalizeUnit(string $unit): string
    {
        $unit = strtolower(trim($unit));

        return $unit !== '' ? $unit : 'pcs';
    }

    private function normalizePrice(mixed $value, float $fallback): float
    {
        if (is_numeric($value) && (float) $value > 0) {
            return round((float) $value, 2);
        }

        return round($fallback, 2);
    }
}
