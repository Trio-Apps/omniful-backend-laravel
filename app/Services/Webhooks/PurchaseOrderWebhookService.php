<?php

namespace App\Services\Webhooks;

use App\Models\OmnifulPurchaseOrderEvent;
use App\Services\SapServiceLayerClient;

class PurchaseOrderWebhookService
{
    public function process(OmnifulPurchaseOrderEvent $event): void
    {
        $mapper = app(WebhookStatusMapper::class);
        $payload = $event->payload ?? [];
        $data = data_get($payload, 'data', []);
        $eventName = (string) data_get($payload, 'event_name', '');
        $status = (string) data_get($data, 'status', '');
        $mapping = $mapper->resolvePurchaseOrderStatus($eventName, $status, $event->sap_status);

        if (!($mapping['mapped'] ?? false)) {
            $event->sap_status = 'ignored';
            $event->sap_error = (string) ($mapping['reason'] ?? 'Ignored: purchase-order status/event not allowed by mapping');
            $event->save();
            return;
        }

        if ($event->external_id) {
            $existing = OmnifulPurchaseOrderEvent::where('external_id', $event->external_id)
                ->whereNotNull('sap_doc_entry')
                ->first();
            if ($existing) {
                $event->sap_status = 'skipped';
                $event->sap_doc_entry = $existing->sap_doc_entry;
                $event->sap_doc_num = $existing->sap_doc_num;
                $event->save();
            }
        }

        $client = app(SapServiceLayerClient::class);

        if (!$event->sap_doc_entry) {
            $result = $client->createPurchaseOrderFromOmniful($data);
            $event->sap_status = 'created';
            $event->sap_doc_entry = $result['DocEntry'] ?? null;
            $event->sap_doc_num = $result['DocNum'] ?? null;
            $event->save();
        }

        if ($event->sap_doc_entry) {
            $comment = trim(sprintf(
                '[%s] %s %s',
                now()->format('Y-m-d H:i:s'),
                $eventName ?: 'purchase_order.event',
                $status ?: ''
            ));
            $client->appendPurchaseOrderComment((int) $event->sap_doc_entry, $comment);
            $event->sap_status = (string) ($mapping['sap_status'] ?? $event->sap_status ?? 'logged');

            $event->save();
        }
    }
}
