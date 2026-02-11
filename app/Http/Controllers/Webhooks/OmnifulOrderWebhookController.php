<?php

namespace App\Http\Controllers\Webhooks;

use App\Models\OmnifulOrder;
use App\Models\OmnifulOrderEvent;
use App\Services\Webhooks\OrderWebhookService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class OmnifulOrderWebhookController extends OmnifulWebhookBase
{
    public function __invoke(Request $request, OrderWebhookService $service)
    {
        $result = $this->storeEvent($request, 'order', OmnifulOrderEvent::class, true);

        if (isset($result['response'])) {
            return $result['response'];
        }

        /** @var OmnifulOrderEvent $event */
        $event = $result['event'];
        $isDuplicate = (bool) ($result['duplicate'] ?? false);
        if ($isDuplicate) {
            return response()->json(['status' => 'ok', 'id' => $event->id, 'duplicate' => true]);
        }

        if (!empty($event->external_id)) {
            OmnifulOrder::where('external_id', $event->external_id)
                ->whereNull('sap_status')
                ->update([
                    'sap_status' => 'pending',
                    'sap_error' => null,
                ]);
        }

        try {
            $service->process($event);
        } catch (\Throwable $e) {
            Log::error('SAP order sync failed', [
                'event_id' => $event->id,
                'external_id' => $event->external_id,
                'error' => $e->getMessage(),
            ]);

            if (!empty($event->external_id)) {
                OmnifulOrder::where('external_id', $event->external_id)->update([
                    'sap_status' => 'failed',
                    'sap_error' => $e->getMessage(),
                ]);
            }
        }

        return response()->json(['status' => 'ok', 'id' => $event->id]);
    }
}
