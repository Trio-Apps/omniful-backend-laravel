<?php

namespace App\Http\Controllers\Webhooks;

use App\Models\OmnifulInwardingEvent;
use App\Services\IntegrationDirectionService;
use App\Services\Webhooks\InwardingWebhookService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class OmnifulInwardingWebhookController extends OmnifulWebhookBase
{
    public function __invoke(Request $request, InwardingWebhookService $service)
    {
        $result = $this->storeEvent($request, 'inwarding', OmnifulInwardingEvent::class, false);

        if (isset($result['response'])) {
            return $result['response'];
        }

        /** @var OmnifulInwardingEvent $event */
        $event = $result['event'];
        $isDuplicate = (bool) ($result['duplicate'] ?? false);

        if ($isDuplicate && $event->sap_status !== null && $event->sap_status !== 'failed') {
            return response()->json(['status' => 'ok', 'id' => $event->id, 'duplicate' => true]);
        }

        if (app(IntegrationDirectionService::class)->isSapToOmniful('inventory')) {
            $event->sap_status = 'ignored';
            $event->sap_error = 'Ignored: inventory sync direction is SAP -> Omniful';
            $event->save();

            return response()->json(['status' => 'ok', 'id' => $event->id, 'ignored' => true]);
        }

        try {
            $service->process($event);
        } catch (\Throwable $e) {
            $event->sap_status = 'failed';
            $event->sap_error = $e->getMessage();
            $event->save();

            Log::error('SAP inwarding sync failed', [
                'event_id' => $event->id,
                'external_id' => $event->external_id,
                'error' => $e->getMessage(),
            ]);
        }

        return response()->json(['status' => 'ok', 'id' => $event->id]);
    }
}
