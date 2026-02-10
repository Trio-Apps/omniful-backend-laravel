<?php

namespace App\Services\Webhooks;

use App\Models\OmnifulInventoryEvent;
use App\Models\OmnifulPurchaseOrderEvent;
use App\Models\SapSyncEvent;
use App\Services\SapServiceLayerClient;
use Illuminate\Database\QueryException;

class InventoryWebhookService
{
    public function process(OmnifulInventoryEvent $event): void
    {
        $eventKey = null;
        try {
            $mapper = app(WebhookStatusMapper::class);
            $payload = $event->payload ?? [];
            $eventName = strtolower(trim((string) data_get($payload, 'event_name', '')));
            $action = strtolower(trim((string) data_get($payload, 'action', '')));
            $entity = strtolower(trim((string) data_get($payload, 'entity', '')));
            $data = data_get($payload, 'data', []);
            $items = data_get($data, 'hub_inventory_items', data_get($data, 'items', []));
            $hubCode = data_get($data, 'hub_code');
            $route = $mapper->mapInventoryRoute($eventName, $action, $entity);

            if (($route['sap_action'] ?? null) === 'grpo') {
                $displayId = $this->extractPurchaseOrderDisplayId($payload);
                $poEvent = null;
                $eventKey = $this->buildInventoryEventKey($payload, $items, $displayId);

                $sync = $this->firstOrCreateSyncEvent(
                    $eventKey,
                    [
                        'source_type' => 'omniful_inventory_event',
                        'source_id' => $event->id,
                        'sap_action' => 'grpo',
                        'sap_status' => 'pending',
                        'payload' => $payload,
                    ]
                );

                if ($sync->wasRecentlyCreated || $sync->sap_status === 'pending') {
                    if ($displayId) {
                        $poEvent = OmnifulPurchaseOrderEvent::where('external_id', $displayId)
                            ->whereNotNull('sap_doc_entry')
                            ->latest()
                            ->first();
                    } else {
                        $matches = $this->findMatchingPurchaseOrders($items, $hubCode);
                        if (count($matches) === 1) {
                            $poEvent = $matches[0];
                            $displayId = $poEvent->external_id;
                        } elseif (count($matches) > 1) {
                            $ids = array_map(fn ($match) => $match->external_id, $matches);
                            throw new \RuntimeException('Multiple matching SAP POs found for GRPO: ' . implode(', ', $ids));
                        }
                    }

                    if (!$poEvent || !$poEvent->sap_doc_entry) {
                        throw new \RuntimeException('SAP PO not found for GRPO (missing sap_doc_entry)');
                    }

                    $client = app(SapServiceLayerClient::class);
                    $result = $client->createGoodsReceiptPOFromInventory((int) $poEvent->sap_doc_entry, is_array($items) ? $items : [], $hubCode, $displayId);

                    if (($result['ignored'] ?? false) === true) {
                        $event->sap_status = 'ignored';
                        $event->sap_error = (string) ($result['reason'] ?? 'Ignored: no receivable PO quantity');
                        $event->save();

                        $sync->sap_status = 'ignored';
                        $sync->sap_error = $event->sap_error;
                    } else {
                        $event->sap_status = 'created';
                        $event->sap_doc_entry = $result['DocEntry'] ?? null;
                        $event->sap_doc_num = $result['DocNum'] ?? null;
                        $event->sap_error = null;
                        $event->save();

                        $sync->sap_status = 'created';
                        $sync->sap_doc_entry = $event->sap_doc_entry;
                        $sync->sap_doc_num = $event->sap_doc_num;
                    }
                    $sync->save();
                }
            } elseif (($route['sap_action'] ?? null) === 'manual_inventory_adjustment') {
                $items = is_array($items) ? $items : [];
                $client = app(SapServiceLayerClient::class);
                $deltas = $this->calculateInventoryAdjustmentsFromSap($items, $hubCode, $client);

                if ($deltas['receipt'] === [] && $deltas['issue'] === []) {
                    $event->sap_status = 'ignored';
                    $event->sap_error = $deltas['reason'] ?? 'Ignored: no quantity change detected';
                    $event->save();
                } else {
                    $summary = [];
                    $skipped = [];
                    $existingErrors = [];
                    $existingDocs = [];

                    if ($deltas['receipt'] !== []) {
                        $eventKey = $this->buildInventoryEventKey($payload, $deltas['receipt'], null, 'gr', (string) $event->id);
                        $sync = $this->firstOrCreateSyncEvent(
                            $eventKey,
                            [
                                'source_type' => 'omniful_inventory_event',
                                'source_id' => $event->id,
                                'sap_action' => 'goods_receipt',
                                'sap_status' => 'pending',
                                'payload' => $payload,
                            ]
                        );
                        if ($sync->wasRecentlyCreated || $sync->sap_status === 'pending') {
                            $client->syncInventoryItems($deltas['receipt']);
                            $result = $client->createInventoryGoodsReceipt($deltas['receipt'], $hubCode, 'Omniful manual edit');
                            $summary['gr'] = $result['DocNum'] ?? null;
                            $sync->sap_status = 'created';
                            $sync->sap_doc_entry = $result['DocEntry'] ?? null;
                            $sync->sap_doc_num = $result['DocNum'] ?? null;
                            $sync->save();
                        } else {
                            $skipped[] = 'gr';
                            if ($sync->sap_status === 'failed' && $sync->sap_error) {
                                $existingErrors[] = $sync->sap_error;
                            }
                            if ($sync->sap_doc_num) {
                                $existingDocs[] = $sync->sap_doc_num;
                            }
                        }
                    }

                    if ($deltas['issue'] !== []) {
                        $eventKey = $this->buildInventoryEventKey($payload, $deltas['issue'], null, 'gi', (string) $event->id);
                        $sync = $this->firstOrCreateSyncEvent(
                            $eventKey,
                            [
                                'source_type' => 'omniful_inventory_event',
                                'source_id' => $event->id,
                                'sap_action' => 'goods_issue',
                                'sap_status' => 'pending',
                                'payload' => $payload,
                            ]
                        );
                        if ($sync->wasRecentlyCreated || $sync->sap_status === 'pending') {
                            $client->syncInventoryItems($deltas['issue']);
                            $result = $client->createInventoryGoodsIssue($deltas['issue'], $hubCode, 'Omniful manual edit');
                            $summary['gi'] = $result['DocNum'] ?? null;
                            $sync->sap_status = 'created';
                            $sync->sap_doc_entry = $result['DocEntry'] ?? null;
                            $sync->sap_doc_num = $result['DocNum'] ?? null;
                            $sync->save();
                        } else {
                            $skipped[] = 'gi';
                            if ($sync->sap_status === 'failed' && $sync->sap_error) {
                                $existingErrors[] = $sync->sap_error;
                            }
                            if ($sync->sap_doc_num) {
                                $existingDocs[] = $sync->sap_doc_num;
                            }
                        }
                    }

                    if ($summary !== []) {
                        $event->sap_status = count($summary) > 1 ? 'created_mixed' : 'created';
                        $event->sap_doc_num = $summary['gr'] ?? $summary['gi'] ?? null;
                        $event->sap_error = $summary ? json_encode($summary, JSON_UNESCAPED_UNICODE) : null;
                        $event->save();
                    } elseif ($existingErrors !== []) {
                        $event->sap_status = 'failed';
                        $event->sap_error = $existingErrors[0];
                        $event->sap_doc_num = $existingDocs[0] ?? null;
                        $event->save();
                    } else {
                        $event->sap_status = 'ignored';
                        $event->sap_error = $skipped !== []
                            ? 'Ignored: duplicate event already synced'
                            : ($deltas['reason'] ?? 'Ignored: no inventory delta found');
                        $event->sap_doc_num = $existingDocs[0] ?? null;
                        $event->save();
                    }
                }
            } else {
                $reason = (string) ($route['reason'] ?? 'Ignored: not mapped to SAP action');
                $event->sap_status = 'ignored';
                $event->sap_error = $reason . ' (route=' . ($route['key'] ?? '-') . ')';
                $event->save();
            }

            if ($event->sap_status === null) {
                $event->sap_status = 'ignored';
                $event->sap_error = 'Ignored: no SAP handler matched';
                $event->save();
            }
        } catch (\Throwable $e) {
            if (!empty($eventKey)) {
                SapSyncEvent::where('event_key', $eventKey)->update([
                    'sap_status' => 'failed',
                    'sap_error' => $e->getMessage(),
                ]);
            }
            throw $e;
        }
    }

