<?php

namespace App\Services\Webhooks;

use App\Exceptions\SapRequestException;
use App\Models\IntegrationSetting;
use App\Models\OmnifulOrder;
use App\Models\OmnifulOrderEvent;
use App\Services\SapServiceLayerClient;
use Illuminate\Support\Facades\Log;

class OrderWebhookService
{
    /**
     * @return array{queue:bool,action:string,reason:?string,event_name:string,status:string}
     */
    public function classifyEventForProcessing(OmnifulOrderEvent $event): array
    {
        $payload = (array) ($event->payload ?? []);
        $data = (array) data_get($payload, 'data', []);
        $eventName = (string) data_get($payload, 'event_name', '');
        $primaryStatus = $this->extractStatusValue($data, [
            'status_code',
            'status',
            'order_status',
            'order.status',
        ]);
        $deliveryStatus = $this->extractStatusValue($data, [
            'status_code',
            'status',
            'order_status',
            'shipment.delivery_status',
            'shipment.status',
            'shipment.shipping_partner_status',
            'delivery_status',
            'shipment_status',
        ]);
        $creditStatus = $this->extractStatusValue($data, [
            'cancel_status',
            'cancellation_status',
            'status_code',
            'status',
            'order_status',
            'shipment.delivery_status',
            'shipment.status',
            'shipment.shipping_partner_status',
        ]);
        $paymentSignals = $this->extractPaymentSignals($data);

        $mapper = app(WebhookStatusMapper::class);
        $invoiceEligibility = $mapper->resolveOrderInvoiceEligibility($eventName, $primaryStatus, $paymentSignals);
        $deliveryEligibility = $mapper->resolveOrderDeliveryEligibility($eventName, $deliveryStatus);
        $creditEligibility = $mapper->resolveOrderCreditEligibility($eventName, $creditStatus);
        $effectiveStatus = $primaryStatus !== '' ? $primaryStatus : $deliveryStatus;

        if ($this->isNoOpOrderStatus($effectiveStatus)) {
            return [
                'queue' => false,
                'action' => 'ignored',
                'reason' => 'Ignored: no SAP action required for order status ' . $effectiveStatus,
                'event_name' => $eventName,
                'status' => $effectiveStatus,
            ];
        }

        if (
            ($invoiceEligibility['eligible'] ?? false)
            || ($deliveryEligibility['eligible'] ?? false)
            || ($creditEligibility['eligible'] ?? false)
        ) {
            return [
                'queue' => true,
                'action' => 'sap',
                'reason' => null,
                'event_name' => $eventName,
                'status' => $effectiveStatus,
            ];
        }

        $order = OmnifulOrder::where('external_id', (string) ($event->external_id ?? ''))->first();
        if ($order && !empty($order->sap_doc_entry)) {
            return [
                'queue' => false,
                'action' => 'metadata_sync',
                'reason' => null,
                'event_name' => $eventName,
                'status' => $effectiveStatus,
            ];
        }

        return [
            'queue' => false,
            'action' => 'ignored',
            'reason' => (string) (
                $invoiceEligibility['reason']
                ?? $deliveryEligibility['reason']
                ?? 'Ignored: order is not eligible for SAP action'
            ),
            'event_name' => $eventName,
            'status' => $effectiveStatus,
        ];
    }

    /**
     * @return array{action:string,message:string}
     */
    public function applyNoOpEventOutcome(OmnifulOrderEvent $event): array
    {
        $classification = $this->classifyEventForProcessing($event);
        $order = OmnifulOrder::where('external_id', (string) ($event->external_id ?? ''))->first();
        if (!$order) {
            return [
                'action' => $classification['action'],
                'message' => (string) ($classification['reason'] ?? 'No order found'),
            ];
        }

        if ($classification['action'] === 'metadata_sync') {
            $this->syncSalesOrderMetadata($order, $classification['event_name'], $classification['status']);

            if (in_array((string) ($order->sap_status ?? ''), ['pending', 'running', 'retrying'], true) && !empty($order->sap_doc_entry)) {
                $order->sap_status = 'created';
                $order->sap_error = null;
                $order->save();
            }

            return [
                'action' => 'metadata_sync',
                'message' => 'Order event did not require SAP action; metadata synced only',
            ];
        }

        $order->sap_status = 'ignored';
        $order->sap_error = (string) ($classification['reason'] ?? 'Ignored: order is not eligible for SAP action');
        $order->save();

        return [
            'action' => 'ignored',
            'message' => (string) ($classification['reason'] ?? 'Ignored: order is not eligible for SAP action'),
        ];
    }

