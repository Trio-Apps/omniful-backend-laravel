<?php

namespace App\Http\Controllers\Webhooks;

use Illuminate\Http\Request;

class OmnifulInwardingWebhookController extends OmnifulWebhookBase
{
    public function __invoke(Request $request)
    {
        return $this->handle($request, 'inwarding', \App\Models\OmnifulInwardingEvent::class, false);
    }
}
