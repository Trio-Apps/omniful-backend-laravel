<?php

namespace App\Http\Controllers\Webhooks;

use App\Models\OmnifulPurchaseOrderEvent;
use App\Services\Webhooks\PurchaseOrderWebhookService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class OmnifulPurchaseOrderWebhookController extends OmnifulWebhookBase
{
    public function __invoke(Request $request, PurchaseOrderWebhookService $service)
    {
        $result = $this->storeEvent($request, 'purchase-order', OmnifulPurchaseOrderEvent::class, false);

        if (isset($result['response'])) {
            return $result['response'];
        }

        /** @var OmnifulPurchaseOrderEvent $event */
        $event = $result['event'];
        $isDuplicate = (bool) ($result['duplicate'] ?? false);

        if ($isDuplicate && $event->sap_status !== null && $event->sap_status !== 'failed') {
            return response()->json(['status' => 'ok', 'id' => $event->id, 'duplicate' => true]);
        }

        try {
            $service->process($event);
        } catch (\Throwable $e) {
            $event->sap_status = 'failed';
            $event->sap_error = $e->getMessage();
            $event->save();
            Log::error('SAP PO sync failed', [
                'event_id' => $event->id,
                'external_id' => $event->external_id,
                'error' => $e->getMessage(),
            ]);
        }

        return response()->json(['status' => 'ok', 'id' => $event->id]);
    }
}
