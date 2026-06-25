<?php

namespace App\Services;

use App\Jobs\RunSapItemCleanup;
use App\Models\OmnifulOrder;
use App\Models\SapCleanupTarget;
use App\Models\SapSyncEvent;
use App\Services\Webhooks\WebhookRetryService;
use Illuminate\Support\Str;

/**
 * Maintenance worklist: reverse AR Reserve Invoices tied to wrongly auto-created
 * items, and re-send the orders later. Targets (one per invoice) are SCANNED into
 * the sap_cleanup_targets table and persist; each can then be Checked (re-read
 * live), Cancelled (reversed -> "<order>-0reversed" + order re-queued to pending),
 * or Resent (Force Resend to SAP). Bulk runs go through a background job tracked
 * on SapSyncEvent. See SapServiceLayerClient::cleanupReverseInvoice().
 */
class SapItemCleanupService
{
    public const SOURCE_TYPE = 'sap_item_cleanup';

    /** @var array<int,string> */
    public const MODES = ['product_id', 'sap_doc_number', 'omniful_order_id'];

    /** @var array<int,string> */
    public const BULK_ACTIONS = ['check', 'cancel', 'resend'];

    public function __construct(private SapServiceLayerClient $client)
    {
    }

    /**
     * Resolve the target invoice DocEntries for a mode + value (read-only).
     *
     * @return int[]
     */
    public function resolveDocEntries(string $mode, string $value): array
    {
        $value = trim($value);
        if ($value === '') {
            return [];
        }

        return match ($mode) {
            'product_id' => $this->resolveInvoicesByItemLocally($value),
            'sap_doc_number' => $this->client->findInvoiceDocEntriesByDocNum($value),
            'omniful_order_id' => $this->client->findOrderInvoiceDocEntriesHoldingReference($value),
            default => [],
        };
    }

    /**
     * Resolve invoice DocEntries for a product/item code via the LOCAL order
     * mirror: the SAP Service Layer cannot filter invoices by a line ItemCode
     * (DocumentLines/any() is rejected and list queries omit the lines), so we
     * find the Omniful orders whose stored payload contains the SKU, then resolve
     * each order's invoice by its U_omo reference. Already-reversed orders (whose
     * invoice U_omo was renamed) are naturally skipped.
     *
     * @return int[]
     */
    private function resolveInvoicesByItemLocally(string $sku): array
    {
        $sku = trim($sku);
        if ($sku === '') {
            return [];
        }

        $externalIds = OmnifulOrder::query()
            ->where('last_payload', 'like', '%' . $sku . '%')
            ->orderByDesc('id')
            ->limit(2000)
            ->pluck('external_id')
            ->filter()
            ->unique()
            ->values();

        $docEntries = [];
        foreach ($externalIds as $externalId) {
            foreach ($this->client->findOrderInvoiceDocEntriesHoldingReference((string) $externalId) as $docEntry) {
                $docEntries[] = (int) $docEntry;
            }
        }

        return array_values(array_unique($docEntries));
    }

    /**
     * Scan SAP for matching invoices (read-only) and upsert them into the
     * persistent worklist. Existing rows are refreshed, not duplicated.
     *
     * @return array{found:int,rows:int,added:int,updated:int}
     */
    /**
     * Whether the latest scan stopped (failed/cancelled) with work still pending,
     * so a "Continue" button can resume it.
     */
    public function scanResumable(): bool
    {
        $event = $this->latestScanEvent();
        if ($event === null) {
            return false;
        }

        $pending = (array) (($event->payload['pending'] ?? []));

        return in_array((string) $event->sap_status, ['failed', 'cancelled'], true) && $pending !== [];
    }

