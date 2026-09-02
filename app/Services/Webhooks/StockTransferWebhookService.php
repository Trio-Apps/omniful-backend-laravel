<?php

namespace App\Services\Webhooks;

use App\Services\SapServiceLayerClient;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;

class StockTransferWebhookService
{
    /**
     * How extractTransferLines() resolved the quantities: 'ok', or 'unavailable'
     * when neither the received nor the shipped quantity could be read. In the
     * latter case the event is DEFERRED for retry rather than posted — the old
     * behaviour (falling back to the approved qty) is what put phantom stock into
     * SAP.
     */
    private string $quantityResolution = 'ok';

    public function process(Model $event): void
    {
        $payload = (array) ($event->payload ?? []);
        $data = (array) data_get($payload, 'data', []);
        $eventName = strtolower(trim((string) data_get($payload, 'event_name', '')));
        $status = strtolower(trim($this->extractTransferStatus($data, $payload)));

        if (!$this->isActionableStockTransferEvent($eventName, $status)) {
            $event->sap_status = 'ignored';
            $event->sap_error = 'Ignored: stock transfer request event/status is not actionable';
            $event->save();
            return;
        }

        $requestId = $this->extractStockTransferRequestId($data, $payload);
        if ($requestId !== null && $requestId !== '' && $event->external_id !== $requestId) {
            $event->external_id = $requestId;
            $event->save();
        }

        // Serialize every event for the SAME transfer (e.g. received + shipped, or
        // retries) behind a per-transfer lock. Processing is synchronous, so without
        // this two simultaneous webhooks could both pass the dedup check before
        // either saves sap_doc_entry — creating a DUPLICATE StockTransfer in SAP.
        $externalId = (string) ($event->external_id ?? '');
        if ($externalId === '') {
            $this->processTransfer($event, $data, $payload, $status);

            return;
        }

        $lock = \Illuminate\Support\Facades\Cache::lock('sto_process_' . $externalId, 120);
        try {
            $lock->block(20);
            $this->processTransfer($event, $data, $payload, $status);
        } catch (\Illuminate\Contracts\Cache\LockTimeoutException $e) {
            $event->sap_status = 'skipped';
            $event->sap_error = 'Skipped: another event for the same stock transfer is still processing.';
            $event->save();
        } finally {
            optional($lock)->release();
        }
    }

