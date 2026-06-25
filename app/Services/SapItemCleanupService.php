<?php

namespace App\Services;

use App\Jobs\RunSapItemCleanup;
use App\Models\OmnifulOrder;
use App\Models\SapSyncEvent;
use Illuminate\Support\Str;

/**
 * Maintenance: reverse every AR Reserve Invoice tied to a wrongly auto-created
 * item (or a single SAP doc / Omniful order). For each target invoice it cancels
 * the incoming payment + delivery, posts an AR credit memo, marks the order refs
 * "<order>-0reversed", and posts a COGS reversal journal. See
 * SapServiceLayerClient::cleanupReverseInvoice().
 *
 * Optionally (default on) the matching local OmnifulOrder is reset to a
 * re-queueable state (sap_status='pending', SAP bindings cleared) so it returns
 * to the "In Queue Orders" list and can be re-sent to SAP at any time; a failed
 * re-send then lands in the order-errors page as usual.
 */
class SapItemCleanupService
{
    public const SOURCE_TYPE = 'sap_item_cleanup';

    /** @var array<int,string> */
    public const MODES = ['product_id', 'sap_doc_number', 'omniful_order_id'];

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
            'product_id' => $this->client->findInvoiceDocEntriesByItemCode($value),
            'sap_doc_number' => $this->client->findInvoiceDocEntriesByDocNum($value),
            'omniful_order_id' => $this->client->findOrderInvoiceDocEntriesHoldingReference($value),
            default => [],
        };
    }

    /**
     * Read-only preview: the invoices that WOULD be reversed.
     *
     * @return array<int,array<string,mixed>>
     */
    public function preview(string $mode, string $value): array
    {
        return $this->client->previewInvoicesForCleanup($this->resolveDocEntries($mode, $value));
    }

    /**
     * Queue a background cleanup run.
     *
     * @return array{queued:bool,already_running:bool,event:\App\Models\SapSyncEvent}
     */
    public function dispatch(string $mode, string $value, bool $requeue = true, ?string $triggeredBy = null): array
    {
        $active = $this->activeEvent();
        if ($active !== null) {
            return ['queued' => false, 'already_running' => true, 'event' => $active];
        }

        $event = SapSyncEvent::create([
            'event_key' => 'sap_item_cleanup_' . (string) Str::ulid(),
            'source_type' => self::SOURCE_TYPE,
            'sap_action' => 'item_cleanup',
            'sap_status' => 'queued',
            'payload' => [
                'mode' => $mode,
                'value' => trim($value),
                'requeue' => $requeue,
                'requested_at' => now()->toDateTimeString(),
                'triggered_by' => $triggeredBy,
            ],
        ]);

        RunSapItemCleanup::dispatch($event->id);

        return ['queued' => true, 'already_running' => false, 'event' => $event];
    }

    /**
     * Execute the cleanup for a queued event. Honors a mid-run stop request.
     *
     * @return array<string,mixed>
     */
    public function run(SapSyncEvent $event): array
    {
        $payload = (array) ($event->payload ?? []);
        $mode = (string) ($payload['mode'] ?? '');
        $value = (string) ($payload['value'] ?? '');
        $requeue = (bool) ($payload['requeue'] ?? true);

        $docEntries = $this->resolveDocEntries($mode, $value);

        $results = [];
        $reversed = 0;
        $skipped = 0;
        $failed = 0;
        $requeued = 0;

        foreach ($docEntries as $docEntry) {
            if ((string) (optional($event->fresh())->sap_status) === 'cancel_requested') {
                return [
                    'cancelled' => true,
                    'mode' => $mode,
                    'value' => $value,
                    'requeue' => $requeue,
                    'found' => count($docEntries),
                    'reversed' => $reversed,
                    'skipped' => $skipped,
                    'failed' => $failed,
                    'requeued' => $requeued,
                    'results' => $results,
                ];
            }

            try {
                $result = $this->client->cleanupReverseInvoice((int) $docEntry);
            } catch (\Throwable $e) {
                $result = ['ok' => false, 'doc_entry' => (int) $docEntry, 'reason' => $e->getMessage()];
            }

            if (!empty($result['skipped'])) {
                $skipped++;
            } elseif (!empty($result['ok'])) {
                $reversed++;
                if ($requeue && !empty($result['order'])) {
                    $requeued += $this->requeueReversedOrder((string) $result['order']);
                }
            } else {
                $failed++;
            }

            $results[] = $result;
        }

        return [
            'cancelled' => false,
            'mode' => $mode,
            'value' => $value,
            'requeue' => $requeue,
            'found' => count($docEntries),
            'reversed' => $reversed,
            'skipped' => $skipped,
            'failed' => $failed,
            'requeued' => $requeued,
            'results' => $results,
        ];
    }

    /**
     * Reset the local OmnifulOrder(s) for a reversed order back to a re-queueable
     * state: clear the SAP document bindings and set sap_status='pending' so the
     * order reappears in the "In Queue Orders" list and can be re-sent to SAP via
     * Force Resend. The original Omniful event + payload are kept intact so the
     * resend has everything it needs; a failed resend lands in the errors page.
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
