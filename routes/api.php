<?php

use App\Http\Controllers\Api\IntegrationReadApiController;
use App\Http\Controllers\Webhooks\OmnifulInwardingWebhookController;
use App\Http\Controllers\Webhooks\OmnifulInventoryWebhookController;
use App\Http\Controllers\Webhooks\OmnifulOrderWebhookController;
use App\Http\Controllers\Webhooks\OmnifulPurchaseOrderWebhookController;
use App\Http\Controllers\Webhooks\OmnifulProductWebhookController;
use App\Http\Controllers\Webhooks\OmnifulReturnOrderWebhookController;
use App\Http\Controllers\Webhooks\OmnifulStockTransferWebhookController;
use Illuminate\Support\Facades\Route;

Route::post('webhooks/omniful/order/whk_7d4c91b2f6e84a9bb3d12c7e', OmnifulOrderWebhookController::class);
Route::post('webhooks/omniful/return-order/whk_3a6e2d9f4c7841b8a5d0e33f', OmnifulReturnOrderWebhookController::class);
Route::post('webhooks/omniful/purchase-order/whk_8c1f5e27b94d4a6f9e2c301d', OmnifulPurchaseOrderWebhookController::class);
Route::post('webhooks/omniful/product/whk_5e2a7c19d8434fb6a0c21d9e', OmnifulProductWebhookController::class);
Route::post('webhooks/omniful/inwarding/whk_1f9b3d6e24c8475aa2e0b91c', OmnifulInwardingWebhookController::class);
Route::post('webhooks/omniful/inventory/whk_4b8e1c7d29f34a6ab5d203ef', OmnifulInventoryWebhookController::class);
Route::post('webhooks/omniful/stock-transfer/whk_9a2d4f1c6b834e7da0c35b8f', OmnifulStockTransferWebhookController::class);

Route::prefix('integration/sap')->group(function (): void {
    Route::get('resources', [IntegrationReadApiController::class, 'resources']);
    Route::get('resources/{resource}', [IntegrationReadApiController::class, 'resourceIndex']);
    Route::get('sync-status', [IntegrationReadApiController::class, 'syncStatus']);
});
