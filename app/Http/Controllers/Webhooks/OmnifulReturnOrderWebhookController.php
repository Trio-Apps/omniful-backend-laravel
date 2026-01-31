<?php

namespace App\Http\Controllers\Webhooks;

use Illuminate\Http\Request;

class OmnifulReturnOrderWebhookController extends OmnifulWebhookBase
{
    public function __invoke(Request $request)
    {
        return $this->handle($request, 'return-order', \App\Models\OmnifulReturnOrderEvent::class, true);
    }
}
