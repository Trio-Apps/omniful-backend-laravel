<?php

namespace App\Services;

use App\Jobs\RunSapInventoryQtyPush;
use App\Models\IntegrationSetting;
use App\Models\SapInventorySnapshot;
use App\Models\SapSyncEvent;
use App\Models\SapWarehouse;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Orchestrates the SAP -> Omniful Inventory Quantity Push: read Available per
 * synced item x warehouse from SAP, map each warehouse to its (already-synced)
 * Omniful hub, push only the changed quantities (delta) in bulk batches, and
 * record what was pushed in the local snapshot. Mirrors the single-flight /
 * SapSyncEvent pattern of SapWarehouseBackgroundPushService and NEVER touches
 * the warehouse sync itself (it only READS SapWarehouse).
 */
class SapInventoryQtyPushService
{
    public const SOURCE_TYPE = 'omniful_inventory_qty_push';

    /**
     * Queue a run (single-flight: one active event at a time; a stale queued
     * event is re-dispatched).
     *
     * @return array{queued:bool,already_running:bool,event:SapSyncEvent}
     */
    public function dispatch(?string $triggeredBy = null, ?string $mode = null): array
    {
        $activeEvent = $this->activeEvent();
        if ($activeEvent !== null) {
            if ($this->shouldRequeueStaleEvent($activeEvent)) {
                $this->markRequeued($activeEvent, $triggeredBy);
                RunSapInventoryQtyPush::dispatch($activeEvent->id);

                return ['queued' => true, 'already_running' => false, 'event' => $activeEvent];
            }

            return ['queued' => false, 'already_running' => true, 'event' => $activeEvent];
        }

        $event = SapSyncEvent::create([
            'event_key' => 'omniful_inventory_qty_push_' . (string) Str::ulid(),
            'source_type' => self::SOURCE_TYPE,
            'sap_action' => 'inventory_qty_push',
            'sap_status' => 'queued',
            'payload' => [
                'requested_at' => now()->toDateTimeString(),
                'triggered_by' => $triggeredBy,
                'mode' => $mode ?: (string) config('omniful.inventory_push.mode', 'delta'),
            ],
        ]);

        RunSapInventoryQtyPush::dispatch($event->id);

        return ['queued' => true, 'already_running' => false, 'event' => $event];
    }

    /**
     * Read-only preview: exactly what a run would push, WITHOUT calling Omniful
     * or touching the snapshot. Use to validate the mapping/quantities (e.g. on
     * a first LIVE run) before any write.
     *
     * @return array<string,mixed>
     */
    public function preview(int $sampleSize = 25): array
    {
        $mode = strtolower((string) config('omniful.inventory_push.mode', 'delta'));
        $plan = $this->buildPlan($mode);

        $sample = [];
        foreach ($plan['by_hub'] as $hub => $skus) {
            foreach ($skus as $s) {
                $sample[] = ['hub_code' => (string) $hub, 'sku_code' => $s['sku_code'], 'quantity' => $s['quantity']];
                if (count($sample) >= $sampleSize) {
                    break 2;
                }
            }
        }

        return [
            'dry_run' => true,
            'mode' => $mode,
            'seller_code' => $plan['seller_code'],
            'synced_hubs' => $plan['synced_hubs'],
            'considered' => $plan['considered'],
            'to_push' => $plan['total'],
            'skipped_unmapped' => $plan['skipped_unmapped'],
            'hubs_with_changes' => count($plan['by_hub']),
            'sample' => $sample,
            'note' => $plan['note'] ?? null,
        ];
    }

