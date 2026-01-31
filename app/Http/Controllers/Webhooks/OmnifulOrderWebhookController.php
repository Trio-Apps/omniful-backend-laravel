<?php

namespace App\Http\Controllers\Webhooks;

use Illuminate\Http\Request;

class OmnifulOrderWebhookController extends OmnifulWebhookBase
{
    public function __invoke(Request $request)
    {
        return $this->handle($request, 'order', \App\Models\OmnifulOrderEvent::class, true);
    }
}
