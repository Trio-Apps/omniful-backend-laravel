<?php

namespace App\Http\Controllers\Webhooks;

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

        try {
            $service->process($event);
        } catch (\Throwable $e) {
            Log::error('SAP order sync failed', [
                'event_id' => $event->id,
                'external_id' => $event->external_id,
                'error' => $e->getMessage(),
            ]);
        }

        return response()->json(['status' => 'ok', 'id' => $event->id]);
    }
}
