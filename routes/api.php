<?php

use App\Http\Controllers\Webhooks\OmnifulInwardingWebhookController;
use App\Http\Controllers\Webhooks\OmnifulInventoryWebhookController;
use App\Http\Controllers\Webhooks\OmnifulOrderWebhookController;
use App\Http\Controllers\Webhooks\OmnifulPurchaseOrderWebhookController;
use App\Http\Controllers\Webhooks\OmnifulProductWebhookController;
use App\Http\Controllers\Webhooks\OmnifulReturnOrderWebhookController;
use App\Http\Controllers\Webhooks\OmnifulStockTransferWebhookController;
use Illuminate\Support\Facades\Route;

Route::post('webhooks/omniful/order', OmnifulOrderWebhookController::class);
Route::post('webhooks/omniful/return-order', OmnifulReturnOrderWebhookController::class);
Route::post('webhooks/omniful/purchase-order', OmnifulPurchaseOrderWebhookController::class);
Route::post('webhooks/omniful/product', OmnifulProductWebhookController::class);
Route::post('webhooks/omniful/inwarding', OmnifulInwardingWebhookController::class);
Route::post('webhooks/omniful/inventory', OmnifulInventoryWebhookController::class);
Route::post('webhooks/omniful/stock-transfer', OmnifulStockTransferWebhookController::class);