    /**
     * @param bool $force When true (manual "Resend Order" action), re-run the
     *   full SAP flow even if the order was already processed: re-verify the AR
     *   invoice against SAP and re-bind it (completing any missing payment /
     *   delivery / COGS), or recreate it only if it no longer exists in SAP.
     *   Never creates a duplicate invoice.
     */
    public function process(OmnifulOrderEvent $event, bool $force = false, bool $cancelOld = false): void
    {
        $externalId = (string) ($event->external_id ?? '');
        if ($externalId === '') {
            return;
        }

        $order = OmnifulOrder::where('external_id', $externalId)->first();
        if (!$order) {
            return;
        }

        $payload = (array) ($event->payload ?? []);
        $data = (array) data_get($payload, 'data', []);
        $eventName = (string) data_get($payload, 'event_name', '');

        // Only push numeric order ids to SAP. Non-numeric ids (e.g. STO_...,
        // RS_234 — stock transfers / internal) are ignored.
        $orderId = trim((string) (
            data_get($data, 'order_id')
            ?? data_get($data, 'order_alias')
            ?? $externalId
        ));
        if ($this->numericOrderIdOnly() && $orderId !== '' && !ctype_digit($orderId)) {
            $order->sap_status = 'ignored';
            $order->sap_error = 'Ignored: non-numeric order id (' . $orderId . ') excluded from SAP sync';
            $order->save();
            return;
        }

        $primaryStatus = $this->extractStatusValue($data, [
            'status_code',
            'status',
            'order_status',
            'order.status',
        ]);
        $deliveryStatus = $this->extractStatusValue($data, [
            'status_code',
            'status',
            'order_status',
            'shipment.delivery_status',
            'shipment.status',
            'shipment.shipping_partner_status',
            'delivery_status',
            'shipment_status',
        ]);
        $creditStatus = $this->extractStatusValue($data, [
            'cancel_status',
            'cancellation_status',
            'status_code',
            'status',
            'order_status',
            'shipment.delivery_status',
            'shipment.status',
            'shipment.shipping_partner_status',
        ]);
        $paymentSignals = $this->extractPaymentSignals($data);

        $mapper = app(WebhookStatusMapper::class);
        $invoiceEligibility = $mapper->resolveOrderInvoiceEligibility($eventName, $primaryStatus, $paymentSignals);
        $deliveryEligibility = $mapper->resolveOrderDeliveryEligibility($eventName, $deliveryStatus);
        $creditEligibility = $mapper->resolveOrderCreditEligibility($eventName, $creditStatus);

        // A manual force-resend always proceeds to (re)ensure the AR invoice and
        // its follow-ups, regardless of the dispatched event's eligibility.
        if (
            !$force
            && !($invoiceEligibility['eligible'] ?? false)
            && !($deliveryEligibility['eligible'] ?? false)
            && !($creditEligibility['eligible'] ?? false)
        ) {
            if (!empty($order->sap_doc_entry)) {
                $this->syncSalesOrderMetadata(
                    $order,
                    $eventName,
                    $primaryStatus !== '' ? $primaryStatus : $deliveryStatus
                );
                return;
            }

            $order->sap_status = 'ignored';
            $order->sap_error = (string) (
                $invoiceEligibility['reason']
                ?? $deliveryEligibility['reason']
                ?? 'Ignored: order is not eligible for SAP action'
            );
            $order->save();
            return;
        }

        $client = app(SapServiceLayerClient::class);
        $invoiceResult = null;
        // On a normal webhook we only touch the invoice when it is eligible and
        // not yet created locally. A force-resend always re-checks SAP.
        $shouldEnsureInvoice = $force
            || (($invoiceEligibility['eligible'] ?? false) && empty($order->sap_doc_entry));
        if ($shouldEnsureInvoice) {
            // Idempotency recovery: an earlier worker may have created the AR reserve invoice
            // in SAP but failed to persist the resulting DocEntry/DocNum locally (worker crash,
            // queue restart, or local truncate). Before issuing a new POST, ask SAP directly
            // via the order UDFs (U_omo / U_ZidId / U_SallaOrderId / NumAtCard / Comments).
            $recoveredInvoice = $client->findExistingArReserveInvoiceForOmnifulOrderReference($data, $externalId);

            // FORCE RESEND with "cancel old" — refresh the invoice when it carries
            // stale data (e.g. a wrong exchange rate). SAP cannot edit a posted
            // invoice, so we REVERSE the old one the same way the SAP team does
            // manually — a base-referenced AR credit memo to reverse the
            // financials, plus renaming its order UDFs to "<id>-reversed" (with a
            // -1/-2 suffix on collision) so the idempotency lookup no longer
            // matches it — then create a fresh invoice with current data.
            // (No SL Cancel: that produced closed cancellation documents that the
            // payment then hit with -10 "Invoice is already closed or blocked".)
            //
            // Gated by the "Cancel & reverse existing invoice" checkbox ($cancelOld).
            // SAFETY: only when the invoice has NO successful dependent document
            // (payment / delivery / COGS); otherwise we leave it and just rebind.
            if ($force && $cancelOld && is_array($recoveredInvoice) && !empty($recoveredInvoice['DocEntry'])) {
                $hasDependents = !empty($order->sap_payment_doc_entry)
                    || !empty($order->sap_delivery_doc_entry)
                    || !empty($order->sap_cogs_journal_entry);

                if ($hasDependents) {
                    \Illuminate\Support\Facades\Log::warning('Resend: invoice reversal skipped (has dependents); rebinding only', [
                        'order' => $externalId,
                        'doc_entry' => $recoveredInvoice['DocEntry'],
                        'has_payment' => !empty($order->sap_payment_doc_entry),
                        'has_delivery' => !empty($order->sap_delivery_doc_entry),
                        'has_cogs' => !empty($order->sap_cogs_journal_entry),
                    ]);
                } else {
                    $reversal = null;
                    try {
                        $reversal = $client->reverseArReserveInvoiceForResend(
                            (int) $recoveredInvoice['DocEntry'],
                            $externalId,
                        );
                    } catch (\Throwable $e) {
                        $order->sap_status = 'failed';
                        $order->sap_error = 'Resend: failed to reverse existing AR invoice DocEntry '
                            . (string) $recoveredInvoice['DocEntry'] . ' — ' . $e->getMessage();
                        $order->save();

                        return;
                    }

                    if (($reversal['ok'] ?? false) === true) {
                        // Old invoice reversed + renamed. Clear local refs so the
                        // create path below issues a fresh invoice + follow-ups.
                        $order->forceFill([
                            'sap_doc_entry' => null,
                            'sap_doc_num' => null,
                            'sap_payment_status' => null,
                            'sap_payment_doc_entry' => null,
                            'sap_payment_doc_num' => null,
                            'sap_delivery_status' => null,
                            'sap_delivery_doc_entry' => null,
                            'sap_delivery_doc_num' => null,
                            'sap_cogs_status' => null,
                            'sap_cogs_journal_entry' => null,
                            'sap_cogs_journal_num' => null,
                        ])->save();

                        \Illuminate\Support\Facades\Log::info('Resend: AR invoice reversed for recreation', [
                            'order' => $externalId,
                            'old_doc_entry' => $recoveredInvoice['DocEntry'],
                            'credit_memo' => $reversal['credit_memo']['DocEntry'] ?? null,
                            'new_ref' => $reversal['new_ref'] ?? null,
                        ]);

                        // Treat as if no invoice exists so the create path runs.
                        $recoveredInvoice = null;
                    } else {
                        $order->sap_status = 'failed';
                        $order->sap_error = 'Resend: invoice reversal not completed — '
                            . (string) ($reversal['reason'] ?? 'unknown');
                        $order->save();

                        return;
                    }
                }
            }

            if (is_array($recoveredInvoice) && !empty($recoveredInvoice['DocEntry'])) {
                if (!$force) {
                    // The AR Reserve Invoice already exists in SAP for this order.
                    // Per business decision: do NOT re-bind it and continue the
                    // remaining steps — just ignore this order (already created).
                    $order->sap_status = 'ignored';
                    $order->sap_error = 'Ignored: AR reserve invoice already exists in SAP (DocNum '
                        . (string) ($recoveredInvoice['DocNum'] ?? '?') . ')';
                    $recoveredInvoice['ignored'] = true;
                    $recoveredInvoice['reused_existing'] = true;
                    $recoveredInvoice['recovered_before_post'] = true;
                    $order->sap_order_response = $recoveredInvoice;
                    $order->save();

                    return;
                }

                // FORCE RESEND: the invoice still exists in SAP — re-bind it and
                // fall through so any MISSING follow-up steps (payment / delivery
                // / COGS) get completed. No duplicate invoice is created.
                $order->sap_status = 'created';
                $order->sap_doc_entry = (string) ($recoveredInvoice['DocEntry'] ?? '');
                $order->sap_doc_num = (string) ($recoveredInvoice['DocNum'] ?? '');
                $order->sap_error = null;
                $recoveredInvoice['ignored'] = false;
                $recoveredInvoice['reused_existing'] = true;
                $order->sap_order_response = $recoveredInvoice;
                $order->save();
                $invoiceResult = $recoveredInvoice;
            } else {
                // FORCE RESEND with a stale local invoice reference: the AR invoice
                // is no longer in SAP (deleted). Clear the now-orphaned follow-up
                // references so the fresh invoice gets its own payment / delivery /
                // COGS — there is nothing left in SAP to duplicate.
                if ($force && !empty($order->sap_doc_entry)) {
                    $order->forceFill([
                        'sap_doc_entry' => null,
                        'sap_doc_num' => null,
                        'sap_payment_status' => null,
                        'sap_payment_doc_entry' => null,
                        'sap_payment_doc_num' => null,
                        'sap_delivery_status' => null,
                        'sap_delivery_doc_entry' => null,
                        'sap_delivery_doc_num' => null,
                        'sap_cogs_status' => null,
                        'sap_cogs_journal_entry' => null,
                        'sap_cogs_journal_num' => null,
                    ])->save();
                }
                try {
                    $invoiceResult = $client->createArReserveInvoiceFromOmnifulOrder($data, $externalId);
                } catch (SapRequestException $e) {
                    // Unified duplicate-error handling: rebind on ownership match,
                    // mark as blocked (not failed) when the conflicting DocNum
                    // belongs to a foreign or orphan invoice — preserves the order
                    // for retry once SAP series counter is corrected.
                    $rescued = $this->handleFollowUpInvoiceCreateException(
                        $order,
                        $client,
                        $e,
                        $data,
                        $externalId,
                    );

                    if (is_array($rescued) && !empty($rescued['DocEntry'])) {
                        $invoiceResult = $rescued;
                    } else {
                        return;
                    }
                }
                if (($invoiceResult['ignored'] ?? false) === true) {
                    $order->sap_status = 'ignored';
                    $order->sap_error = (string) ($invoiceResult['reason'] ?? 'Ignored: no order lines found');
                    $order->sap_order_response = $invoiceResult;
                    $order->save();
                    return;
                }

                $order->sap_status = 'created';
                $order->sap_doc_entry = (string) ($invoiceResult['DocEntry'] ?? '');
                $order->sap_doc_num = (string) ($invoiceResult['DocNum'] ?? '');
                $order->sap_error = null;
                $order->sap_order_response = $invoiceResult;
                $order->save();
            }
        }

        if (
            empty($order->sap_doc_entry)
            && (
                ($deliveryEligibility['eligible'] ?? false)
                || ($creditEligibility['eligible'] ?? false)
            )
        ) {
            $invoiceResult = $this->ensureSapOrderExistsForFollowUpAction($order, $data, $externalId);
        }

        if (($invoiceEligibility['eligible'] ?? false) || !empty($order->sap_doc_entry)) {
            $this->createIncomingPaymentIfEligible($order, $data, $invoiceResult);
            $this->createCardFeeJournalIfEligible($order, $data);
            // COGS is posted at the invoice/payment stage (client's "2 JEs"),
            // reading item cost from the Item Master — it does NOT wait for
            // shipment. SAP does not auto-post COGS on the delivery here, so
            // there is no double-posting risk.
            $this->createCogsJournalAtInvoiceIfEligible($order, $data);
        }

        if (($deliveryEligibility['eligible'] ?? false)) {
            $this->createDeliveryIfEligible($order, $data);
        }

        if (($creditEligibility['eligible'] ?? false)) {
            $this->createCreditNoteIfEligible($order, $data);
        }
        $this->refreshOverallSapStatus($order);
        $this->syncSalesOrderMetadata($order, $eventName, $primaryStatus !== '' ? $primaryStatus : $deliveryStatus);
    }