    /**
     * The actual push. Invoked by RunSapInventoryQtyPush.
     *
     * @return array<string,mixed>
     */
    public function pushToOmniful(OmnifulApiClient $client, SapSyncEvent $event): array
    {
        $mode = strtolower((string) (data_get($event->payload, 'mode') ?: config('omniful.inventory_push.mode', 'delta')));
        $batchSize = max(1, (int) config('omniful.inventory_push.batch_size', 200));

        $plan = $this->buildPlan($mode);
        if (($plan['synced_hubs'] ?? 0) === 0) {
            return ['ok' => 0, 'failed' => 0, 'skipped' => 0, 'note' => 'no synced warehouses'];
        }

        $byHub = $plan['by_hub'];
        $sellerCode = $plan['seller_code'];

        $this->progress($event, [
            'mode' => $mode,
            'total' => $plan['total'],
            'considered' => $plan['considered'],
            'skipped_unmapped' => $plan['skipped_unmapped'],
            'hubs' => count($byHub),
            'pushed' => 0,
            'failed' => 0,
        ]);

        $pushed = 0;
        $failed = 0;
        $cancelled = false;
        $failedSamples = [];

        foreach ($byHub as $hubCode => $skus) {
            foreach (array_chunk($skus, $batchSize) as $chunk) {
                if ($this->isCancelled($event)) {
                    $cancelled = true;
                    break 2;
                }

                $skuDetail = array_map(
                    static fn ($s) => ['sku_code' => $s['sku_code'], 'quantity' => $s['quantity']],
                    $chunk
                );

                $result = $client->pushHubInventory((string) $hubCode, $sellerCode, $skuDetail);

                if (!($result['ok'] ?? false)) {
                    // Whole batch rejected — count as failed, keep snapshots as-is
                    // so the next run retries them.
                    $failed += count($chunk);
                    if (count($failedSamples) < 10) {
                        $failedSamples[] = ['hub' => $hubCode, 'status' => $result['status'] ?? 0, 'body' => substr((string) ($result['body'] ?? ''), 0, 200)];
                    }
                    Log::warning('Inventory push batch failed', ['hub' => $hubCode, 'status' => $result['status'] ?? 0, 'count' => count($chunk)]);
                } else {
                    $failedSet = $this->failedSkuSet($result['failed_skus'] ?? []);
                    foreach ($chunk as $s) {
                        if (isset($failedSet[$s['sku_code']])) {
                            $failed++;
                            if (count($failedSamples) < 10) {
                                $failedSamples[] = ['hub' => $hubCode, 'sku' => $s['sku_code']];
                            }
                            continue;
                        }
                        $this->upsertSnapshot($s['warehouse_code'], $s['item_code'], (string) $hubCode, $s['quantity']);
                        $pushed++;
                    }
                }

                $this->progress($event, ['pushed' => $pushed, 'failed' => $failed]);
            }
        }

        return [
            'ok' => $pushed,
            'failed' => $failed,
            'skipped' => $plan['skipped_unmapped'],
            'considered' => $plan['considered'],
            'cancelled' => $cancelled,
            'mode' => $mode,
            'seller_code' => $sellerCode,
            'failed_samples' => $failedSamples,
        ];
    }

    /**
     * Read SAP + map + delta-diff into the changed SKU list grouped by hub.
     * Shared by preview() (read-only) and pushToOmniful().
     *
     * @return array{by_hub:array<string,array<int,array<string,mixed>>>,considered:int,skipped_unmapped:int,seller_code:string,synced_hubs:int,total:int,note?:string}
     */
    private function buildPlan(string $mode): array
    {
        $clampNegative = (bool) config('omniful.inventory_push.clamp_negative_to_zero', true);
        $qtySource = strtolower((string) config('omniful.inventory_push.quantity_source', 'available'));

        $sellerCode = $this->resolveSellerCode();
        if ($sellerCode === '') {
            throw new \RuntimeException('Omniful seller_code is not configured (inventory_push.seller_code / sap_item_defaults.seller_code / IntegrationSetting.omniful_seller_code).');
        }

        // Only push to warehouses whose hub is already synced in Omniful. hub_code
        // == SAP WarehouseCode (see SapWarehouseSyncService), so the mapping is
        // the warehouse code itself.
        $syncedHubs = $this->syncedWarehouseCodes();
        if ($syncedHubs === []) {
            return ['by_hub' => [], 'considered' => 0, 'skipped_unmapped' => 0, 'seller_code' => $sellerCode, 'synced_hubs' => 0, 'total' => 0, 'note' => 'no synced warehouses'];
        }

        // m1: read Available per synced item x warehouse (restricted to synced hubs).
        $rows = app(SapServiceLayerClient::class)->fetchSyncedItemQuantities(array_keys($syncedHubs));

        // Snapshot map for delta detection.
        $snapshots = [];
        SapInventorySnapshot::query()
            ->select(['warehouse_code', 'item_code', 'quantity'])
            ->chunk(2000, function ($chunk) use (&$snapshots) {
                foreach ($chunk as $s) {
                    $snapshots[$s->warehouse_code . '|' . $s->item_code] = (int) $s->quantity;
                }
            });

        $byHub = [];
        $skippedUnmapped = 0;
        $considered = 0;
        foreach ($rows as $r) {
            $warehouse = (string) $r['warehouse_code'];
            if (!isset($syncedHubs[$warehouse])) {
                $skippedUnmapped++;
                continue;
            }

            $qty = $qtySource === 'in_stock' ? $r['in_stock'] : $r['available'];
            $qty = (int) round((float) $qty);
            if ($clampNegative && $qty < 0) {
                $qty = 0;
            }
            $considered++;

            if ($mode === 'delta') {
                $key = $warehouse . '|' . $r['item_code'];
                if (array_key_exists($key, $snapshots) && $snapshots[$key] === $qty) {
                    continue; // unchanged since last push
                }
            }

            $byHub[$warehouse][] = [
                'sku_code' => (string) $r['item_code'],
                'quantity' => $qty,
                'warehouse_code' => $warehouse,
                'item_code' => (string) $r['item_code'],
            ];
        }

        return [
            'by_hub' => $byHub,
            'considered' => $considered,
            'skipped_unmapped' => $skippedUnmapped,
            'seller_code' => $sellerCode,
            'synced_hubs' => count($syncedHubs),
            'total' => array_sum(array_map('count', $byHub)),
        ];
    }

