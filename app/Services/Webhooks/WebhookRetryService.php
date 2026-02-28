<?php

namespace App\Services\Webhooks;

use App\Models\OmnifulInventoryEvent;
use App\Models\OmnifulInwardingEvent;
use App\Models\OmnifulOrder;
use App\Models\OmnifulOrderEvent;
use App\Models\OmnifulProductEvent;
use App\Models\OmnifulPurchaseOrderEvent;
use App\Models\OmnifulReturnOrderEvent;
use App\Services\IntegrationDirectionService;
use App\Services\SapServiceLayerClient;

class WebhookRetryService
{
    /**
     * @return array{ok:bool,message:string}
     */
    public function retryOrderEvent(OmnifulOrderEvent $event): array
    {
        $order = OmnifulOrder::where('external_id', $event->external_id)->first();
        if ($order) {
            $order->sap_status = 'retrying';
            $order->sap_error = null;
            $order->save();
        }

        try {
            app(OrderWebhookService::class)->process($event);
            return ['ok' => true, 'message' => 'Order event retried successfully'];
        } catch (\Throwable $e) {
            if ($order) {
                $order->sap_status = 'failed';
                $order->sap_error = $e->getMessage();
                $order->save();
            }
            return ['ok' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * @return array{ok:bool,message:string}
     */
    public function retryLatestOrderEventForOrder(OmnifulOrder $order): array
    {
        $event = OmnifulOrderEvent::query()
            ->where('external_id', $order->external_id)
            ->orderByDesc('received_at')
            ->first();

        if (!$event) {
            return ['ok' => false, 'message' => 'No order event found for this order'];
        }

        return $this->retryOrderEvent($event);
    }

    /**
     * @return array{ok:bool,message:string}
     */
    public function retryReturnOrderEvent(OmnifulReturnOrderEvent $event): array
    {
        try {
            $event->sap_error = null;
            $event->save();
            app(ReturnOrderWebhookService::class)->process($event);
            return ['ok' => true, 'message' => 'Return order retried successfully'];
        } catch (\Throwable $e) {
            $event->sap_status = 'failed';
            $event->sap_error = $e->getMessage();
            $event->save();
            return ['ok' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * @return array{ok:bool,message:string}
     */
    public function retryPurchaseOrderEvent(OmnifulPurchaseOrderEvent $event): array
    {
        try {
            $event->sap_error = null;
            $event->save();
            app(PurchaseOrderWebhookService::class)->process($event);
            return ['ok' => true, 'message' => 'Purchase order retried successfully'];
        } catch (\Throwable $e) {
            $event->sap_status = 'failed';
            $event->sap_error = $e->getMessage();
            $event->save();
            return ['ok' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * @return array{ok:bool,message:string}
     */
    public function retryProductEvent(OmnifulProductEvent $event): array
    {
        try {
            $event->sap_error = null;
            $event->save();
            $client = app(SapServiceLayerClient::class);
            $eventName = (string) data_get($event->payload, 'event_name', '');
            $summary = $this->syncProductPayloadRows((array) ($event->payload ?? []), $client, $eventName);

            $event->sap_status = $summary['status'];
            $event->sap_item_code = $summary['item_code'];
            $event->sap_error = null;
            $event->save();

            return ['ok' => true, 'message' => 'Product event retried successfully'];
        } catch (\Throwable $e) {
            $event->sap_status = 'failed';
            $event->sap_error = $e->getMessage();
            $event->save();
            return ['ok' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * @return array{ok:bool,message:string}
     */
    public function retryInventoryEvent(OmnifulInventoryEvent $event): array
    {
        if (app(IntegrationDirectionService::class)->isSapToOmniful('inventory')) {
            $event->sap_status = 'ignored';
            $event->sap_error = 'Ignored: inventory sync direction is SAP -> Omniful';
            $event->save();

            return ['ok' => false, 'message' => 'Inventory direction is SAP -> Omniful'];
        }

        try {
            $event->sap_error = null;
            $event->save();
            if ($this->isStockTransferPayload((array) $event->payload)) {
                app(StockTransferWebhookService::class)->process($event);
            } else {
                app(InventoryWebhookService::class)->process($event);
            }

            return ['ok' => true, 'message' => 'Inventory event retried successfully'];
        } catch (\Throwable $e) {
            $event->sap_status = 'failed';
            $event->sap_error = $e->getMessage();
            $event->save();
            return ['ok' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * @return array{ok:bool,message:string}
     */
    public function retryInwardingEvent(OmnifulInwardingEvent $event): array
    {
        if (app(IntegrationDirectionService::class)->isSapToOmniful('inventory')) {
            $event->sap_status = 'ignored';
            $event->sap_error = 'Ignored: inventory sync direction is SAP -> Omniful';
            $event->save();

            return ['ok' => false, 'message' => 'Inventory direction is SAP -> Omniful'];
        }

        try {
            $event->sap_error = null;
            $event->save();
            app(InwardingWebhookService::class)->process($event);

            return ['ok' => true, 'message' => 'Inwarding event retried successfully'];
        } catch (\Throwable $e) {
            $event->sap_status = 'failed';
            $event->sap_error = $e->getMessage();
            $event->save();

            return ['ok' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function isStockTransferPayload(array $payload): bool
    {
        $eventName = strtolower((string) data_get($payload, 'event_name', ''));
        $action = strtolower((string) data_get($payload, 'action', ''));
        $entity = strtolower((string) data_get($payload, 'entity', ''));

        return str_contains($eventName, 'stock_transfer')
            || str_contains($eventName, 'stock-transfer')
            || str_contains($action, 'stock_transfer')
            || str_contains($action, 'stock-transfer')
            || str_contains($entity, 'stock_transfer')
            || str_contains($entity, 'stock-transfer');
    }

    /**
     * @param array<string,mixed> $data
     */
    private function isBundlePayload(array $data, string $eventName): bool
    {
        $eventName = strtolower(trim($eventName));
        if (str_contains($eventName, 'bundle') || str_contains($eventName, 'bom') || str_contains($eventName, 'kit')) {
            return true;
        }

        $componentCandidates = [
            data_get($data, 'bundle_items'),
            data_get($data, 'bundle_components'),
            data_get($data, 'components'),
            data_get($data, 'bom_items'),
            data_get($data, 'kit_items'),
        ];

        foreach ($componentCandidates as $value) {
            if (is_array($value) && $value !== []) {
                return true;
            }
        }

        return (bool) data_get($data, 'is_bundle', false);
    }

    /**
     * @param array<string,mixed> $payload
     * @return array{status:string,item_code:?string}
     */
    private function syncProductPayloadRows(array $payload, SapServiceLayerClient $client, string $eventName): array
    {
        $rows = $this->extractProductRows($payload);
        $statuses = [];
        $itemCodes = [];

        foreach ($rows as $row) {
            if ($this->isBundlePayload($row, $eventName)) {
                $sync = $client->syncBundleFromOmniful($row, $eventName);
            } else {
                $sync = $client->syncProductFromOmniful($row, $eventName);
            }

            $status = trim((string) ($sync['status'] ?? 'created'));
            if ($status !== '') {
                $statuses[] = $status;
            }

            $itemCode = trim((string) ($sync['item_code'] ?? $sync['bundle_code'] ?? ''));
            if ($itemCode !== '') {
                $itemCodes[] = $itemCode;
            }
        }

        $statuses = array_values(array_unique($statuses));
        $itemCodes = array_values(array_unique($itemCodes));
        $joinedCodes = $itemCodes !== [] ? implode(',', $itemCodes) : null;

        return [
            'status' => count($statuses) === 1 ? $statuses[0] : 'created',
            'item_code' => $joinedCodes !== null ? substr($joinedCodes, 0, 255) : null,
        ];
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<int,array<string,mixed>>
     */
    private function extractProductRows(array $payload): array
    {
        $rawData = data_get($payload, 'data', []);
        if (!is_array($rawData)) {
            return [];
        }

        if (array_is_list($rawData)) {
            $rows = [];
            foreach ($rawData as $row) {
                if (is_array($row)) {
                    $rows[] = $row;
                }
            }

            return $rows;
        }

        return [$rawData];
    }
}