    private function ensureSapOrderExistsForFollowUpAction(OmnifulOrder $order, array $data, string $externalId): ?array
    {
        if (!empty($order->sap_doc_entry)) {
            return null;
        }

        $client = app(SapServiceLayerClient::class);

        // 1) Try to rebind an existing AR reserve invoice already in SAP.
        $existingInvoice = $client->findExistingArReserveInvoiceForOmnifulOrderReference($data, $externalId);
        if (is_array($existingInvoice) && !empty($existingInvoice['DocEntry'])) {
            $order->sap_status = 'created';
            $order->sap_doc_entry = (string) ($existingInvoice['DocEntry'] ?? '');
            $order->sap_doc_num = (string) ($existingInvoice['DocNum'] ?? '');
            $order->sap_error = null;
            $existingInvoice['ignored'] = false;
            $existingInvoice['reused_existing'] = true;
            $order->sap_order_response = $existingInvoice;
            $order->save();

            return $existingInvoice;
        }

        // 2) Fallback: create the AR reserve invoice on-the-fly from the follow-up payload.
        //
        // The shipment/delivery webhook payload carries the full order context
        // (items, prices, customer, hub) — equivalent to what order.new/order.create
        // would have provided. Creating the invoice here recovers orders whose
        // initial create event never arrived (integration started after the order,
        // earlier event failed, replayed shipped event, etc.), turning a hard
        // "blocked" state into a successful sale + delivery flow.
        //
        // Duplicate protection is preserved by the SDK-side recovery logic:
        // createArReserveInvoiceFromOmnifulOrder runs findExistingArReserveInvoiceForOmnifulOrder
        // before POST and on "already exists" errors, with UDF ownership validation
        // (U_omo / U_ZidId / U_SallaOrderId / NumAtCard / Comments).
        try {
            $invoiceResult = $client->createArReserveInvoiceFromOmnifulOrder($data, $externalId);
        } catch (SapRequestException $e) {
            return $this->handleFollowUpInvoiceCreateException($order, $client, $e, $data, $externalId);
        }

        if (($invoiceResult['ignored'] ?? false) === true) {
            $order->sap_status = 'ignored';
            $order->sap_error = (string) ($invoiceResult['reason'] ?? 'Ignored: no order lines found');
            $invoiceResult['created_during_follow_up'] = true;
            $order->sap_order_response = $invoiceResult;
            $order->save();

            return null;
        }

        $order->sap_status = 'created';
        $order->sap_doc_entry = (string) ($invoiceResult['DocEntry'] ?? '');
        $order->sap_doc_num = (string) ($invoiceResult['DocNum'] ?? '');
        $order->sap_error = null;
        $invoiceResult['ignored'] = false;
        $invoiceResult['created_during_follow_up'] = true;
        $order->sap_order_response = $invoiceResult;
        $order->save();

        return $invoiceResult;
    }

    /**
     * Map a SapRequestException raised by a lazy/follow-up AR reserve invoice
     * create attempt to the appropriate local order state. Distinguishes:
     *
     * - Owned recovery (invoice exists with matching UDFs/Comments): rebind locally.
     * - Foreign duplicate (invoice exists but belongs to another order): mark the
     *   order as `blocked` with a SAP series-counter advisory message — keeps the
     *   order recoverable once the SAP admin advances the AR Invoice numbering
     *   series Next No. past MAX(DocNum).
     * - Orphan duplicate (invoice exists with no ownership markers): mark the
     *   order as `blocked` for manual review rather than silently adopting an
     *   ambiguous SAP record.
     * - True failure (no candidate invoice): mark the order as `failed`.
     */
    private function handleFollowUpInvoiceCreateException(
        OmnifulOrder $order,
        $client,
        SapRequestException $e,
        array $data,
        string $externalId,
    ): ?array {
        $inspection = null;
        if ($e->responseBody !== '' && method_exists($client, 'inspectArReserveInvoiceDuplicate')) {
            try {
                $inspection = $client->inspectArReserveInvoiceDuplicate(
                    (string) $e->responseBody,
                    $data,
                    $externalId,
                );
            } catch (\Throwable $inspectError) {
                $inspection = null;
            }
        }

        $ownership = (string) ($inspection['ownership'] ?? 'none');
        $candidate = is_array($inspection['invoice'] ?? null) ? $inspection['invoice'] : null;

        if ($ownership === 'match' && is_array($candidate) && !empty($candidate['DocEntry'])) {
            $order->sap_status = 'created';
            $order->sap_doc_entry = (string) ($candidate['DocEntry'] ?? '');
            $order->sap_doc_num = (string) ($candidate['DocNum'] ?? '');
            $order->sap_error = null;
            $candidate['ignored'] = false;
            $candidate['reused_existing'] = true;
            $candidate['recovered_after_duplicate_error'] = true;
            $order->sap_order_response = $candidate;
            $order->save();

            return $candidate;
        }

        if ($ownership === 'foreign' && is_array($candidate)) {
            $order->sap_status = 'blocked';
            $order->sap_error = sprintf(
                'SAP AR Invoice numbering series conflict: conflicting DocNum %s already exists in SAP and belongs to a different order (UDF/Comments do not match Omniful order %s). Ask SAP admin to advance the AR Invoice series "Next No." past MAX(DocNum) in OINV, then retry.',
                (string) ($candidate['DocNum'] ?? '?'),
                $externalId,
            );
            $order->sap_order_response = [
                'ignored' => false,
                'request_body' => $e->requestBody,
                'error_response_body' => $e->responseBody,
                'status_code' => $e->statusCode,
                'created_during_follow_up' => true,
                'duplicate_inspection' => $inspection,
            ];
            $order->save();

            return null;
        }

        if ($ownership === 'orphan' && is_array($candidate)) {
            $order->sap_status = 'blocked';
            $order->sap_error = sprintf(
                'SAP returned a duplicate DocNum %s but the conflicting invoice carries no UDF/Comments to confirm ownership. Review in SAP and either set U_omo=%s or update the AR Invoice series "Next No." then retry.',
                (string) ($candidate['DocNum'] ?? '?'),
                $externalId,
            );
            $order->sap_order_response = [
                'ignored' => false,
                'request_body' => $e->requestBody,
                'error_response_body' => $e->responseBody,
                'status_code' => $e->statusCode,
                'created_during_follow_up' => true,
                'duplicate_inspection' => $inspection,
            ];
            $order->save();

            return null;
        }

        $order->sap_status = 'failed';
        $order->sap_error = 'AR reserve invoice creation failed during follow-up event: ' . $e->getMessage();
        $order->sap_order_response = [
            'ignored' => false,
            'request_body' => $e->requestBody,
            'error_response_body' => $e->responseBody,
            'status_code' => $e->statusCode,
            'created_during_follow_up' => true,
            'duplicate_inspection' => $inspection,
        ];
        $order->save();

        return null;
    }

