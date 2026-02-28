<?php

namespace App\Http\Controllers\Webhooks;

use App\Models\OmnifulProductEvent;
use App\Services\SapServiceLayerClient;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class OmnifulProductWebhookController extends OmnifulWebhookBase
{
    public function __invoke(Request $request)
    {
        $result = $this->storeEvent($request, 'product', OmnifulProductEvent::class, false);

        if (isset($result['response'])) {
            return $result['response'];
        }

        /** @var OmnifulProductEvent $event */
        $event = $result['event'];
        $isDuplicate = (bool) ($result['duplicate'] ?? false);

        if ($isDuplicate && $event->sap_status !== null && $event->sap_status !== 'failed') {
            return response()->json(['status' => 'ok', 'id' => $event->id, 'duplicate' => true]);
        }

        try {
            $client = app(SapServiceLayerClient::class);
            $eventName = (string) data_get($event->payload, 'event_name', '');
            $summary = $this->syncProductPayloadRows((array) ($event->payload ?? []), $client, $eventName);

            $event->sap_status = $summary['status'];
            $event->sap_item_code = $summary['item_code'];
            $event->sap_error = null;
            $event->save();
        } catch (\Throwable $e) {
            $event->sap_status = 'failed';
            $event->sap_error = $e->getMessage();
            $event->save();
            Log::error('SAP product sync failed', [
                'event_id' => $event->id,
                'external_id' => $event->external_id,
                'error' => $e->getMessage(),
            ]);
        }

        return response()->json(['status' => 'ok', 'id' => $event->id]);
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
}
