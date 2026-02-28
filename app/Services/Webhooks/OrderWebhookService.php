<?php

namespace App\Services\Webhooks;

use App\Models\OmnifulOrder;
use App\Models\OmnifulOrderEvent;
use App\Services\SapServiceLayerClient;
use Illuminate\Support\Facades\Log;

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
        $invoiceEligibility = $mapper->resolveOrderInvoiceEligibility($eventName, $status, $paymentSignals);
        $deliveryEligibility = $mapper->resolveOrderDeliveryEligibility($eventName, $status);

        if (!($invoiceEligibility['eligible'] ?? false) && !($deliveryEligibility['eligible'] ?? false)) {
            if (!empty($order->sap_doc_entry)) {
                $this->syncSalesOrderMetadata($order, $eventName, $status);
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
        if (($invoiceEligibility['eligible'] ?? false) && empty($order->sap_doc_entry)) {
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

        if (($invoiceEligibility['eligible'] ?? false) || !empty($order->sap_doc_entry)) {
            $this->createIncomingPaymentIfEligible($order, $data, $invoiceResult);
            $this->createCardFeeJournalIfEligible($order, $data);
        }

        if (($deliveryEligibility['eligible'] ?? false)) {
            $this->createDeliveryIfEligible($order, $data);
        }

        $this->createCogsJournalIfEligible($order);
        $this->syncSalesOrderMetadata($order, $eventName, $status);
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

    private function createCardFeeJournalIfEligible(OmnifulOrder $order, array $data): void
    {
        if (!(bool) config('omniful.order_payment.card_fee_journal_enabled', false)) {
            return;
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

        $feeAmount = $this->extractCardFeeAmount($data);
        if ($feeAmount <= 0) {
            $order->sap_card_fee_status = 'ignored';
            $order->sap_card_fee_error = 'Ignored: card fee amount missing';
            $order->save();
            return;
        }

        $client = app(SapServiceLayerClient::class);
        $result = $client->createCardFeeJournalEntryForOrder([
            'amount' => $feeAmount,
            'posting_date' => data_get($data, 'order_created_at') ?? data_get($data, 'created_at'),
            'reference' => (string) ($order->external_id ?? ''),
            'memo' => 'Card fee from Omniful order ' . (string) ($order->external_id ?? ''),
            'expense_account' => config('omniful.order_payment.card_fee_expense_account'),
            'offset_account' => config('omniful.order_payment.card_fee_offset_account'),
        ]);

        if (($result['ignored'] ?? false) === true) {
            $order->sap_card_fee_status = 'ignored';
            $order->sap_card_fee_error = (string) ($result['reason'] ?? 'Card fee journal ignored');
            $order->save();
            return;
        }

        $order->sap_card_fee_status = 'created';
        $order->sap_card_fee_journal_entry = (string) ($result['TransId'] ?? '');
        $order->sap_card_fee_journal_num = (string) ($result['Number'] ?? $result['JdtNum'] ?? '');
        $order->sap_card_fee_error = null;
        $order->save();
    }

    private function extractCardFeeAmount(array $data): float
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
                return (float) $value;
            }
        }

        $percent = (float) config('omniful.order_payment.card_fee_percent', 0);
        if ($percent <= 0) {
            return 0.0;
        }

        $total = data_get($data, 'invoice.grand_total');
        if ($total === null) {
            $total = data_get($data, 'total_amount');
        }

        if (!is_numeric($total) || (float) $total <= 0) {
            return 0.0;
        }

        return round(((float) $total) * ($percent / 100), 2);
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
            $order->save();
            return;
        }

        $client = app(SapServiceLayerClient::class);
        $result = $client->createDeliveryFromReserveOrder([
            'order_doc_entry' => $invoiceDocEntry,
            'hub_code' => data_get($data, 'hub_code'),
            'external_id' => (string) ($order->external_id ?? ''),
        ]);

        if (($result['ignored'] ?? false) === true) {
            $order->sap_delivery_status = 'ignored';
            $order->sap_delivery_error = (string) ($result['reason'] ?? 'Delivery ignored');
            $order->save();
            return;
        }

        $order->sap_delivery_status = 'created';
        $order->sap_delivery_doc_entry = (string) ($result['DocEntry'] ?? '');
        $order->sap_delivery_doc_num = (string) ($result['DocNum'] ?? '');
        $order->sap_delivery_error = null;
        $order->save();
    }

    private function createCogsJournalIfEligible(OmnifulOrder $order): void
    {
        if (!(bool) config('omniful.order_accounting.cogs_journal_enabled', false)) {
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
        $result = $client->createCogsJournalEntryForDelivery([
            'delivery_doc_entry' => $deliveryDocEntry,
            'reference' => (string) ($order->external_id ?? ''),
            'memo' => 'COGS journal from Omniful order ' . (string) ($order->external_id ?? ''),
            'expense_account' => config('omniful.order_accounting.cogs_expense_account'),
            'offset_account' => config('omniful.order_accounting.inventory_offset_account'),
        ]);

        if (($result['ignored'] ?? false) === true) {
            $order->sap_cogs_status = 'ignored';
            $order->sap_cogs_error = (string) ($result['reason'] ?? 'COGS journal ignored');
            $order->save();
            return;
        }

        $order->sap_cogs_status = 'created';
        $order->sap_cogs_journal_entry = (string) ($result['TransId'] ?? '');
        $order->sap_cogs_journal_num = (string) ($result['Number'] ?? $result['JdtNum'] ?? '');
        $order->sap_cogs_error = null;
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
