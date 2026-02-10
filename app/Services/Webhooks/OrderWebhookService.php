<?php

namespace App\Services\Webhooks;

use App\Models\OmnifulOrder;
use App\Models\OmnifulOrderEvent;
use App\Services\SapServiceLayerClient;

class OrderWebhookService
{
    public function process(OmnifulOrderEvent $event): void
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
        $status = (string) (data_get($data, 'status_code') ?? data_get($data, 'status') ?? '');
        $paymentSignals = $this->extractPaymentSignals($data);

        $mapper = app(WebhookStatusMapper::class);
        $eligibility = $mapper->resolveOrderInvoiceEligibility($eventName, $status, $paymentSignals);
        if (!($eligibility['eligible'] ?? false)) {
            $order->sap_status = 'ignored';
            $order->sap_error = (string) ($eligibility['reason'] ?? 'Ignored: order is not eligible for AR reserve invoice');
            $order->save();
            return;
        }

        $client = app(SapServiceLayerClient::class);
        $invoiceResult = null;
        if (empty($order->sap_doc_entry)) {
            $invoiceResult = $client->createArReserveInvoiceFromOmnifulOrder($data, $externalId);
            if (($invoiceResult['ignored'] ?? false) === true) {
                $order->sap_status = 'ignored';
                $order->sap_error = (string) ($invoiceResult['reason'] ?? 'Ignored: no order lines found');
                $order->save();
                return;
            }

            $order->sap_status = 'created';
            $order->sap_doc_entry = (string) ($invoiceResult['DocEntry'] ?? '');
            $order->sap_doc_num = (string) ($invoiceResult['DocNum'] ?? '');
            $order->sap_error = null;
            $order->save();
        }

        $this->createIncomingPaymentIfEligible($order, $data, $invoiceResult);
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
            (string) (data_get($data, 'invoice.payment_method') ?? ''),
            (string) (data_get($data, 'invoice.payment_type') ?? ''),
        ], fn ($v) => trim($v) !== ''));
    }

    private function createIncomingPaymentIfEligible(OmnifulOrder $order, array $data, ?array $invoiceResult): void
    {
        if (!(bool) config('omniful.order_payment.enabled', true)) {
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
        $result = $client->createIncomingPaymentForInvoice([
            'invoice_doc_entry' => $invoiceDocEntry,
            'card_code' => data_get($invoiceResult ?? [], 'CardCode') ?? data_get($data, 'customer.code'),
            'sum_applied' => data_get($invoiceResult ?? [], 'DocTotal') ?? data_get($data, 'invoice.grand_total') ?? data_get($data, 'total_amount') ?? 0,
            'transfer_date' => data_get($data, 'order_created_at') ?? data_get($data, 'created_at'),
            'transfer_account' => config('omniful.order_payment.transfer_account'),
            'invoice_type_candidates' => config('omniful.order_payment.invoice_type_candidates', [17, 13]),
        ]);

        if (($result['ignored'] ?? false) === true) {
            $order->sap_payment_status = 'ignored';
            $order->sap_payment_error = (string) ($result['reason'] ?? 'Incoming payment ignored');
            $order->save();
            return;
        }

        $order->sap_payment_status = 'created';
        $order->sap_payment_doc_entry = (string) ($result['DocEntry'] ?? '');
        $order->sap_payment_doc_num = (string) ($result['DocNum'] ?? '');
        $order->sap_payment_error = null;
        $order->save();
    }
}
