<?php

namespace App\Services\Webhooks;

use App\Models\OmnifulPurchaseOrderEvent;
use App\Services\SapServiceLayerClient;

class PurchaseOrderWebhookService
{
    public function process(OmnifulPurchaseOrderEvent $event): void
    {
        $payload = $event->payload ?? [];
        $data = data_get($payload, 'data', []);
        $eventName = (string) data_get($payload, 'event_name', '');
        $status = (string) data_get($data, 'status', '');

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

            if (str_contains($eventName, 'update')) {
                $event->sap_status = 'updated';
            } elseif (str_contains($eventName, 'receive') || $status === 'received') {
                $event->sap_status = 'received_logged';
            } elseif (str_contains($eventName, 'cancel') || $status === 'cancelled' || $status === 'canceled') {
                $event->sap_status = 'cancel_logged';
            } else {
                $event->sap_status = $event->sap_status ?: 'logged';
            }

            $event->save();
        }
    }
}

