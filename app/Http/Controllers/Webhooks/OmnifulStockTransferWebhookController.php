<?php

namespace App\Http\Controllers\Webhooks;

use Illuminate\Http\Request;

class OmnifulStockTransferWebhookController extends OmnifulWebhookBase
{
    public function __invoke(Request $request)
    {
        return $this->handle($request, 'stock-transfer-request', \App\Models\OmnifulInventoryEvent::class, false);
    }
}
