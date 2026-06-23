<?php

use Illuminate\Support\Facades\Route;
use Modules\Notification\Http\Controllers\NotificationController;

Route::middleware('auth:sanctum')->prefix('notifications')->group(function (): void {
    Route::get('/', [NotificationController::class, 'index']);
    Route::post('{id}/read', [NotificationController::class, 'markAsRead']);
});