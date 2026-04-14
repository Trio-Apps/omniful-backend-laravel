<?php

namespace App\Http\Controllers\Webhooks;

use App\Jobs\ProcessOmnifulOrderEvent;
use App\Models\OmnifulOrder;
use App\Models\OmnifulOrderEvent;
use Illuminate\Http\Request;

class OmnifulOrderWebhookController extends OmnifulWebhookBase
{
    public function __invoke(Request $request)
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
                ->update([
                    'sap_status' => 'pending',
                    'sap_error' => null,
                ]);
        }

        ProcessOmnifulOrderEvent::dispatch($event->id);

        return response()->json(['status' => 'queued', 'id' => $event->id]);
    }
}