    /**
     * Resume the latest scan from where it stopped (re-dispatches the same event;
     * its payload still holds the remaining work-list).
     *
     * @return array{ok:bool,reason?:string,event:?\App\Models\SapSyncEvent}
     */
    public function continueScan(): array
    {
        $event = $this->latestScanEvent();
        if ($event === null) {
            return ['ok' => false, 'reason' => 'No scan to continue', 'event' => null];
        }

        $active = SapSyncEvent::query()
            ->where('source_type', self::SOURCE_TYPE)
            ->whereIn('sap_status', ['queued', 'running'])
            ->where('id', '!=', $event->id)
            ->where('updated_at', '>=', now()->subHours(6))
            ->exists();
        if ($active) {
            return ['ok' => false, 'reason' => 'Another cleanup is already running', 'event' => $event];
        }

        $event->update(['sap_status' => 'queued', 'sap_error' => null]);
        RunSapItemCleanup::dispatch($event->id);

        return ['ok' => true, 'event' => $event];
    }

    private function latestScanEvent(): ?SapSyncEvent
    {
        return SapSyncEvent::query()
            ->where('source_type', self::SOURCE_TYPE)
            ->where('sap_action', 'item_cleanup_scan')
            ->latest('id')
            ->first();
    }

    /**
     * Build a scan's pending work-list. Returns [kind, list]: 'orders' (order ids,
     * each resolved to its invoices during processing) for product/order modes, or
     * 'docs' (doc entries resolved up front) for the SAP-doc-number mode.
     *
     * @return array{0:string,1:array<int,string>}
     */
    private function buildScanPending(string $mode, string $value): array
    {
        $value = trim($value);
        if ($value === '') {
            return ['orders', []];
        }

        if ($mode === 'product_id') {
            $ids = OmnifulOrder::query()
                ->where('last_payload', 'like', '%' . $value . '%')
                ->orderByDesc('id')
                ->limit(5000)
                ->pluck('external_id')
                ->filter()
                ->unique()
                ->values()
                ->all();

            return ['orders', array_map('strval', $ids)];
        }

        if ($mode === 'omniful_order_id') {
            return ['orders', [$value]];
        }

        return ['docs', array_map('strval', $this->client->findInvoiceDocEntriesByDocNum($value))];
    }

    /**
     * Process one scan token (an order id, or a doc entry) and upsert its invoice
     * worklist row(s).
     *
     * @return array{added:int,updated:int}
     */
    private function processScanToken(string $kind, string $token, string $mode, string $value): array
    {
        $added = 0;
        $updated = 0;

        $docEntries = $kind === 'docs'
            ? [(int) $token]
            : $this->client->findOrderInvoiceDocEntriesHoldingReference((string) $token);

        foreach ($docEntries as $docEntry) {
            $result = $this->upsertInvoiceTarget((int) $docEntry, $mode, $value);
            if ($result === 'added') {
                $added++;
            } elseif ($result === 'updated') {
                $updated++;
            }
        }

        return ['added' => $added, 'updated' => $updated];
    }

    /**
     * Fetch a fast snapshot (no related docs) for one invoice and upsert its row.
     */
    private function upsertInvoiceTarget(int $docEntry, string $mode, string $value): ?string
    {
        if ($docEntry <= 0) {
            return null;
        }

        $rows = $this->client->previewInvoicesForCleanup([$docEntry], false);
        $row = $rows[0] ?? null;
        if ($row === null) {
            return null;
        }

        $target = SapCleanupTarget::query()->firstOrNew(['doc_entry' => $docEntry]);
        $isNew = !$target->exists;

        $target->fill([
            'doc_num' => $row['doc_num'] ?? null,
            'order_external_id' => $row['order'] ?? null,
            'card_code' => $row['card_code'] ?? null,
            'doc_total' => $row['doc_total'] ?? null,
            'sap_doc_status' => $this->deriveDocStatus($row),
            'lines' => $row['lines'] ?? [],
            'last_checked_at' => now(),
            'source_mode' => $mode,
            'source_value' => $value,
        ]);

        if ($isNew) {
            $target->cleanup_state = !empty($row['already_reversed']) ? 'reversed' : 'new';
        } elseif (!empty($row['already_reversed']) && $target->cleanup_state === 'new') {
            $target->cleanup_state = 'reversed';
        }

        $target->save();

        return $isNew ? 'added' : 'updated';
    }