    /**
     * Seller the SKUs live under — MUST match where items are pushed. Falls back
     * through the configured push seller, the item-sync seller, then the DB.
     */
    private function resolveSellerCode(): string
    {
        $candidates = [
            (string) config('omniful.inventory_push.seller_code', ''),
            (string) config('omniful.sap_item_defaults.seller_code', ''),
            (string) (IntegrationSetting::active()?->omniful_seller_code ?? ''),
        ];

        foreach ($candidates as $candidate) {
            $candidate = trim($candidate);
            if ($candidate !== '') {
                return $candidate;
            }
        }

        return '';
    }

    /**
     * Warehouse codes whose hub is already synced in Omniful (hub_code == code).
     *
     * @return array<string,true>
     */
    private function syncedWarehouseCodes(): array
    {
        $set = [];
        SapWarehouse::query()
            ->whereNotNull('omniful_synced_at')
            ->select(['code'])
            ->chunk(1000, function ($chunk) use (&$set) {
                foreach ($chunk as $warehouse) {
                    $code = trim((string) $warehouse->code);
                    if ($code !== '') {
                        $set[$code] = true;
                    }
                }
            });

        return $set;
    }

    private function upsertSnapshot(string $warehouseCode, string $itemCode, string $hubCode, int $quantity): void
    {
        SapInventorySnapshot::query()->updateOrCreate(
            ['warehouse_code' => $warehouseCode, 'item_code' => $itemCode],
            ['hub_code' => $hubCode, 'quantity' => $quantity, 'last_pushed_at' => now()],
        );
    }

    /**
     * @param array<int,mixed> $failedSkus
     * @return array<string,true>
     */
    private function failedSkuSet(array $failedSkus): array
    {
        $set = [];
        foreach ($failedSkus as $failed) {
            if (is_array($failed)) {
                $code = (string) ($failed['sku_code'] ?? $failed['code'] ?? $failed['sku'] ?? '');
            } else {
                $code = (string) $failed;
            }
            $code = trim($code);
            if ($code !== '') {
                $set[$code] = true;
            }
        }

        return $set;
    }

    /**
     * @param array<string,mixed> $merge
     */
    private function progress(SapSyncEvent $event, array $merge): void
    {
        $payload = (array) ($event->payload ?? []);
        $payload['progress'] = array_merge(
            (array) ($payload['progress'] ?? []),
            $merge,
            ['updated_at' => now()->toDateTimeString()]
        );
        $event->update(['payload' => $payload]);
    }

    private function isCancelled(SapSyncEvent $event): bool
    {
        return (string) (SapSyncEvent::query()->whereKey($event->id)->value('sap_status')) === 'cancel_requested';
    }

    private function activeEvent(): ?SapSyncEvent
    {
        return SapSyncEvent::query()
            ->where('source_type', self::SOURCE_TYPE)
            ->whereIn('sap_status', ['queued', 'running', 'cancel_requested'])
            ->where('updated_at', '>=', now()->subHours(6))
            ->latest('id')
            ->first();
    }

    private function shouldRequeueStaleEvent(SapSyncEvent $event): bool
    {
        return (string) $event->sap_status === 'queued'
            && $event->updated_at !== null
            && $event->updated_at->lt(now()->subMinutes(5));
    }

    private function markRequeued(SapSyncEvent $event, ?string $triggeredBy): void
    {
        $payload = (array) ($event->payload ?? []);
        $event->update([
            'sap_status' => 'queued',
            'sap_error' => null,
            'payload' => array_merge($payload, [
                'requeued_at' => now()->toDateTimeString(),
                'requeued_by' => $triggeredBy,
            ]),
        ]);
    }
}