    private function extractPurchaseOrderDisplayId(array $payload): ?string
    {
        $candidates = [
            data_get($payload, 'data.display_id'),
            data_get($payload, 'data.purchase_order_display_id'),
            data_get($payload, 'data.purchase_order_id'),
            data_get($payload, 'data.po_id'),
            data_get($payload, 'data.reference_id'),
            data_get($payload, 'data.order_id'),
        ];

        foreach ($candidates as $value) {
            if (is_string($value) && $value !== '') {
                return $value;
            }
            if (is_numeric($value)) {
                return (string) $value;
            }
        }

        return null;
    }

    private function buildInventoryEventKey(array $payload, array $items, ?string $displayId, ?string $suffix = null, ?string $unique = null): string
    {
        $action = (string) data_get($payload, 'action', '');
        $entity = (string) data_get($payload, 'entity', '');
        $eventName = (string) data_get($payload, 'event_name', '');
        $hubCode = (string) data_get($payload, 'data.hub_code', '');

        $normalized = [];
        foreach ($items as $item) {
            $itemCode = data_get($item, 'seller_sku_code')
                ?? data_get($item, 'sku_code')
                ?? data_get($item, 'seller_sku_id');
            $qty = data_get($item, 'quantity_location_pass_inventory_sum');
            if ($qty === null) {
                $qty = data_get($item, 'quantity_on_hand');
            }
            if ($qty === null) {
                $qty = data_get($item, 'quantity');
            }
            if ($itemCode) {
                $normalized[] = [$itemCode, (float) $qty];
            }
        }
        usort($normalized, fn ($a, $b) => $a[0] <=> $b[0]);

        $keyPayload = [
            'event' => $eventName,
            'action' => $action,
            'entity' => $entity,
            'hub' => $hubCode,
            'display_id' => $displayId,
            'items' => $normalized,
            'suffix' => $suffix,
            'unique' => $unique,
        ];

        return hash('sha256', json_encode($keyPayload));
    }

