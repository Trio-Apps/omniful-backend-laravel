<?php

namespace App\Services\MasterData;

use App\Models\SapItem;
use App\Models\SapSyncEvent;
use App\Services\IntegrationDirectionService;
use App\Services\OmnifulApiClient;
use App\Services\SapServiceLayerClient;

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

            $result = $this->pushRecord($record);
            $outcome = (string) $result['outcome'];
            $summary[$outcome] = ($summary[$outcome] ?? 0) + 1;

            if ($result['ok']) {
                $ok++;
            } elseif (in_array($outcome, ['kit', 'sku', 'error'], true)) {
                $failed++;
                $errors[] = $record->code . ': ' . ($result['error'] ?? 'push failed');
            }

            if ($delayMs > 0) {
                usleep($delayMs * 1000);
            }
        }

        return ['ok' => $ok, 'failed' => $failed, 'errors' => $errors, 'cancelled' => false, 'summary' => $summary];
    }

    /**
     * Push a single mirrored item to Omniful, persisting the exact payload sent
     * plus Omniful's raw response and HTTP status on the record (regardless of
     * outcome) for per-item debugging. Unlike the bulk push, this always sends —
     * even for an already-synced item — so it doubles as a "re-push / debug"
     * action from the SAP Items page.
     *
     * @return array{outcome:string,ok:bool,error:?string}
     */
    public function pushRecord(SapItem $record): array
    {
        $integration = app(SapItemIntegrationService::class);

        $rawItem = is_array($record->payload) ? $record->payload : [];
        if (trim((string) ($rawItem['ItemCode'] ?? '')) === '') {
            $rawItem['ItemCode'] = $record->code;
        }

        try {
            $details = $integration->integrateSapItemDetailed($rawItem);
        } catch (\Throwable $e) {
            // SAP-side failure (e.g. combo lookup) — nothing was sent.
            $record->update([
                'omniful_status' => 'failed',
                'omniful_error' => $e->getMessage(),
                'omniful_payload' => null,
                'omniful_response' => $e->getMessage(),
                'omniful_response_code' => null,
            ]);

            return ['outcome' => 'error', 'ok' => false, 'error' => $e->getMessage()];
        }

        $outcome = (string) $details['outcome'];
        $response = $details['response'] ?? null;

        // Always persist the exact payload sent and Omniful's raw response
        // (plus HTTP status), regardless of outcome, for per-item debugging.
        $captured = [
            'omniful_payload' => $details['payload'] ?? null,
            'omniful_response' => $response['body'] ?? null,
            'omniful_response_code' => $response['status'] ?? null,
        ];

        if (in_array($outcome, ['kit', 'sku'], true)) {
            if ($response['ok'] ?? false) {
                $record->update($captured + [
                    'omniful_status' => 'synced',
                    'omniful_error' => null,
                    'omniful_synced_at' => now(),
                ]);

                return ['outcome' => $outcome, 'ok' => true, 'error' => null];
            }

            $record->update($captured + [
                'omniful_status' => 'failed',
                'omniful_error' => $response['body'] ?? 'Omniful push failed',
            ]);

            return ['outcome' => $outcome, 'ok' => false, 'error' => 'HTTP ' . ($response['status'] ?? 0)];
        }

        $record->update($captured + [
            'omniful_status' => 'skipped',
            'omniful_error' => $outcome === 'ignored'
                ? 'Sales-only item with no ZIDCOMBO sub-items'
                : 'Not an inventory item or sellable bundle',
        ]);

        return ['outcome' => $outcome, 'ok' => false, 'error' => null];
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

}