    /**
     * Queue a background SCAN (resolve + add targets). Heavy for a product with
     * many orders, so it runs on the queue instead of blocking the page.
     *
     * @return array{queued:bool,already_running:bool,event:\App\Models\SapSyncEvent}
     */
    public function dispatchScan(string $mode, string $value, ?string $triggeredBy = null): array
    {
        $active = $this->activeEvent();
        if ($active !== null) {
            return ['queued' => false, 'already_running' => true, 'event' => $active];
        }

        $event = SapSyncEvent::create([
            'event_key' => 'sap_item_cleanup_' . (string) Str::ulid(),
            'source_type' => self::SOURCE_TYPE,
            'sap_action' => 'item_cleanup_scan',
            'sap_status' => 'queued',
            'payload' => [
                'action' => 'scan',
                'mode' => $mode,
                'value' => trim($value),
                'requested_at' => now()->toDateTimeString(),
                'triggered_by' => $triggeredBy,
            ],
        ]);

        RunSapItemCleanup::dispatch($event->id);

        return ['queued' => true, 'already_running' => false, 'event' => $event];
    }

    /**
     * Execute a queued scan.
     *
     * @return array<string,mixed>
     */
    /**
     * Process ONE chunk of a scan and persist progress on the event. Returns
     * 'done'=false when more chunks remain (the job re-dispatches itself), so no
     * single run is long enough to trip the queue's retry_after.
     *
     * @return array<string,mixed>
     */
    public function runScan(SapSyncEvent $event): array
    {
        $chunkSize = 10;
        $payload = (array) ($event->payload ?? []);
        $mode = (string) ($payload['mode'] ?? '');
        $value = (string) ($payload['value'] ?? '');

        // Build the pending work-list once and persist it on the event.
        if (!array_key_exists('pending', $payload)) {
            [$kind, $pending] = $this->buildScanPending($mode, $value);
            $payload['pending'] = $pending;
            $payload['pending_kind'] = $kind;
            $payload['total'] = count($pending);
            $payload['added'] = 0;
            $payload['updated'] = 0;
            $event->update(['payload' => $payload]);
        }

        $kind = (string) ($payload['pending_kind'] ?? 'docs');
        $pending = array_values((array) ($payload['pending'] ?? []));
        $added = (int) ($payload['added'] ?? 0);
        $updated = (int) ($payload['updated'] ?? 0);

        $cancelled = false;
        $processed = 0;
        foreach ($pending as $token) {
            if ($processed >= $chunkSize) {
                break;
            }
            if ((string) (optional($event->fresh())->sap_status) === 'cancel_requested') {
                $cancelled = true;
                break;
            }
            $r = $this->processScanToken($kind, (string) $token, $mode, $value);
            $added += $r['added'];
            $updated += $r['updated'];
            $processed++;
        }

        $remaining = array_slice($pending, $processed);
        $payload['pending'] = $remaining;
        $payload['added'] = $added;
        $payload['updated'] = $updated;
        $event->update(['payload' => $payload]);

        return [
            'done' => $cancelled || $remaining === [],
            'cancelled' => $cancelled,
            'found' => (int) ($payload['total'] ?? 0),
            'added' => $added,
            'updated' => $updated,
            'remaining' => count($remaining),
        ];
    }

    /**
     * Re-read a target's invoice from SAP and refresh its stored snapshot.
     *
     * @return array<string,mixed>
     */
    public function checkTarget(SapCleanupTarget $target): array
    {
        $rows = $this->client->previewInvoicesForCleanup([(int) $target->doc_entry]);
        $row = $rows[0] ?? null;

        if ($row === null) {
            $target->last_action = 'check';
            $target->last_checked_at = now();
            $target->last_error = 'Invoice not found in SAP';
            $target->save();

            return ['ok' => false, 'reason' => 'Invoice not found in SAP'];
        }

        $target->fill([
            'doc_num' => $row['doc_num'] ?? $target->doc_num,
            'order_external_id' => $row['order'] ?? $target->order_external_id,
            'card_code' => $row['card_code'] ?? $target->card_code,
            'doc_total' => $row['doc_total'] ?? $target->doc_total,
            'sap_doc_status' => $this->deriveDocStatus($row),
            'lines' => $row['lines'] ?? $target->lines,
            'related' => $row['related'] ?? $target->related,
            'last_action' => 'check',
            'last_checked_at' => now(),
            'last_error' => null,
        ]);

        if (!empty($row['already_reversed']) && $target->cleanup_state === 'new') {
            $target->cleanup_state = 'reversed';
        }

        $target->save();

        return ['ok' => true, 'status' => $target->sap_doc_status];
    }