    private function refreshOverallSapStatus(OmnifulOrder $order): void
    {
        $hasSapDocument = trim((string) ($order->sap_doc_entry ?? '')) !== ''
            || trim((string) ($order->sap_doc_num ?? '')) !== '';
        $hasFollowUpDocument = trim((string) ($order->sap_delivery_doc_entry ?? '')) !== ''
            || trim((string) ($order->sap_payment_doc_entry ?? '')) !== ''
            || trim((string) ($order->sap_credit_note_doc_entry ?? '')) !== ''
            || trim((string) ($order->sap_cogs_journal_entry ?? '')) !== ''
            || trim((string) ($order->sap_cancel_cogs_journal_entry ?? '')) !== '';

        if (!$hasSapDocument && !$hasFollowUpDocument) {
            return;
        }

        $current = trim((string) ($order->sap_status ?? ''));
        if (in_array($current, ['', 'ignored', 'pending', 'retrying', 'failed', 'running'], true)) {
            $order->sap_status = 'created';
            $order->sap_error = null;
            $order->save();
        }
    }

    /**
     * @return array<int,string>
     */
    private function extractPaymentSignals(array $data): array
    {
        return array_values(array_filter([
            (string) (data_get($data, 'payment_method') ?? ''),
            (string) (data_get($data, 'payment_type') ?? ''),
            (string) (data_get($data, 'payment_mode') ?? ''),
            (string) (data_get($data, 'payment.method') ?? ''),
            (string) (data_get($data, 'payment.status') ?? ''),
            (string) (data_get($data, 'invoice.payment_mode') ?? ''),
            (string) (data_get($data, 'invoice.payment_method') ?? ''),
            (string) (data_get($data, 'invoice.payment_type') ?? ''),
            (string) (data_get($data, 'invoice.payment_status') ?? ''),
            $this->extractCodSignal($data),
        ], fn ($v) => trim($v) !== ''));
    }

    /**
     * @param array<int,string> $paths
     */
    private function extractStatusValue(array $data, array $paths): string
    {
        foreach ($paths as $path) {
            $value = data_get($data, $path);
            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        return '';
    }

    private function extractCodSignal(array $data): string
    {
        $value = data_get($data, 'is_cash_on_delivery');

        if (is_bool($value)) {
            return $value ? 'cod' : '';
        }

        if (is_numeric($value)) {
            return ((int) $value) === 1 ? 'cod' : '';
        }

        if (is_string($value)) {
            $normalized = strtolower(trim($value));
            return in_array($normalized, ['1', 'true', 'yes', 'y'], true) ? 'cod' : '';
        }

        return '';
    }

    private function isNoOpOrderStatus(string $status): bool
    {
        // on_hold orders are ignored entirely (no SAP action) until they move
        // to an active status.
        return in_array($this->normalizeStatusValue($status), ['picked', 'packed', 'on_hold', 'on hold', 'onhold'], true);
    }

    private function normalizeStatusValue(string $value): string
    {
        return strtolower(trim($value));
    }

    private function createIncomingPaymentIfEligible(OmnifulOrder $order, array $data, ?array $invoiceResult): void
    {
        if (!$this->isIncomingPaymentEnabled()) {
            if ((string) ($order->sap_payment_doc_entry ?? '') === '') {
                $order->sap_payment_status = 'ignored';
                $order->sap_payment_error = 'Incoming payments disabled from integration settings';
                $order->sap_payment_response = [
                    'ignored' => true,
                    'reason' => 'Incoming payments disabled from integration settings',
                ];
                $order->save();
            }

            return;
        }

        if ($this->wasReserveInvoiceDowngradedToSalesOrder($order, $invoiceResult)) {
            $order->sap_payment_status = 'ignored';
            $order->sap_payment_error = 'Incoming payment skipped: SAP created a Sales Order fallback instead of an A/R Reserve Invoice';
            $order->sap_payment_response = [
                'ignored' => true,
                'reason' => 'Incoming payment skipped: SAP created a Sales Order fallback instead of an A/R Reserve Invoice',
            ];
            $order->save();

            return;
        }

        if (!empty($order->sap_payment_doc_entry)) {
            if ((string) $order->sap_payment_status === '') {
                $order->sap_payment_status = 'created';
                $order->save();
            }
            return;
        }

        $invoiceDocEntry = (int) ($order->sap_doc_entry ?? 0);
        if ($invoiceDocEntry <= 0) {
            return;
        }

        $client = app(SapServiceLayerClient::class);
        try {
            $paymentMethod = $this->resolvePaymentMethod($data);
            $result = $client->createIncomingPaymentForInvoice([
                'invoice_doc_entry' => $invoiceDocEntry,
                'card_code' => data_get($invoiceResult ?? [], 'CardCode') ?? data_get($data, 'customer.code'),
                'sum_applied' => $this->resolveIncomingPaymentAmount($data, $invoiceResult),
                'transfer_date' => data_get($data, 'order_created_at') ?? data_get($data, 'created_at'),
                'reference' => (string) ($order->external_id ?? ''),
                'payment_method' => $paymentMethod,
                'transfer_account' => $this->resolveIncomingPaymentTransferAccount($paymentMethod),
                'invoice_type_candidates' => $this->resolveIncomingPaymentInvoiceTypeCandidates(),
                'external_id' => (string) ($order->external_id ?? ''),
                'order_id' => data_get($data, 'order_id'),
                'order_alias' => data_get($data, 'order_alias'),
                'sales_channel' => data_get($data, 'sales_channel'),
                'source' => data_get($data, 'source'),
                'source_name' => data_get($data, 'source_name'),
                'channel' => data_get($data, 'channel'),
                'channel_name' => data_get($data, 'channel_name'),
                'store_name' => data_get($data, 'store_name'),
            ]);
        } catch (SapRequestException $e) {
            // Classify "Invoice is already closed or blocked" and friends as a
            // recoverable duplicate-payment scenario: rebind if a matching
            // payment is found in SAP, block (not fail) if it belongs to a
            // different order, fail only when nothing can be located.
            $inspection = null;
            if ($e->responseBody !== '' && method_exists($client, 'inspectIncomingPaymentDuplicate')) {
                try {
                    $inspection = $client->inspectIncomingPaymentDuplicate(
                        (string) $e->responseBody,
                        (int) $invoiceDocEntry,
                        $data,
                        (string) ($order->external_id ?? ''),
                    );
                } catch (\Throwable $inspectError) {
                    $inspection = null;
                }
            }

            $ownership = (string) ($inspection['ownership'] ?? 'none');
            $candidatePayment = is_array($inspection['payment'] ?? null) ? $inspection['payment'] : null;

            if ($ownership === 'match' && is_array($candidatePayment) && !empty($candidatePayment['DocEntry'])) {
                $candidatePayment['ignored'] = false;
                $candidatePayment['reused_existing'] = true;
                $candidatePayment['recovered_after_duplicate_error'] = true;
                $result = $candidatePayment;
            } elseif ($ownership === 'foreign' && is_array($candidatePayment)) {
                $order->sap_payment_status = 'blocked';
                $order->sap_payment_error = sprintf(
                    'SAP incoming payment conflict: payment DocEntry %s on invoice %s belongs to a different order. Manual review required in SAP.',
                    (string) ($candidatePayment['DocEntry'] ?? '?'),
                    (string) $invoiceDocEntry,
                );
                $order->sap_payment_response = [
                    'ignored' => false,
                    'request_body' => $e->requestBody,
                    'error_response_body' => $e->responseBody,
                    'status_code' => $e->statusCode,
                    'duplicate_inspection' => $inspection,
                ];
                $order->save();
                return;
            } elseif ($ownership === 'orphan' && is_array($candidatePayment)) {
                $order->sap_payment_status = 'blocked';
                $order->sap_payment_error = sprintf(
                    'SAP invoice %s is already closed by payment DocEntry %s but that payment has no UDF/Remarks ownership markers. Review in SAP and set U_omo=%s if it belongs to this order, then retry.',
                    (string) $invoiceDocEntry,
                    (string) ($candidatePayment['DocEntry'] ?? '?'),
                    (string) ($order->external_id ?? ''),
                );
                $order->sap_payment_response = [
                    'ignored' => false,
                    'request_body' => $e->requestBody,
                    'error_response_body' => $e->responseBody,
                    'status_code' => $e->statusCode,
                    'duplicate_inspection' => $inspection,
                ];
                $order->save();
                return;
            } else {
                $order->sap_payment_status = 'failed';
                $order->sap_payment_error = $e->getMessage();
                $order->sap_payment_response = [
                    'ignored' => false,
                    'request_body' => $e->requestBody,
                    'error_response_body' => $e->responseBody,
                    'status_code' => $e->statusCode,
                    'duplicate_inspection' => $inspection,
                ];
                $order->save();
                throw $e;
            }
        }

        if (($result['ignored'] ?? false) === true) {
            $order->sap_payment_status = 'ignored';
            $order->sap_payment_error = (string) ($result['reason'] ?? 'Incoming payment ignored');
            $order->sap_payment_response = $result;
            $order->save();
            return;
        }

        $order->sap_payment_status = 'created';
        $order->sap_payment_doc_entry = (string) ($result['DocEntry'] ?? '');
        $order->sap_payment_doc_num = (string) ($result['DocNum'] ?? '');
        $order->sap_payment_error = null;
        $order->sap_payment_response = $result;
        $order->save();
    }

    private function wasReserveInvoiceDowngradedToSalesOrder(OmnifulOrder $order, ?array $invoiceResult): bool
    {
        $response = $invoiceResult ?? $order->sap_order_response ?? [];
        if (!is_array($response) || $response === []) {
            return false;
        }

        if (($response['reserve_invoice_fallback'] ?? false) === true) {
            return true;
        }

        return (string) ($response['ReserveInvoice'] ?? '') === 'tNO';
    }

    private function resolveIncomingPaymentAmount(array $data, ?array $invoiceResult): float
    {
        $candidates = [
            data_get($invoiceResult ?? [], 'DocTotal'),
            data_get($data, 'invoice.grand_total'),
            data_get($data, 'invoice.total_paid'),
            data_get($data, 'invoice.total'),
            data_get($data, 'total_amount'),
            data_get($data, 'invoice.subtotal'),
        ];

        foreach ($candidates as $value) {
            if (is_numeric($value) && (float) $value > 0) {
                return round((float) $value, 2);
            }
        }

        return 0.0;
    }

    private function resolveIncomingPaymentTransferAccount(string $paymentMethod = ''): string
    {
        $normalizedMethod = strtolower(str_replace([' ', '-', '_'], '', trim($paymentMethod)));
        $settingsMap = $this->parseSimpleMapping((string) (IntegrationSetting::query()->first()?->order_payment_method_map ?? ''));
        $mappedAccount = trim((string) ($settingsMap[$normalizedMethod] ?? config('omniful.order_payment.method_transfer_accounts.' . $normalizedMethod, '')));
        if ($mappedAccount !== '') {
            return $mappedAccount;
        }

        return trim((string) config('omniful.order_payment.transfer_account', ''));
    }

    /**
     * @return array<string,string>
     */
    private function parseSimpleMapping(string $raw): array
    {
        $raw = trim($raw);
        if ($raw === '') {
            return [];
        }

        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            $map = [];
            foreach ($decoded as $key => $value) {
                $normalizedKey = strtolower(str_replace([' ', '-', '_'], '', trim((string) $key)));
                $normalizedValue = trim((string) $value);
                if ($normalizedKey !== '' && $normalizedValue !== '') {
                    $map[$normalizedKey] = $normalizedValue;
                }
            }

            return $map;
        }

        $pairs = preg_split('/[\r\n,]+/', $raw) ?: [];
        $map = [];
        foreach ($pairs as $pair) {
            $pair = trim((string) $pair);
            if ($pair === '' || !str_contains($pair, ':')) {
                continue;
            }

            [$key, $value] = array_map('trim', explode(':', $pair, 2));
            $normalizedKey = strtolower(str_replace([' ', '-', '_'], '', $key));
            if ($normalizedKey !== '' && $value !== '') {
                $map[$normalizedKey] = $value;
            }
        }

        return $map;
    }