    private function processTransfer(Model $event, array $data, array $payload, string $status): void
    {
        if ($event->external_id) {
            $eventModel = $event::class;
            $existing = $eventModel::query()
                ->where('external_id', $event->external_id)
                ->where('id', '!=', $event->id)
                ->whereNotNull('sap_doc_entry')
                ->latest()
                ->first();

            if ($existing) {
                $event->sap_status = 'skipped';
                $event->sap_doc_entry = $existing->sap_doc_entry;
                $event->sap_doc_num = $existing->sap_doc_num;
                $event->sap_error = $existing->sap_error;
                $event->save();
                return;
            }

            // SAP-side guard (source of truth): if a StockTransfer for this STO
            // already exists in SAP (by U_omo reference), reuse it — never create a
            // second one. Catches duplicate deliveries/retries even if the local row
            // lost its doc entry, and (with the per-STO lock) closes the race.
            $existingSap = app(SapServiceLayerClient::class)
                ->findExistingStockTransferByReference((string) $event->external_id);
            if ($existingSap !== null) {
                $event->sap_status = 'skipped';
                $event->sap_doc_entry = (string) ($existingSap['DocEntry'] ?? '');
                $event->sap_doc_num = (string) ($existingSap['DocNum'] ?? '');
                $event->sap_error = 'Skipped: a stock transfer already exists in SAP for this STO ('
                    . (string) $event->external_id . ').';
                $event->save();
                return;
            }
        }

        $fromWarehouse = $this->extractFromWarehouse($data, $payload);
        $toWarehouse = $this->extractToWarehouse($data, $payload);

        if ($fromWarehouse === '' || $toWarehouse === '') {
            $event->sap_status = 'ignored';
            $event->sap_error = 'Ignored: missing source or destination warehouse';
            $event->save();
            return;
        }

        if ($fromWarehouse === $toWarehouse) {
            $event->sap_status = 'ignored';
            $event->sap_error = 'Ignored: source and destination warehouse are identical';
            $event->save();
            return;
        }

        $lines = $this->extractTransferLines($data, $payload);
        if ($lines === []) {
            // Distinguish "nothing to transfer" from "we could not read the real
            // quantities yet". The latter is DEFERRED (omniful:retry-pending-stock-transfers
            // picks it up once Omniful has booked the receipt) — posting the
            // approved qty instead is exactly the bug this guards against.
            $deferred = $this->quantityResolution === 'unavailable';
            $event->sap_status = $deferred ? 'pending_receipt' : 'ignored';
            $event->sap_error = $deferred
                ? 'Deferred: Omniful has not reported the received quantities yet; will retry (approved qty is never posted).'
                : 'Ignored: no stock transfer lines found';
            $event->save();
            return;
        }

        $client = app(SapServiceLayerClient::class);
        $client->syncInventoryItems($lines);

        $rawEventName = (string) data_get($payload, 'event_name', 'stock_transfer');
        $remarks = trim('Omniful stock transfer | ' . $rawEventName . ' | ' . $status . ' | ' . (string) ($event->external_id ?? ''));
        $inTransitWarehouse = $this->extractInTransitWarehouse($data, $payload);
        $useInTransit = $this->shouldUseInTransit($data, $payload, $inTransitWarehouse);

        $transferReference = (string) ($event->external_id ?? '');
        $transferChannel = $this->extractTransferChannel($data, $payload);

        if ($useInTransit) {
            $result = $client->createStockTransferViaTransit(
                $lines,
                $fromWarehouse,
                $toWarehouse,
                $inTransitWarehouse,
                $remarks,
                $transferReference,
                $transferChannel
            );
        } else {
            $result = $client->createStockTransfer(
                $lines,
                $fromWarehouse,
                $toWarehouse,
                $remarks,
                $transferReference,
                $transferChannel
            );
        }

        if (($result['ignored'] ?? false) === true) {
            $event->sap_status = 'ignored';
            $event->sap_error = (string) ($result['reason'] ?? 'Stock transfer ignored');
            $event->save();
            return;
        }

        $event->sap_status = (($result['mode'] ?? '') === 'two_step_in_transit') ? 'created_via_transit' : 'created';
        $event->sap_doc_entry = (string) ($result['DocEntry'] ?? '');
        $event->sap_doc_num = (string) ($result['DocNum'] ?? '');
        if (($result['mode'] ?? '') === 'two_step_in_transit') {
            $event->sap_error = json_encode([
                'mode' => 'two_step_in_transit',
                'leg1' => $result['leg1'] ?? null,
                'leg2' => $result['leg2'] ?? null,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        } else {
            $event->sap_error = null;
        }
        $event->save();
    }

    private function extractFromWarehouse(array $data, array $payload): string
    {
        $candidates = [
            data_get($data, 'from_hub_code'),
            data_get($data, 'source_hub_code'),
            data_get($data, 'source_hub.code'),
            data_get($data, 'source_warehouse_code'),
            data_get($data, 'from_warehouse_code'),
            data_get($data, 'origin_hub_code'),
            data_get($payload, 'from_hub_code'),
            data_get($payload, 'source_hub_code'),
            data_get($payload, 'source_hub.code'),
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

    private function extractToWarehouse(array $data, array $payload): string
    {
        $candidates = [
            data_get($data, 'to_hub_code'),
            data_get($data, 'destination_hub_code'),
            data_get($data, 'destination_hub.code'),
            data_get($data, 'destination_warehouse_code'),
            data_get($data, 'to_warehouse_code'),
            data_get($payload, 'to_hub_code'),
            data_get($payload, 'destination_hub_code'),
            data_get($payload, 'destination_hub.code'),
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

    /**
     * @return array<int,array{seller_sku_code:string,quantity:float}>
     */
    private function extractTransferLines(array $data, array $payload): array
    {
        // The STO REQUEST webhook only carries the APPROVED quantity, never the
        // quantity that physically moved. Resolve the real quantity per SKU from
        // Omniful's dedicated STO endpoint (RECEIVED — what SAP must move), and
        // fall back ONLY to the matching STO order's packed/picked qty (SHIPPED).
        //
        // The webhook's approved qty is NEVER used: it is precisely the wrong
        // number (a 1000-approved / 101-received transfer once moved 899 phantom
        // units into SAP). When neither real source is available the caller defers
        // the event for retry instead of guessing.
        $this->quantityResolution = 'ok';
        $shippedBySku = $this->resolveOrderActualQuantities($data, $payload);
        $actualBySku = $this->resolveReceivedQuantities($data, $payload);

        if ($actualBySku === []) {
            $actualBySku = $shippedBySku;
            if ($actualBySku !== []) {
                Log::warning('STO: received qty unavailable, falling back to the SHIPPED (packed) qty', [
                    'sto' => $this->extractStockTransferRequestId($data, $payload),
                ]);
            }
        }

        if ($actualBySku === []) {
            Log::warning('STO: neither received nor shipped qty available — deferring instead of posting the approved qty', [
                'sto' => $this->extractStockTransferRequestId($data, $payload),
            ]);
            $this->quantityResolution = 'unavailable';

            return [];
        }

        // Sanity guard: never move more than what was actually shipped. If the two
        // sources disagree the smaller one wins — over-stating the destination is
        // the failure mode we are protecting against.
        foreach ($actualBySku as $sku => $qty) {
            $shipped = $shippedBySku[$sku] ?? null;
            if ($shipped !== null && (float) $qty > (float) $shipped + 0.001) {
                Log::warning('STO: resolved qty exceeds the shipped qty; capping to shipped', [
                    'sto' => $this->extractStockTransferRequestId($data, $payload),
                    'sku' => $sku,
                    'resolved' => $qty,
                    'shipped' => $shipped,
                ]);
                $actualBySku[$sku] = (float) $shipped;
            }
        }

        $sources = [
            data_get($data, 'stock_transfer_items', []),
            data_get($data, 'transfer_items', []),
            data_get($data, 'order_items', []),
            data_get($data, 'items', []),
            data_get($payload, 'stock_transfer_items', []),
            data_get($payload, 'order_items', []),
            data_get($payload, 'items', []),
        ];

        foreach ($sources as $source) {
            $lines = [];
            foreach ((array) $source as $item) {
                $itemCode = $this->extractTransferItemCode((array) $item);
                if (!$itemCode) {
                    continue;
                }

                // ONLY the real moved quantity (received, else shipped). An item
                // missing from the map — or received as 0 — never physically
                // arrived, so it must not be transferred in SAP. The webhook's
                // approved/requested quantities are deliberately not consulted.
                $qty = (float) ($actualBySku[(string) $itemCode] ?? 0);
                if ($qty <= 0) {
                    continue;
                }

                $lines[] = [
                    'seller_sku_code' => (string) $itemCode,
                    'quantity' => $qty,
                ];
            }

            if ($lines !== []) {
                return $this->aggregateTransferLines($lines);
            }
        }

        return [];
    }

    /**
     * RECEIVED quantity per SKU from Omniful's dedicated STO endpoint — the only
     * source that exposes `received_quantity`. SAP must move what the destination
     * hub ACTUALLY RECEIVED, not what was approved (request webhook) or shipped
     * (order packed qty): a short receipt (damage/loss in transit) would otherwise
     * overstate the destination's stock.
     *
     * Returns [] when the STO id is unknown or the endpoint fails, so the caller
     * falls back to the shipped quantity.
     *
     * @return array<string,float>
     */
    private function resolveReceivedQuantities(array $data, array $payload): array
    {
        $stoId = trim((string) ($this->extractStockTransferRequestId($data, $payload) ?? ''));
        if ($stoId === '') {
            return [];
        }

        try {
            $res = app(\App\Services\OmnifulApiClient::class)->fetchStockTransferReceivedQuantities($stoId);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('STO received-qty lookup failed; falling back to shipped qty', [
                'sto' => $stoId,
                'error' => substr($e->getMessage(), 0, 200),
            ]);

            return [];
        }

        if (($res['ok'] ?? false) !== true) {
            // Silent until now — this is the path that let a transient API failure
            // slip through to the approved quantity without leaving any trace.
            Log::warning('STO received-qty endpoint returned no usable data', [
                'sto' => $stoId,
                'status' => $res['status'] ?? 0,
            ]);

            return [];
        }

        // An all-zero payload means the receipt is not booked yet — never post a
        // zero-quantity transfer.
        if (array_sum($res['quantities']) <= 0) {
            Log::warning('STO received-qty is all zero — receipt not booked yet', ['sto' => $stoId]);

            return [];
        }

        return (array) $res['quantities'];
    }

    /**
     * The physically-moved quantity per SKU (packed, else picked) from the STO
     * ORDER that the normal order webhook pulled — keyed by SKU code. Unlike the
     * STO request webhook, the order carries the real quantity, not the approved
     * one. Empty when the order is not (yet) in our DB, so the caller safely
     * falls back to the request webhook's approved quantity.
     *
     * The order's packed_quantity is the CUMULATIVE total for the transfer, so a
     * single transfer of that quantity is correct even when the request is
     * received across several GRNs (and the per-STO idempotency prevents any
     * double post).
     *
     * @return array<string,float>
     */
    private function resolveOrderActualQuantities(array $data, array $payload): array
    {
        $stoId = trim((string) ($this->extractStockTransferRequestId($data, $payload) ?? ''));
        if ($stoId === '') {
            return [];
        }

        $order = \App\Models\OmnifulOrder::where('external_id', $stoId)->first();
        if ($order === null) {
            return [];
        }

        $op = is_array($order->last_payload)
            ? $order->last_payload
            : (array) json_decode((string) $order->last_payload, true);
        $od = (array) data_get($op, 'data', $op);
        $items = data_get($od, 'order_items') ?? data_get($od, 'items') ?? [];

        $map = [];
        foreach ((array) $items as $it) {
            $sku = trim((string) (data_get($it, 'sku_code') ?? data_get($it, 'seller_sku_code') ?? ''));
            if ($sku === '') {
                continue;
            }
            $actual = data_get($it, 'packed_quantity');
            if ($actual === null || (float) $actual <= 0) {
                $actual = data_get($it, 'picked_quantity');
            }
            if ($actual !== null && (float) $actual > 0) {
                $map[$sku] = ((float) ($map[$sku] ?? 0)) + (float) $actual;
            }
        }

        return $map;
    }

    private function isActionableStockTransferEvent(string $eventName, string $status): bool
    {
        // STRICT: only the configured events (default sto.received.event +
        // sto.shipped.event) post to SAP. Every other event/status is ignored.
        $eventName = strtolower(trim($eventName));
        if ($eventName === '') {
            return false;
        }

        $allowedEvents = (array) config('omniful.stock_transfer.actionable_events', ['sto.received.event', 'sto.shipped.event']);
        foreach ($allowedEvents as $allowed) {
            $allowed = strtolower(trim((string) $allowed));
            if ($allowed === '') {
                continue;
            }
            if ($eventName === $allowed || str_contains($eventName, $allowed)) {
                return true;
            }
            // Tolerant fallback: match the distinctive token (e.g. ".received.")
            // so a minor event-name formatting difference still resolves.
            $parts = array_values(array_filter(explode('.', $allowed)));
            $token = $parts[1] ?? ($parts[0] ?? '');
            if ($token !== '' && str_contains($eventName, '.' . $token . '.')) {
                return true;
            }
        }

        return false;
    }

    private function extractStockTransferRequestId(array $data, array $payload): ?string
    {
        $candidates = [
            data_get($data, 'sto_id'),
            data_get($data, 'sto_request_id'),
            data_get($data, 'status_reference_id'),
            data_get($data, 'display_id'),
            data_get($data, 'id'),
            data_get($payload, 'sto_id'),
            data_get($payload, 'sto_request_id'),
            data_get($payload, 'display_id'),
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

    private function extractTransferChannel(array $data, array $payload): string
    {
        $candidates = [
            data_get($data, 'sales_channel.name'),
            data_get($data, 'sales_channel.tag'),
            data_get($data, 'sales_channel'),
            data_get($data, 'channel_name'),
            data_get($data, 'channel'),
            data_get($data, 'source'),
            data_get($data, 'store_name'),
            data_get($payload, 'sales_channel.name'),
            data_get($payload, 'source'),
            data_get($payload, 'store_name'),
        ];

        foreach ($candidates as $candidate) {
            if (is_array($candidate)) {
                $candidate = $candidate['name'] ?? $candidate['tag'] ?? $candidate['code'] ?? null;
            }
            $value = trim((string) ($candidate ?? ''));
            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }

    private function extractTransferStatus(array $data, array $payload): string
    {
        $candidates = [
            data_get($data, 'status'),
            data_get($data, 'status_code'),
            data_get($payload, 'status'),
            data_get($payload, 'status_code'),
        ];

        foreach ($candidates as $candidate) {
            $value = trim((string) $candidate);
            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }

    private function extractTransferItemCode(array $item): string
    {
        $candidates = [
            data_get($item, 'seller_sku_code'),
            data_get($item, 'sku_code'),
            data_get($item, 'item_code'),
            data_get($item, 'seller_sku.seller_sku_code'),
            data_get($item, 'seller_sku.seller_sku_id'),
            data_get($item, 'sku.seller_sku_code'),
            data_get($item, 'sku.seller_sku_id'),
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

    /**
     * @param array<int,array{seller_sku_code:string,quantity:float}> $lines
     * @return array<int,array{seller_sku_code:string,quantity:float}>
     */
    private function aggregateTransferLines(array $lines): array
    {
        $grouped = [];

        foreach ($lines as $line) {
            $itemCode = (string) ($line['seller_sku_code'] ?? '');
            $quantity = (float) ($line['quantity'] ?? 0);
            if ($itemCode === '' || $quantity <= 0) {
                continue;
            }

            $grouped[$itemCode] = ($grouped[$itemCode] ?? 0.0) + $quantity;
        }

        $result = [];
        foreach ($grouped as $itemCode => $quantity) {
            $result[] = [
                'seller_sku_code' => $itemCode,
                'quantity' => $quantity,
            ];
        }

        return $result;
    }

    private function extractInTransitWarehouse(array $data, array $payload): string
    {
        $candidates = [
            data_get($data, 'in_transit_hub_code'),
            data_get($data, 'transit_hub_code'),
            data_get($data, 'in_transit_warehouse_code'),
            data_get($payload, 'in_transit_hub_code'),
            data_get($payload, 'transit_hub_code'),
            config('omniful.stock_transfer.in_transit_warehouse', ''),
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

    private function shouldUseInTransit(array $data, array $payload, string $inTransitWarehouse): bool
    {
        if (!(bool) config('omniful.stock_transfer.in_transit_enabled', false)) {
            return false;
        }

        if ($inTransitWarehouse === '') {
            return false;
        }

        if ((bool) config('omniful.stock_transfer.force_in_transit', false)) {
            return true;
        }

        $flags = [
            data_get($data, 'use_in_transit'),
            data_get($data, 'via_in_transit'),
            data_get($payload, 'use_in_transit'),
            data_get($payload, 'via_in_transit'),
        ];

        foreach ($flags as $flag) {
            if (is_bool($flag) && $flag === true) {
                return true;
            }
            if (is_string($flag) && in_array(strtolower(trim($flag)), ['1', 'true', 'yes', 'y'], true)) {
                return true;
            }
            if (is_numeric($flag) && (int) $flag === 1) {
                return true;
            }
        }

        $transferType = strtolower(trim((string) (data_get($data, 'transfer_type') ?? data_get($payload, 'transfer_type') ?? '')));
        return in_array($transferType, ['main_to_branch_via_transit', 'branch_to_branch_via_transit', 'via_transit'], true);
    }
}