    /**
     * Reverse a target's invoice (cancel payment/delivery, credit memo, COGS
     * reversal, "-0reversed" stamping) and, when $requeue, reset the local order
     * to a re-queueable "pending" state.
     *
     * @return array<string,mixed>
     */
    public function cancelTarget(SapCleanupTarget $target, bool $requeue = true): array
    {
        try {
            $result = $this->client->cleanupReverseInvoice((int) $target->doc_entry);
        } catch (\Throwable $e) {
            $result = ['ok' => false, 'reason' => $e->getMessage()];
        }

        $target->last_action = 'cancel';
        $target->last_checked_at = now();
        if (!empty($result['order'])) {
            $target->order_external_id = (string) $result['order'];
        }

        if (!empty($result['ok'])) {
            $target->cleanup_state = 'reversed';
            $target->sap_doc_status = !empty($result['skipped']) ? 'already_reversed' : 'reversed';
            $target->last_error = !empty($result['skipped']) ? (string) ($result['reason'] ?? '') : null;

            if ($requeue && empty($result['skipped']) && !empty($result['order'])) {
                $this->requeueReversedOrder((string) $result['order']);
                $target->last_action = 'cancel+requeue';
            }
        } else {
            $target->cleanup_state = 'failed';
            $target->last_error = (string) ($result['reason'] ?? 'Cancel failed');
        }

        $target->save();

        return $result;
    }

    /**
     * Re-send the target's Omniful order to SAP (clean send via Force Resend).
     *
     * @return array<string,mixed>
     */
    public function resendTarget(SapCleanupTarget $target): array
    {
        $externalId = trim((string) $target->order_external_id);
        if ($externalId === '') {
            $target->last_action = 'resend';
            $target->cleanup_state = 'failed';
            $target->last_error = 'No Omniful order id on this target';
            $target->save();

            return ['ok' => false, 'reason' => 'No Omniful order id'];
        }

        $order = OmnifulOrder::query()->where('external_id', $externalId)->first();
        if ($order === null) {
            $target->last_action = 'resend';
            $target->cleanup_state = 'failed';
            $target->last_error = 'Omniful order not found locally';
            $target->save();

            return ['ok' => false, 'reason' => 'Omniful order not found locally'];
        }

        $result = app(WebhookRetryService::class)->forceResendOrder($order, false);

        $target->last_action = 'resend';
        $target->last_checked_at = now();
        if (!empty($result['ok'])) {
            $target->cleanup_state = 'resent';
            $target->last_error = null;
        } else {
            $target->cleanup_state = 'failed';
            $target->last_error = (string) ($result['message'] ?? 'Resend failed');
        }
        $target->save();

        return $result;
    }

    /**
     * Queue a background bulk run over a set of target ids.
     *
     * @param int[] $targetIds
     * @return array{queued:bool,already_running:bool,event:\App\Models\SapSyncEvent}
     */
    public function dispatchBulk(string $action, array $targetIds, bool $requeue = true, ?string $triggeredBy = null): array
    {
        $action = in_array($action, self::BULK_ACTIONS, true) ? $action : 'check';
        $targetIds = array_values(array_unique(array_map('intval', $targetIds)));

        $active = $this->activeEvent();
        if ($active !== null) {
            return ['queued' => false, 'already_running' => true, 'event' => $active];
        }

        $event = SapSyncEvent::create([
            'event_key' => 'sap_item_cleanup_' . (string) Str::ulid(),
            'source_type' => self::SOURCE_TYPE,
            'sap_action' => 'item_cleanup_' . $action,
            'sap_status' => 'queued',
            'payload' => [
                'action' => $action,
                'target_ids' => $targetIds,
                'requeue' => $requeue,
                'requested_at' => now()->toDateTimeString(),
                'triggered_by' => $triggeredBy,
            ],
        ]);

        RunSapItemCleanup::dispatch($event->id);

        return ['queued' => true, 'already_running' => false, 'event' => $event];
    }

