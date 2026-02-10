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

        if (!empty($order->sap_doc_entry) && in_array((string) $order->sap_status, ['created', 'invoiced'], true)) {
            $order->sap_status = 'skipped';
            $order->save();
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
        $result = $client->createArReserveInvoiceFromOmnifulOrder($data, $externalId);
        if (($result['ignored'] ?? false) === true) {
            $order->sap_status = 'ignored';
            $order->sap_error = (string) ($result['reason'] ?? 'Ignored: no order lines found');
            $order->save();
            return;
        }

        $order->sap_status = 'created';
        $order->sap_doc_entry = (string) ($result['DocEntry'] ?? '');
        $order->sap_doc_num = (string) ($result['DocNum'] ?? '');
        $order->sap_error = null;
        $order->save();
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
}

