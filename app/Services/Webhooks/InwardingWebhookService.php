<?php

namespace App\Services\Webhooks;

use App\Models\OmnifulInwardingEvent;
use App\Models\OmnifulPurchaseOrderEvent;
use App\Models\SapSyncEvent;
use App\Services\SapServiceLayerClient;
use Illuminate\Database\QueryException;

class InwardingWebhookService
{
    public function process(OmnifulInwardingEvent $event): void
    {
        $payload = (array) ($event->payload ?? []);
        $data = (array) data_get($payload, 'data', []);
        $grnDetails = (array) data_get($data, 'grn_details', []);
        $eventName = strtolower(trim((string) data_get($payload, 'event_name', '')));
        $entityType = strtolower(trim((string) data_get($data, 'entity_type', '')));

        if (!$this->isGrnQcEvent($eventName, $entityType)) {
            $event->sap_status = 'ignored';
            $event->sap_error = 'Ignored: inwarding event is not a documented GRN QC purchase-order event';
            $event->save();

            return;
        }

        $items = $this->extractGrnItems($grnDetails, $data, $payload);
        if ($items === []) {
            $event->sap_status = 'ignored';
            $event->sap_error = 'Ignored: no GRN SKU lines found';
            $event->save();

            return;
        }

        $referenceId = $this->extractGrnReferenceId($data, $grnDetails, $payload);
        if ($referenceId !== null && $referenceId !== '' && $event->external_id !== $referenceId) {
            $event->external_id = $referenceId;
        }

        $hubCode = $this->extractDestinationHubCode($data, $grnDetails, $payload);
        $displayId = $this->extractPurchaseOrderDisplayId($data, $grnDetails, $payload);
        $eventKey = $this->buildInwardingEventKey($referenceId, $displayId, $hubCode, $items);

        $sync = $this->firstOrCreateSyncEvent(
            $eventKey,
            [
                'source_type' => 'omniful_inwarding_event',
                'source_id' => $event->id,
                'sap_action' => 'grpo',
                'sap_status' => 'pending',
                'payload' => $payload,
            ]
        );

        if (!$sync->wasRecentlyCreated && !in_array((string) $sync->sap_status, ['pending', 'failed'], true)) {
            $event->sap_status = $sync->sap_status === 'created' ? 'skipped' : (string) ($sync->sap_status ?: 'ignored');
            $event->sap_doc_entry = $sync->sap_doc_entry;
            $event->sap_doc_num = $sync->sap_doc_num;
            $event->sap_error = $sync->sap_error;
            $event->save();

            return;
        }

        $poEvent = $this->findPurchaseOrderEvent($displayId, $items, $hubCode);
        if (!$poEvent || !$poEvent->sap_doc_entry) {
            throw new \RuntimeException('SAP PO not found for documented GRN QC event');
        }

        $client = app(SapServiceLayerClient::class);
        $result = $client->createGoodsReceiptPOFromInventory(
            (int) $poEvent->sap_doc_entry,
            $items,
            $hubCode,
            $displayId
        );

        if (($result['ignored'] ?? false) === true) {
            $event->sap_status = 'ignored';
            $event->sap_error = (string) ($result['reason'] ?? 'Ignored: no receivable PO quantity');

            $sync->sap_status = 'ignored';
            $sync->sap_error = $event->sap_error;
        } else {
            $event->sap_status = 'created';
            $event->sap_doc_entry = $result['DocEntry'] ?? null;
            $event->sap_doc_num = $result['DocNum'] ?? null;
            $event->sap_error = null;

            $sync->sap_status = 'created';
            $sync->sap_doc_entry = $event->sap_doc_entry;
            $sync->sap_doc_num = $event->sap_doc_num;
            $sync->sap_error = null;
        }

        $event->save();
        $sync->save();
    }

    private function isGrnQcEvent(string $eventName, string $entityType): bool
    {
        if (!str_contains($eventName, 'grn.qc')) {
            return false;
        }

        if ($entityType === '') {
            return true;
        }

        return in_array($entityType, ['po', 'purchase_order'], true);
    }

    /**
     * @param array<string,mixed> $grnDetails
     * @param array<string,mixed> $data
     * @param array<string,mixed> $payload
     * @return array<int,array{seller_sku_code:string,quantity:float}>
     */
    private function extractGrnItems(array $grnDetails, array $data, array $payload): array
    {
        $sources = [
            data_get($grnDetails, 'skus', []),
            data_get($data, 'skus', []),
            data_get($payload, 'skus', []),
        ];

        foreach ($sources as $source) {
            $lines = [];
            foreach ((array) $source as $item) {
                $itemCode = $this->extractSkuCode((array) $item);
                if ($itemCode === '') {
                    continue;
                }

                $qty = data_get($item, 'received_quantity');
                if ($qty === null) {
                    $qty = data_get($item, 'accepted_quantity');
                }
                if ($qty === null) {
                    $qty = data_get($item, 'qc_pass_quantity');
                }
                if ($qty === null) {
                    $qty = data_get($item, 'passed_quantity');
                }
                if ($qty === null) {
                    $qty = data_get($item, 'putaway_quantity');
                }
                if ($qty === null) {
                    $qty = data_get($item, 'quantity_location_pass_inventory_sum');
                }
                if ($qty === null) {
                    $qty = data_get($item, 'quantity');
                }

                $qty = (float) ($qty ?? 0);
                if ($qty <= 0) {
                    continue;
                }

                $lines[] = [
                    'seller_sku_code' => $itemCode,
                    'quantity' => $qty,
                ];
            }

            if ($lines !== []) {
                return $lines;
            }
        }

        return [];
    }