    /**
     * Execute the queued bulk action. Honors a mid-run stop request.
     *
     * @return array<string,mixed>
     */
    public function runBulk(SapSyncEvent $event): array
    {
        $payload = (array) ($event->payload ?? []);
        $action = (string) ($payload['action'] ?? 'check');
        $requeue = (bool) ($payload['requeue'] ?? true);
        $targetIds = array_map('intval', (array) ($payload['target_ids'] ?? []));

        $targets = SapCleanupTarget::query()->whereIn('id', $targetIds)->get();

        $done = 0;
        $failed = 0;
        $requeued = 0;
        $results = [];

        foreach ($targets as $target) {
            if ((string) (optional($event->fresh())->sap_status) === 'cancel_requested') {
                return [
                    'cancelled' => true,
                    'action' => $action,
                    'total' => count($targetIds),
                    'done' => $done,
                    'failed' => $failed,
                    'requeued' => $requeued,
                    'results' => $results,
                ];
            }

            $res = match ($action) {
                'cancel' => $this->cancelTarget($target, $requeue),
                'resend' => $this->resendTarget($target),
                default => $this->checkTarget($target),
            };

            if (!empty($res['ok'])) {
                $done++;
                if ($action === 'cancel' && $requeue && empty($res['skipped']) && !empty($res['order'])) {
                    $requeued++;
                }
            } else {
                $failed++;
            }

            $results[] = [
                'target_id' => $target->id,
                'doc_num' => $target->doc_num,
                'order' => $target->order_external_id,
                'ok' => !empty($res['ok']),
                'reason' => $res['reason'] ?? null,
            ];
        }

        return [
            'cancelled' => false,
            'action' => $action,
            'total' => count($targetIds),
            'done' => $done,
            'failed' => $failed,
            'requeued' => $requeued,
            'results' => $results,
        ];
    }

    private function deriveDocStatus(array $row): string
    {
        if (!empty($row['already_reversed'])) {
            return 'already_reversed';
        }
        if (!empty($row['cancelled'])) {
            return 'cancelled';
        }

        return (string) ($row['status'] ?? '');
    }

    /**
     * Reset the local OmnifulOrder(s) back to a re-queueable "pending" state with
     * cleared SAP bindings, so the order returns to the In Queue list and can be
     * re-sent to SAP. The Omniful event + payload are kept for the resend.
     */
    private function requeueReversedOrder(string $externalId): int
    {
        $externalId = trim($externalId);
        if ($externalId === '') {
            return 0;
        }

        $orders = OmnifulOrder::query()->where('external_id', $externalId)->get();
        foreach ($orders as $order) {
            $order->fill([
                'sap_status' => 'pending',
                'sap_error' => null,
                'sap_doc_entry' => null,
                'sap_doc_num' => null,
                'sap_payment_status' => null,
                'sap_payment_doc_entry' => null,
                'sap_payment_doc_num' => null,
                'sap_payment_error' => null,
                'sap_delivery_status' => null,
                'sap_delivery_doc_entry' => null,
                'sap_delivery_doc_num' => null,
                'sap_delivery_error' => null,
                'sap_cogs_status' => null,
                'sap_cogs_journal_entry' => null,
                'sap_cogs_journal_num' => null,
                'sap_cogs_error' => null,
                'sap_credit_note_status' => null,
                'sap_credit_note_doc_entry' => null,
                'sap_credit_note_doc_num' => null,
                'sap_credit_note_error' => null,
                'sap_cancel_cogs_status' => null,
                'sap_cancel_cogs_journal_entry' => null,
                'sap_cancel_cogs_journal_num' => null,
                'sap_cancel_cogs_error' => null,
                'sap_card_fee_status' => null,
                'sap_card_fee_journal_entry' => null,
                'sap_card_fee_journal_num' => null,
                'sap_card_fee_error' => null,
            ]);
            $order->save();
        }

        return $orders->count();
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
}