    private function calculateInventoryAdjustmentsFromSap(array $items, ?string $hubCode, SapServiceLayerClient $client): array
    {
        $receipt = [];
        $issue = [];
        $noChange = 0;

        foreach ($items as $item) {
            $itemCode = data_get($item, 'seller_sku_code')
                ?? data_get($item, 'sku_code')
                ?? data_get($item, 'seller_sku_id');

            if (!$itemCode) {
                continue;
            }

            $current = data_get($item, 'quantity_location_pass_inventory_sum');
            if ($current === null) {
                $current = data_get($item, 'quantity_on_hand');
            }
            if ($current === null) {
                $current = data_get($item, 'quantity');
            }
            $uom = (string) data_get($item, 'uom', '');
            $current = $this->normalizeQuantity((float) $current, $uom);

            $sapQty = $client->getWarehouseOnHand($itemCode, $hubCode);
            $delta = $current - (float) $sapQty;
            if (abs($delta) < 0.0001) {
                $noChange++;
                continue;
            }

            $line = [
                'seller_sku_code' => $itemCode,
                'quantity' => abs($delta),
            ];

            if ($delta > 0) {
                $receipt[] = $line;
            } else {
                $issue[] = $line;
            }
        }

        $reason = null;
        if ($receipt === [] && $issue === []) {
            $reason = $noChange > 0
                ? 'Ignored: no quantity change detected'
                : 'Ignored: no inventory delta found';
        }

        return ['receipt' => $receipt, 'issue' => $issue, 'reason' => $reason];
    }

    private function normalizeQuantity(float $qty, string $uom): float
    {
        $uom = strtolower(trim($uom));
        if ($uom === 'kg' && $qty >= 1000 && fmod($qty, 1000.0) < 0.0001) {
            return $qty / 1000.0;
        }

        return $qty;
    }

    /**
     * @return array<int,OmnifulPurchaseOrderEvent>
     */
    private function findMatchingPurchaseOrders(array $items, ?string $hubCode): array
    {
        $codes = [];
        foreach ($items as $item) {
            $code = data_get($item, 'seller_sku_code')
                ?? data_get($item, 'sku_code')
                ?? data_get($item, 'seller_sku_id');
            if ($code) {
                $codes[] = $code;
            }
        }
        $codes = array_values(array_unique($codes));
        if ($codes === []) {
            return [];
        }

        $candidates = OmnifulPurchaseOrderEvent::query()
            ->whereNotNull('sap_doc_entry')
            ->orderByDesc('received_at')
            ->limit(50)
            ->get();

        $matches = [];
        foreach ($candidates as $candidate) {
            $payload = $candidate->payload ?? [];
            $candidateHub = data_get($payload, 'data.hub_code');
            if ($hubCode && $candidateHub && $candidateHub !== $hubCode) {
                continue;
            }

            $candidateCodes = $this->extractPurchaseOrderItemCodes($payload);
            if ($candidateCodes === []) {
                continue;
            }

            $allFound = true;
            foreach ($codes as $code) {
                if (!in_array($code, $candidateCodes, true)) {
                    $allFound = false;
                    break;
                }
            }
            if ($allFound) {
                $matches[] = $candidate;
            }
        }

        return $matches;
    }

    private function extractPurchaseOrderItemCodes(array $payload): array
    {
        $items = data_get($payload, 'data.purchase_order_items', data_get($payload, 'purchase_order_items', []));
        $codes = [];
        foreach ((array) $items as $item) {
            $code = data_get($item, 'sku.seller_sku_code')
                ?? data_get($item, 'sku.seller_sku_id')
                ?? data_get($item, 'seller_sku_code')
                ?? data_get($item, 'sku_code');
            if ($code) {
                $codes[] = $code;
            }
        }

        return array_values(array_unique($codes));
    }

    private function firstOrCreateSyncEvent(string $eventKey, array $defaults): SapSyncEvent
    {
        try {
            return SapSyncEvent::firstOrCreate(
                ['event_key' => $eventKey],
                $defaults
            );
        } catch (QueryException $e) {
            $existing = SapSyncEvent::where('event_key', $eventKey)->first();
            if ($existing) {
                return $existing;
            }

            throw $e;
        }
    }
}