    private function isIncomingPaymentEnabled(): bool
    {
        $settings = IntegrationSetting::query()->first();
        if ($settings && $settings->order_payment_enabled !== null) {
            return (bool) $settings->order_payment_enabled;
        }

        return (bool) config('omniful.order_payment.enabled', true);
    }

    private function isCardFeeJournalEnabled(): bool
    {
        // Card fee journal is disabled entirely per business decision — it must
        // never be sent to SAP, only the COGS journal runs. Hard-off here so the
        // step is skipped regardless of the (now inert) settings/config toggle.
        return false;
    }

    private function numericOrderIdOnly(): bool
    {
        return (bool) $this->resolveIntegrationSettingValue(
            'order_numeric_id_only',
            config('omniful.order_sync.numeric_order_id_only', true)
        );
    }

    private function isCogsJournalEnabled(): bool
    {
        $settings = IntegrationSetting::query()->first();
        if ($settings && $settings->order_cogs_journal_enabled !== null) {
            return (bool) $settings->order_cogs_journal_enabled;
        }

        return (bool) config('omniful.order_accounting.cogs_journal_enabled', false);
    }

    private function resolveIntegrationSettingValue(string $field, mixed $fallback = ''): mixed
    {
        $settings = IntegrationSetting::query()->first();
        if (!$settings || !array_key_exists($field, $settings->getAttributes())) {
            return $fallback;
        }

        $value = $settings->{$field};
        if ($value === null || $value === '') {
            return $fallback;
        }

        return $value;
    }

    /**
     * @return array<int,int>
     */
    private function resolveIncomingPaymentInvoiceTypeCandidates(): array
    {
        return array_values(array_map(
            'intval',
            array_filter((array) config('omniful.order_payment.invoice_type_candidates', [13]), fn ($value) => is_numeric($value))
        ));
    }

    private function resolvePaymentMethod(array $data): string
    {
        $candidates = [
            data_get($data, 'invoice.payment_mode'),
            data_get($data, 'invoice.payment_method'),
            data_get($data, 'payment_mode'),
            data_get($data, 'payment.method'),
            data_get($data, 'payment_type'),
            data_get($data, 'payment_method'),
        ];

        foreach ($candidates as $value) {
            $normalized = trim((string) ($value ?? ''));
            if ($normalized !== '') {
                return $this->normalizePaymentMethod($normalized);
            }
        }

        return '';
    }

    private function normalizePaymentMethod(string $value): string
    {
        $normalized = strtolower(trim($value));
        $normalized = str_replace([' ', '-', '_'], '', $normalized);

        return match ($normalized) {
            'visa' => 'Visa',
            'master', 'mastercard' => 'Master',
            'tamara' => 'Tamara',
            'tabby' => 'Tabby',
            'tab' => 'Tab',
            default => trim($value),
        };
    }