    private function extractSkuCode(array $item): string
    {
        $candidates = [
            data_get($item, 'seller_sku_code'),
            data_get($item, 'sku_code'),
            data_get($item, 'sku.seller_sku_code'),
            data_get($item, 'sku.seller_sku_id'),
            data_get($item, 'seller_sku.seller_sku_code'),
            data_get($item, 'seller_sku.seller_sku_id'),
            data_get($item, 'item_code'),
            data_get($item, 'seller_sku_id'),
        ];

        foreach ($candidates as $value) {
            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
            if (is_numeric($value)) {
                return (string) $value;
            }
        }

        return '';
    }

    private function extractGrnReferenceId(array $data, array $grnDetails, array $payload): ?string
    {
        $candidates = [
            data_get($grnDetails, 'grn_id'),
            data_get($data, 'grn_id'),
            data_get($data, 'id'),
            data_get($payload, 'id'),
        ];

        foreach ($candidates as $value) {
            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
            if (is_numeric($value)) {
                return (string) $value;
            }
        }

        return null;
    }

    private function extractDestinationHubCode(array $data, array $grnDetails, array $payload): ?string
    {
        $candidates = [
            data_get($grnDetails, 'destination_hub_code'),
            data_get($data, 'destination_hub_code'),
            data_get($data, 'hub_code'),
            data_get($payload, 'destination_hub_code'),
            data_get($payload, 'hub_code'),
        ];

        foreach ($candidates as $value) {
            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
            if (is_numeric($value)) {
                return (string) $value;
            }
        }

        return null;
    }

    private function extractPurchaseOrderDisplayId(array $data, array $grnDetails, array $payload): ?string
    {
        $candidates = [
            data_get($data, 'entity_id'),
            data_get($data, 'display_id'),
            data_get($data, 'purchase_order_display_id'),
            data_get($grnDetails, 'purchase_order_display_id'),
            data_get($grnDetails, 'po_display_id'),
            data_get($payload, 'display_id'),
        ];

        foreach ($candidates as $value) {
            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
            if (is_numeric($value)) {
                return (string) $value;
            }
        }

        return null;
    }

    /**
     * @param array<int,array{seller_sku_code:string,quantity:float}> $items
     */
    private function buildInwardingEventKey(?string $referenceId, ?string $displayId, ?string $hubCode, array $items): string
    {
        $normalized = [];
        foreach ($items as $item) {
            $normalized[] = [
                (string) ($item['seller_sku_code'] ?? ''),
                (float) ($item['quantity'] ?? 0),
            ];
        }
        usort($normalized, fn ($a, $b) => $a[0] <=> $b[0]);

        return hash('sha256', json_encode([
            'reference_id' => $referenceId,
            'display_id' => $displayId,
            'hub_code' => $hubCode,
            'items' => $normalized,
        ]));
    }

    /**
     * @param array<int,array{seller_sku_code:string,quantity:float}> $items
     */
    private function findPurchaseOrderEvent(?string $displayId, array $items, ?string $hubCode): ?OmnifulPurchaseOrderEvent
    {
        if ($displayId !== null && $displayId !== '') {
            $event = OmnifulPurchaseOrderEvent::where('external_id', $displayId)
                ->whereNotNull('sap_doc_entry')
                ->latest()
                ->first();

            if ($event) {
                return $event;
            }
        }

        $itemCodes = array_values(array_unique(array_map(
            fn ($item) => (string) ($item['seller_sku_code'] ?? ''),
            $items
        )));
        $itemCodes = array_values(array_filter($itemCodes, fn ($code) => $code !== ''));

        if ($itemCodes === []) {
            return null;
        }

        $candidates = OmnifulPurchaseOrderEvent::query()
            ->whereNotNull('sap_doc_entry')
            ->orderByDesc('received_at')
            ->limit(50)
            ->get();

        foreach ($candidates as $candidate) {
            $payload = (array) ($candidate->payload ?? []);
            $candidateHub = data_get($payload, 'data.hub_code');
            if ($hubCode && $candidateHub && $candidateHub !== $hubCode) {
                continue;
            }

            $candidateCodes = $this->extractPurchaseOrderItemCodes($payload);
            if ($candidateCodes === []) {
                continue;
            }

            $allFound = true;
            foreach ($itemCodes as $code) {
                if (!in_array($code, $candidateCodes, true)) {
                    $allFound = false;
                    break;
                }
            }

            if ($allFound) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<int,string>
     */
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
                $codes[] = (string) $code;
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
