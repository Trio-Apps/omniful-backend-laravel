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
            $rawData = data_get($event->payload, 'data', []);
            $data = is_array($rawData) ? ($rawData[0] ?? []) : $rawData;

            $client = app(SapServiceLayerClient::class);
            $sync = $client->syncProductFromOmniful($data, (string) data_get($event->payload, 'event_name', ''));

            $event->sap_status = $sync['status'] ?? 'created';
            $event->sap_item_code = $sync['item_code'] ?? null;
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
}