    private function createCardFeeJournalIfEligible(OmnifulOrder $order, array $data): void
    {
        if (!$this->isCardFeeJournalEnabled()) {
            if ((string) ($order->sap_card_fee_journal_entry ?? '') === '') {
                $order->sap_card_fee_status = 'ignored';
                $order->sap_card_fee_error = 'Card fee journal disabled from integration settings';
                $order->sap_card_fee_response = [
                    'ignored' => true,
                    'reason' => 'Card fee journal disabled from integration settings',
                ];
                $order->save();
            }

            return;
        }

        // Heal rows that were saved with the literal sentinel "already_integrated"
        // by the previous duplicate-error handler: resolve the real TransId/Number
        // from SAP so the local reference matches an actual Journal Entry instead
        // of a meaningless placeholder string.
        $existingJournalRef = (string) ($order->sap_card_fee_journal_entry ?? '');
        $existingJournalNum = (string) ($order->sap_card_fee_journal_num ?? '');
        if ($existingJournalRef === 'already_integrated' || $existingJournalNum === 'already_integrated') {
            try {
                $client = app(SapServiceLayerClient::class);
                $recovered = $client->findExistingCardFeeJournalForOrder(
                    (string) ($order->external_id ?? ''),
                    'CARD_FEE',
                );
            } catch (\Throwable $e) {
                $recovered = null;
            }

            if (is_array($recovered) && !empty($recovered['TransId'])) {
                $order->sap_card_fee_journal_entry = (string) $recovered['TransId'];
                $order->sap_card_fee_journal_num = (string) ($recovered['Number'] ?? $recovered['JdtNum'] ?? '');
                $order->sap_card_fee_status = 'created';
                $order->sap_card_fee_error = null;
                $recovered['ignored'] = false;
                $recovered['reused_existing'] = true;
                $recovered['healed_from_legacy_marker'] = true;
                $order->sap_card_fee_response = $recovered;
                $order->save();
                return;
            }

            // Could not resolve — clear the sentinel so the next retry can attempt
            // a clean POST (with idempotency lookup inside the client).
            $order->sap_card_fee_journal_entry = '';
            $order->sap_card_fee_journal_num = '';
            $order->sap_card_fee_status = '';
            $order->save();
        }

        if (!empty($order->sap_card_fee_journal_entry)) {
            if ((string) $order->sap_card_fee_status === '') {
                $order->sap_card_fee_status = 'created';
                $order->save();
            }
            return;
        }

        if ((string) $order->sap_payment_status !== 'created') {
            return;
        }

        $paymentMethod = $this->resolvePaymentMethod($data);
        $feeAmount = $this->extractCardFeeAmount($data, $paymentMethod);
        if ($feeAmount <= 0) {
            $order->sap_card_fee_status = 'ignored';
            $order->sap_card_fee_error = 'Ignored: card fee amount missing';
            $order->sap_card_fee_response = [
                'ignored' => true,
                'reason' => 'Ignored: card fee amount missing',
            ];
            $order->save();
            return;
        }

        $client = app(SapServiceLayerClient::class);
        try {
            $result = $client->createCardFeeJournalEntryForOrder([
                'amount' => $feeAmount,
                'posting_date' => data_get($data, 'order_created_at') ?? data_get($data, 'created_at'),
                'reference' => (string) ($order->external_id ?? ''),
                'memo' => trim('Card fee from Omniful order ' . (string) ($order->external_id ?? '') . ($paymentMethod !== '' ? ' | method=' . $paymentMethod : '')),
                'payment_method' => $paymentMethod,
                'expense_account' => $this->resolveIntegrationSettingValue('order_card_fee_expense_account', config('omniful.order_payment.card_fee_expense_account')),
                'offset_account' => $this->resolveIntegrationSettingValue('order_card_fee_offset_account', config('omniful.order_payment.card_fee_offset_account')),
                // Apply input VAT on payment gateway fees per ZATCA: gross
                // amount is split into expense + VAT recoverable when both
                // settings are configured. Without the recoverable account,
                // the JE remains a tax-less 2-line entry (legacy behaviour).
                'vat_percent' => (float) $this->resolveIntegrationSettingValue('order_card_fee_vat_percent', config('omniful.order_payment.card_fee_vat_percent', 15)),
                'vat_recoverable_account' => (string) $this->resolveIntegrationSettingValue('order_card_fee_vat_recoverable_account', config('omniful.order_payment.card_fee_vat_recoverable_account', '')),
                'hub_code' => (string) ($order->hub_code ?? data_get($data, 'hub_code', '')),
            ]);
        } catch (SapRequestException $e) {
            $order->sap_card_fee_status = 'failed';
            $order->sap_card_fee_error = $e->getMessage();
            $order->sap_card_fee_response = [
                'ignored' => false,
                'request_body' => $e->requestBody,
                'error_response_body' => $e->responseBody,
                'status_code' => $e->statusCode,
            ];
            $order->save();
            throw $e;
        }

        if (($result['ignored'] ?? false) === true) {
            $order->sap_card_fee_status = 'ignored';
            $order->sap_card_fee_error = (string) ($result['reason'] ?? 'Card fee journal ignored');
            $order->sap_card_fee_response = $result;
            $order->save();
            return;
        }

        $order->sap_card_fee_status = 'created';
        $order->sap_card_fee_journal_entry = (string) ($result['TransId'] ?? '');
        $order->sap_card_fee_journal_num = (string) ($result['Number'] ?? $result['JdtNum'] ?? '');
        $order->sap_card_fee_error = null;
        $order->sap_card_fee_response = $result;
        $order->save();
    }

    private function extractCardFeeAmount(array $data, string $paymentMethod = ''): float
    {
        $candidates = [
            data_get($data, 'payment.card_fee_amount'),
            data_get($data, 'payment.card_fee'),
            data_get($data, 'invoice.card_fee_amount'),
            data_get($data, 'invoice.card_fee'),
            data_get($data, 'card_fee_amount'),
            data_get($data, 'card_fee'),
            data_get($data, 'payment.gateway_fee'),
            data_get($data, 'payment.fee_amount'),
        ];

        foreach ($candidates as $value) {
            if (is_numeric($value) && (float) $value > 0) {
                return round((float) $value, 2);
            }
        }

        $feeRule = $this->resolveCardFeeRule($paymentMethod);
        if (($feeRule['percent'] ?? 0.0) <= 0 && ($feeRule['fixed'] ?? 0.0) <= 0) {
            return 0.0;
        }

        $total = data_get($data, 'invoice.total_paid')
            ?? data_get($data, 'invoice.total')
            ?? data_get($data, 'invoice.grand_total')
            ?? data_get($data, 'total_amount');

        if (!is_numeric($total) || (float) $total <= 0) {
            return 0.0;
        }

        return round((((float) $total) * (($feeRule['percent'] ?? 0.0) / 100)) + ($feeRule['fixed'] ?? 0.0), 2);
    }

    /**
     * @return array{percent:float,fixed:float}
     */
    private function resolveCardFeeRule(string $paymentMethod): array
    {
        $normalizedMethod = strtolower(str_replace([' ', '-', '_'], '', trim($paymentMethod)));

        $settings = IntegrationSetting::query()->first();
        $settingsMap = $this->parseSimpleMapping((string) ($settings?->order_card_fee_method_percent_map ?? ''));
        if ($normalizedMethod !== '' && isset($settingsMap[$normalizedMethod])) {
            $rule = $this->parseCardFeeRule($settingsMap[$normalizedMethod]);
            if ($rule['percent'] > 0 || $rule['fixed'] > 0) {
                return $rule;
            }
        }

        $configuredMap = config('omniful.order_payment.card_fee_method_percent_map', []);
        if ($normalizedMethod !== '' && is_array($configuredMap) && isset($configuredMap[$normalizedMethod])) {
            $rule = $this->parseCardFeeRule($configuredMap[$normalizedMethod]);
            if ($rule['percent'] > 0 || $rule['fixed'] > 0) {
                return $rule;
            }
        }

        return [
            'percent' => (float) $this->resolveIntegrationSettingValue('order_card_fee_percent', config('omniful.order_payment.card_fee_percent', 0)),
            'fixed' => 0.0,
        ];
    }

    /**
     * @return array{percent:float,fixed:float}
     */
    private function parseCardFeeRule(mixed $value): array
    {
        if (is_array($value)) {
            return [
                'percent' => (float) ($value['percent'] ?? $value['percentage'] ?? 0),
                'fixed' => (float) ($value['fixed'] ?? $value['amount'] ?? $value['fixed_amount'] ?? 0),
            ];
        }

        if (is_numeric($value)) {
            return ['percent' => (float) $value, 'fixed' => 0.0];
        }

        $raw = strtolower(trim((string) $value));
        if ($raw === '') {
            return ['percent' => 0.0, 'fixed' => 0.0];
        }

        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            return $this->parseCardFeeRule($decoded);
        }

