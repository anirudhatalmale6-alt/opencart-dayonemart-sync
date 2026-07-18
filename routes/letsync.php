<?php

use App\Http\Controllers\Letsync\LetsyncWebhookController;
use Illuminate\Support\Facades\Route;

Route::prefix('webhooks')->group(function (): void {
    Route::post('product/handle', [LetsyncWebhookController::class, 'product']);
    Route::post('category/handle', [LetsyncWebhookController::class, 'category']);
    Route::post('customer/handle', [LetsyncWebhookController::class, 'customer']);
    Route::post('order/handle', [LetsyncWebhookController::class, 'order']);
});
