<?php

namespace App\Http\Controllers\Webhooks;

use App\Models\OmnifulInventoryEvent;
use App\Services\Webhooks\InventoryWebhookService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class OmnifulInventoryWebhookController extends OmnifulWebhookBase
{
    public function __invoke(Request $request, InventoryWebhookService $service)
    {
        $result = $this->storeEvent($request, 'inventory', OmnifulInventoryEvent::class, false);

        if (isset($result['response'])) {
            return $result['response'];
        }

        /** @var OmnifulInventoryEvent $event */
        $event = $result['event'];

        try {
            $service->process($event);
        } catch (\Throwable $e) {
            $event->sap_status = 'failed';
            $event->sap_error = $e->getMessage();
            $event->save();

            Log::error('SAP inventory sync failed', [
                'event_id' => $event->id,
                'external_id' => $event->external_id,
                'error' => $e->getMessage(),
            ]);
        }

        return response()->json(['status' => 'ok', 'id' => $event->id]);
    }
}

