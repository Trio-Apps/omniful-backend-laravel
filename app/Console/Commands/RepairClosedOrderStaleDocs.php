<?php

namespace App\Console\Commands;

use App\Models\OmnifulOrder;
use App\Services\SapServiceLayerClient;
use Illuminate\Console\Command;

/**
 * One-time repair for orders whose AR reserve invoice is already CLOSED + fully
 * PAID in SAP (i.e. delivery + incoming payment were done on the old server) but
 * whose local delivery/payment doc links were lost in the migration — so the
 * monitor still shows a stale "-5002 base already closed" (delivery) / "-10
 * invoice already closed or blocked" (payment) error and a resend can't recreate
 * the docs.
 *
 * For each such order that SAP confirms is CLOSED (+ paid), it REBINDS the
 * existing SAP delivery/payment (populates the doc entry) or, if the doc can't
 * be located, CLEARS the stale error (the work is genuinely done). READ-ONLY on
 * SAP — only local rows are updated. Orders whose invoice is NOT closed are left
 * untouched (a real, unresolved error).
 */
class RepairClosedOrderStaleDocs extends Command
{
    protected $signature = 'omniful:repair-stale-closed-docs {--dry-run} {--limit=0}';

    protected $description = 'Rebind existing SAP delivery/payment (or clear stale errors) for completed closed+paid orders stuck on -5002/-10';

    public function handle(SapServiceLayerClient $client): int
    {
        $dry = (bool) $this->option('dry-run');
        $limit = (int) $this->option('limit');

        $invRef = new \ReflectionMethod($client, 'getArReserveInvoice');
        $invRef->setAccessible(true);

        $stats = [
            'orders' => 0, 'skipped_no_invoice' => 0, 'skipped_not_closed' => 0,
            'delivery_rebound' => 0, 'delivery_cleared' => 0,
            'payment_rebound' => 0, 'payment_cleared' => 0, 'updated' => 0,
        ];

        $query = OmnifulOrder::query()
            ->where(function ($w) {
                $w->where(function ($d) {
                    $d->where('sap_delivery_error', 'like', '%already been closed%')
                        ->where(fn ($x) => $x->whereNull('sap_delivery_doc_entry')->orWhere('sap_delivery_doc_entry', ''));
                })->orWhere(function ($p) {
                    $p->where('sap_payment_error', 'like', '%already closed or blocked%')
                        ->where(fn ($x) => $x->whereNull('sap_payment_doc_entry')->orWhere('sap_payment_doc_entry', ''));
                });
            })
            ->orderByDesc('id');

        if ($limit > 0) {
            $query->limit($limit);
        }

        foreach ($query->cursor() as $order) {
            $stats['orders']++;

            $invEntry = (int) $order->sap_doc_entry;
            if ($invEntry <= 0) {
                $stats['skipped_no_invoice']++;
                continue;
            }

            // Only touch orders SAP confirms are complete (invoice closed).
            $invoice = (array) $invRef->invoke($client, $invEntry);
            $closed = (string) ($invoice['DocumentStatus'] ?? '') === 'bost_Close';
            $total = (float) ($invoice['DocTotal'] ?? 0);
            $paidFull = $total > 0 && abs(((float) ($invoice['PaidToDate'] ?? 0)) - $total) < 0.01;
            if (!$closed) {
                $stats['skipped_not_closed']++;
                continue;
            }

            $payload = (array) ($order->last_payload ?? []);
            $data = (array) (data_get($payload, 'data', $payload));
            $externalId = (string) $order->external_id;
            $changes = [];

            $delErr = trim((string) $order->sap_delivery_error);
            $delDoc = trim((string) $order->sap_delivery_doc_entry);
            if ($delDoc === '' && str_contains(strtolower($delErr), 'already been closed')) {
                $delivery = $client->findExistingDeliveryForOmnifulOrder($data, $externalId, $invEntry);
                if (is_array($delivery) && !empty($delivery['DocEntry'])) {
                    $changes['sap_delivery_doc_entry'] = (string) $delivery['DocEntry'];
                    $changes['sap_delivery_status'] = 'created';
                    $changes['sap_delivery_error'] = null;
                    $stats['delivery_rebound']++;
                } else {
                    // Invoice is closed => it WAS delivered; drop the stale error.
                    $changes['sap_delivery_status'] = 'created';
                    $changes['sap_delivery_error'] = null;
                    $stats['delivery_cleared']++;
                }
            }

            $payErr = trim((string) $order->sap_payment_error);
            $payDoc = trim((string) $order->sap_payment_doc_entry);
            if ($payDoc === '' && str_contains(strtolower($payErr), 'already closed or blocked')) {
                $payment = $client->findExistingIncomingPaymentForOmnifulOrder($data, $externalId, $invEntry);
                if (is_array($payment) && !empty($payment['DocEntry'])) {
                    $changes['sap_payment_doc_entry'] = (string) $payment['DocEntry'];
                    $changes['sap_payment_status'] = 'created';
                    $changes['sap_payment_error'] = null;
                    $stats['payment_rebound']++;
                } elseif ($paidFull) {
                    $changes['sap_payment_status'] = 'created';
                    $changes['sap_payment_error'] = null;
                    $stats['payment_cleared']++;
                }
            }

            if ($changes !== []) {
                if (!$dry) {
                    $order->forceFill($changes)->save();
                }
                $stats['updated']++;
            }
        }

        $this->info(($dry ? '[DRY RUN] ' : '') . json_encode($stats, JSON_UNESCAPED_SLASHES));

        return self::SUCCESS;
    }
}
