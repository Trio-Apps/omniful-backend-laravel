<?php

namespace App\Http\Controllers\Webhooks;

use App\Jobs\ProcessOmnifulOrderEvent;
use App\Models\OmnifulOrder;
use App\Models\OmnifulOrderEvent;
use App\Services\Webhooks\OrderWebhookService;
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

        $service = app(OrderWebhookService::class);
        $classification = $service->classifyEventForProcessing($event);
        if (!($classification['queue'] ?? false)) {
            $result = $service->applyNoOpEventOutcome($event);

            return response()->json([
                'status' => 'ok',
                'id' => $event->id,
                'ignored' => ($result['action'] ?? '') === 'ignored',
                'message' => $result['message'] ?? 'No SAP action required',
            ]);
        }

        if (!empty($event->external_id)) {
            OmnifulOrder::where('external_id', $event->external_id)
                ->where(function ($query) {
                    $query
                        ->where(function ($docEntryQuery) {
                            $docEntryQuery->whereNull('sap_doc_entry')
                                ->orWhere('sap_doc_entry', '');
                        })
                        ->where(function ($docNumQuery) {
                            $docNumQuery->whereNull('sap_doc_num')
                                ->orWhere('sap_doc_num', '');
                        });
                })
                ->update([
                    'sap_status' => 'pending',
                    'sap_error' => null,
                ]);
        }

        ProcessOmnifulOrderEvent::dispatch($event->id);

        return response()->json(['status' => 'queued', 'id' => $event->id]);
    }
}
