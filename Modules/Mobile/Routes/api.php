<?php

use Illuminate\Support\Facades\Route;
use Modules\Mobile\Http\Controllers\MobileApiController;

Route::middleware('auth:sanctum')->prefix('mobile')->group(function (): void {
    Route::get('me', [MobileApiController::class, 'me']);
    Route::get('bootstrap', [MobileApiController::class, 'bootstrap']);

    Route::get('conversations', [MobileApiController::class, 'conversations']);
    Route::get('conversations/{conversation}', [MobileApiController::class, 'conversation']);
    Route::get('conversations/{conversation}/messages', [MobileApiController::class, 'messages']);
    Route::post('conversations/{conversation}/messages', [MobileApiController::class, 'sendMessage']);
    Route::post('conversations/{conversation}/read', [MobileApiController::class, 'markRead']);
    Route::post('conversations/{conversation}/assign', [MobileApiController::class, 'assign']);

    Route::get('customers', [MobileApiController::class, 'customers']);
    Route::get('notifications', [MobileApiController::class, 'notifications']);
    Route::post('notifications/{notification}/read', [MobileApiController::class, 'markNotificationRead']);
    Route::post('notifications/read-all', [MobileApiController::class, 'markAllNotificationsRead']);
});
