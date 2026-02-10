<?php

namespace App\Http\Controllers\Webhooks;

use App\Models\OmnifulReturnOrderEvent;
use App\Services\Webhooks\ReturnOrderWebhookService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class OmnifulReturnOrderWebhookController extends OmnifulWebhookBase
{
    public function __invoke(Request $request, ReturnOrderWebhookService $service)
    {
        $result = $this->storeEvent($request, 'return-order', OmnifulReturnOrderEvent::class, true);

        if (isset($result['response'])) {
            return $result['response'];
        }

        /** @var OmnifulReturnOrderEvent $event */
        $event = $result['event'];

        try {
            $service->process($event);
        } catch (\Throwable $e) {
            $event->sap_status = 'failed';
            $event->sap_error = $e->getMessage();
            $event->save();

            Log::error('SAP return order sync failed', [
                'event_id' => $event->id,
                'external_id' => $event->external_id,
                'error' => $e->getMessage(),
            ]);
        }

        return response()->json(['status' => 'ok', 'id' => $event->id]);
    }
}