        $percent = 0.0;
        $fixed = 0.0;

        if (preg_match('/(?:percent|percentage)\s*=\s*([0-9]+(?:\.[0-9]+)?)/', $raw, $match)) {
            $percent = (float) $match[1];
        }
        if (preg_match('/(?:fixed|amount|fixed_amount)\s*=\s*([0-9]+(?:\.[0-9]+)?)/', $raw, $match)) {
            $fixed = (float) $match[1];
        }

        if ($percent > 0 || $fixed > 0) {
            return ['percent' => $percent, 'fixed' => $fixed];
        }

        $parts = preg_split('/\s*(?:\+|\|)\s*/', str_replace('%', '', $raw)) ?: [];
        if (isset($parts[0]) && is_numeric($parts[0])) {
            $percent = (float) $parts[0];
        }
        if (isset($parts[1]) && is_numeric($parts[1])) {
            $fixed = (float) $parts[1];
        }

        return ['percent' => $percent, 'fixed' => $fixed];
    }

    private function createDeliveryIfEligible(OmnifulOrder $order, array $data): void
    {
        if (!empty($order->sap_delivery_doc_entry)) {
            if ((string) $order->sap_delivery_status === '') {
                $order->sap_delivery_status = 'created';
                $order->save();
            }
            return;
        }

        $invoiceDocEntry = (int) ($order->sap_doc_entry ?? 0);
        if ($invoiceDocEntry <= 0) {
            $order->sap_delivery_status = 'blocked';
            $order->sap_delivery_error = 'Delivery blocked: source order is missing in SAP';
            $order->sap_delivery_response = [
                'ignored' => true,
                'reason' => 'Delivery blocked: source order is missing in SAP',
            ];
            $order->save();
            return;
        }

        $client = app(SapServiceLayerClient::class);
        try {
            $result = $client->createDeliveryFromReserveOrder([
                'order_doc_entry' => $invoiceDocEntry,
                'hub_code' => data_get($data, 'hub_code'),
                'external_id' => (string) ($order->external_id ?? ''),
                'order_items' => (array) data_get($data, 'order_items', []),
            ]);
        } catch (SapRequestException $e) {
            // Last-chance recovery for "-5002 base document already closed" and
            // similar duplicate signals — try to rebind the existing delivery
            // rather than escalating to failed.
            $rescued = null;
            if (method_exists($client, 'findExistingDeliveryForOmnifulOrder')) {
                try {
                    $rescued = $client->findExistingDeliveryForOmnifulOrder(
                        $data,
                        (string) ($order->external_id ?? ''),
                        $invoiceDocEntry,
                    );
                } catch (\Throwable $rescueError) {
                    $rescued = null;
                }
            }

            if (is_array($rescued) && !empty($rescued['DocEntry'])) {
                $order->sap_delivery_status = 'created';
                $order->sap_delivery_doc_entry = (string) ($rescued['DocEntry'] ?? '');
                $order->sap_delivery_doc_num = (string) ($rescued['DocNum'] ?? '');
                $order->sap_delivery_error = null;
                $rescued['ignored'] = false;
                $rescued['reused_existing'] = true;
                $rescued['recovered_after_duplicate_error'] = true;
                $order->sap_delivery_response = $rescued;
                $order->save();

                return;
            }

            $order->sap_delivery_status = 'failed';
            $order->sap_delivery_error = $e->getMessage();
            $order->sap_delivery_response = [
                'ignored' => false,
                'request_body' => $e->requestBody,
                'error_response_body' => $e->responseBody,
                'status_code' => $e->statusCode,
            ];
            $order->save();
            throw $e;
        }

        if (($result['ignored'] ?? false) === true) {
            $order->sap_delivery_status = 'ignored';
            $order->sap_delivery_error = (string) ($result['reason'] ?? 'Delivery ignored');
            $order->sap_delivery_response = $result;
            $order->save();
            return;
        }

        $order->sap_delivery_status = 'created';
        $order->sap_delivery_doc_entry = (string) ($result['DocEntry'] ?? '');
        $order->sap_delivery_doc_num = (string) ($result['DocNum'] ?? '');
        $order->sap_delivery_error = null;
        $order->sap_delivery_response = $result;
        $order->save();
    }

    private function createCreditNoteIfEligible(OmnifulOrder $order, array $data): void
    {
        if (!empty($order->sap_credit_note_doc_entry)) {
            if ((string) $order->sap_credit_note_status === '') {
                $order->sap_credit_note_status = 'created';
                $order->save();
            }

            $this->createCancelCogsReversalIfEligible($order);
            return;
        }

        if (empty($order->sap_doc_entry) && empty($order->sap_delivery_doc_entry)) {
            $order->sap_credit_note_status = 'blocked';
            $order->sap_credit_note_error = 'Credit note blocked: source order is missing in SAP';
            $order->sap_credit_note_response = [
                'ignored' => true,
                'reason' => 'Credit note blocked: source order is missing in SAP',
            ];
            $order->save();
            return;
        }

        $externalId = trim((string) ($order->external_id ?? ''));
        $creditReference = $externalId !== '' ? ($externalId . '-cancel') : 'order-cancel';

        $client = app(SapServiceLayerClient::class);
        try {
            $result = $client->createArCreditMemoFromReturnOrder($data, [
                'external_id' => $creditReference,
                'base_delivery_doc_entry' => (int) ($order->sap_delivery_doc_entry ?? 0),
                'base_order_doc_entry' => (int) ($order->sap_doc_entry ?? 0),
            ]);
        } catch (SapRequestException $e) {
            $order->sap_credit_note_status = 'failed';
            $order->sap_credit_note_error = $e->getMessage();
            $order->sap_credit_note_response = [
                'ignored' => false,
                'request_body' => $e->requestBody,
                'error_response_body' => $e->responseBody,
                'status_code' => $e->statusCode,
            ];
            $order->save();
            throw $e;
        }

        if (($result['ignored'] ?? false) === true) {
            $order->sap_credit_note_status = 'ignored';
            $order->sap_credit_note_error = (string) ($result['reason'] ?? 'Credit note ignored');
            $order->sap_credit_note_response = $result;
            $order->save();
            return;
        }

        $order->sap_credit_note_status = 'created';
        $order->sap_credit_note_doc_entry = (string) ($result['DocEntry'] ?? '');
        $order->sap_credit_note_doc_num = (string) ($result['DocNum'] ?? '');
        $order->sap_credit_note_error = null;
        $order->sap_credit_note_response = $result;
        $order->save();

        $this->createCancelCogsReversalIfEligible($order);
    }

    /**
     * Post the COGS journal at the AR Invoice + Payment stage (client's
     * "2 JEs" alongside Card Fee). Reads item cost from the Item Master, so
     * it does not need a delivery. Replaces the old delivery-stage COGS.
     */
    private function createCogsJournalAtInvoiceIfEligible(OmnifulOrder $order, array $data): void
    {
        if (!$this->isCogsJournalEnabled()) {
            if ((string) ($order->sap_cogs_journal_entry ?? '') === '') {
                $order->sap_cogs_status = 'ignored';
                $order->sap_cogs_error = 'COGS journal disabled from integration settings';
                $order->sap_cogs_response = [
                    'ignored' => true,
                    'reason' => 'COGS journal disabled from integration settings',
                ];
                $order->save();
            }

            return;
        }

        // Already posted, or no invoice yet → nothing to do.
        if (!empty($order->sap_cogs_journal_entry)) {
            if ((string) $order->sap_cogs_status === '') {
                $order->sap_cogs_status = 'created';
                $order->save();
            }
            return;
        }
        if (empty($order->sap_doc_entry)) {
            return;
        }

        $client = app(SapServiceLayerClient::class);
        try {
            $result = $client->createCogsJournalForOmnifulOrder([
                'reference' => (string) ($order->external_id ?? ''),
                'external_id' => (string) ($order->external_id ?? ''),
                'order_items' => (array) data_get($data, 'order_items', data_get($data, 'items', [])),
                'hub_code' => (string) ($order->hub_code ?? data_get($data, 'hub_code', '')),
                'posting_date' => data_get($data, 'order_created_at') ?? data_get($data, 'created_at'),
                'memo' => 'COGS journal from Omniful order ' . (string) ($order->external_id ?? ''),
                'expense_account' => $this->resolveIntegrationSettingValue('order_cogs_expense_account', config('omniful.order_accounting.cogs_expense_account')),
                'offset_account' => $this->resolveIntegrationSettingValue('order_cogs_inventory_offset_account', config('omniful.order_accounting.inventory_offset_account')),
            ]);
        } catch (SapRequestException $e) {
            $order->sap_cogs_status = 'failed';
            $order->sap_cogs_error = $e->getMessage();
            $order->sap_cogs_response = [
                'ignored' => false,
                'request_body' => $e->requestBody,
                'error_response_body' => $e->responseBody,
                'status_code' => $e->statusCode,
            ];
            $order->save();
            throw $e;
        }

        if (($result['ignored'] ?? false) === true) {
            $order->sap_cogs_status = 'ignored';
            $order->sap_cogs_error = (string) ($result['reason'] ?? 'COGS journal ignored');
            $order->sap_cogs_response = $result;
            $order->save();
            return;
        }

        $order->sap_cogs_status = 'created';
        $order->sap_cogs_journal_entry = (string) ($result['TransId'] ?? $result['JdtNum'] ?? '');
        $order->sap_cogs_journal_num = (string) ($result['Number'] ?? $result['JdtNum'] ?? '');
        $order->sap_cogs_error = null;
        $order->sap_cogs_response = $result;
        $order->save();
    }

    private function createCogsJournalIfEligible(OmnifulOrder $order): void
    {
        if (!$this->isCogsJournalEnabled()) {
            if ((string) ($order->sap_cogs_journal_entry ?? '') === '') {
                $order->sap_cogs_status = 'ignored';
                $order->sap_cogs_error = 'COGS journal disabled from integration settings';
                $order->sap_cogs_response = [
                    'ignored' => true,
                    'reason' => 'COGS journal disabled from integration settings',
                ];
                $order->save();
            }

            return;
        }

        if (!empty($order->sap_cogs_journal_entry)) {
            if ((string) $order->sap_cogs_status === '') {
                $order->sap_cogs_status = 'created';
                $order->save();
            }
            return;
        }

        $deliveryDocEntry = (int) ($order->sap_delivery_doc_entry ?? 0);
        if ($deliveryDocEntry <= 0) {
            return;
        }

        $client = app(SapServiceLayerClient::class);
        try {
            $result = $client->createCogsJournalEntryForDelivery([
                'delivery_doc_entry' => $deliveryDocEntry,
                'reference' => (string) ($order->external_id ?? ''),
                'memo' => 'COGS journal from Omniful order ' . (string) ($order->external_id ?? ''),
                'expense_account' => $this->resolveIntegrationSettingValue('order_cogs_expense_account', config('omniful.order_accounting.cogs_expense_account')),
                'offset_account' => $this->resolveIntegrationSettingValue('order_cogs_inventory_offset_account', config('omniful.order_accounting.inventory_offset_account')),
            ]);
        } catch (SapRequestException $e) {
            $order->sap_cogs_status = 'failed';
            $order->sap_cogs_error = $e->getMessage();
            $order->sap_cogs_response = [
                'ignored' => false,
                'request_body' => $e->requestBody,
                'error_response_body' => $e->responseBody,
                'status_code' => $e->statusCode,
            ];
            $order->save();
            throw $e;
        }

        if (($result['ignored'] ?? false) === true) {
            $order->sap_cogs_status = 'ignored';
            $order->sap_cogs_error = (string) ($result['reason'] ?? 'COGS journal ignored');
            $order->sap_cogs_response = $result;
            $order->save();
            return;
        }

        $order->sap_cogs_status = 'created';
        $order->sap_cogs_journal_entry = (string) ($result['TransId'] ?? '');
        $order->sap_cogs_journal_num = (string) ($result['Number'] ?? $result['JdtNum'] ?? '');
        $order->sap_cogs_error = null;
        $order->sap_cogs_response = $result;
        $order->save();
    }

    private function createCancelCogsReversalIfEligible(OmnifulOrder $order): void
    {
        // Controlled by the Integration Settings toggle (return_cogs_reversal_enabled);
        // env/config flag is only the fallback when the setting was never stored.
        $reversalEnabled = $this->resolveIntegrationSettingValue(
            'return_cogs_reversal_enabled',
            config('omniful.order_accounting.return_cogs_reversal_enabled', false)
        );
        if (!(bool) $reversalEnabled) {
            return;
        }

        if (!empty($order->sap_cancel_cogs_journal_entry)) {
            if ((string) $order->sap_cancel_cogs_status === '') {
                $order->sap_cancel_cogs_status = 'created';
                $order->save();
            }
            return;
        }

        $creditMemoDocEntry = (int) ($order->sap_credit_note_doc_entry ?? 0);
        if ($creditMemoDocEntry <= 0) {
            return;
        }

        $client = app(SapServiceLayerClient::class);
        try {
            $result = $client->createCogsReversalJournalForCreditMemo([
                'credit_memo_doc_entry' => $creditMemoDocEntry,
                'reference' => (string) ($order->external_id ?? ''),
                'memo' => 'COGS reversal from canceled Omniful order ' . (string) ($order->external_id ?? ''),
                // Original order reference, used to look up the posted order COGS
                // when the credit-memo line has no stock cost (bundles/kits).
                'cogs_order_reference' => (string) ($order->external_id ?? ''),
                'expense_account' => $this->resolveIntegrationSettingValue('order_cogs_expense_account', config('omniful.order_accounting.cogs_expense_account')),
                'offset_account' => $this->resolveIntegrationSettingValue('order_cogs_inventory_offset_account', config('omniful.order_accounting.inventory_offset_account')),
            ]);
        } catch (SapRequestException $e) {
            $order->sap_cancel_cogs_status = 'failed';
            $order->sap_cancel_cogs_error = $e->getMessage();
            $order->sap_cancel_cogs_response = [
                'ignored' => false,
                'request_body' => $e->requestBody,
                'error_response_body' => $e->responseBody,
                'status_code' => $e->statusCode,
            ];
            $order->save();
            throw $e;
        }

        if (($result['ignored'] ?? false) === true) {
            $order->sap_cancel_cogs_status = 'ignored';
            $order->sap_cancel_cogs_error = (string) ($result['reason'] ?? 'Cancel COGS reversal ignored');
            $order->sap_cancel_cogs_response = $result;
            $order->save();
            return;
        }

        $order->sap_cancel_cogs_status = 'created';
        $order->sap_cancel_cogs_journal_entry = (string) ($result['TransId'] ?? '');
        $order->sap_cancel_cogs_journal_num = (string) ($result['Number'] ?? $result['JdtNum'] ?? '');
        $order->sap_cancel_cogs_error = null;
        $order->sap_cancel_cogs_response = $result;
        $order->save();
    }

    private function syncSalesOrderMetadata(OmnifulOrder $order, string $eventName, string $status): void
    {
        $docEntry = (int) ($order->sap_doc_entry ?? 0);
        if ($docEntry <= 0) {
            return;
        }

        try {
            $client = app(SapServiceLayerClient::class);
            $client->syncSalesOrderFromOmnifulEvent($docEntry, $eventName, $status);
        } catch (\Throwable $e) {
            Log::warning('SAP sales order metadata sync failed', [
                'external_id' => $order->external_id,
                'sap_doc_entry' => $docEntry,
                'event_name' => $eventName,
                'status' => $status,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
