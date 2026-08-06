<?php

use Illuminate\Support\Facades\Route;
use Modules\Mobile\Http\Controllers\MobileConversationController;
use Modules\Mobile\Http\Controllers\MobileConversationStreamController;
use Modules\Mobile\Http\Controllers\MobileDeviceController;
use Modules\Mobile\Http\Controllers\MobileNotificationController;
use Modules\Mobile\Http\Controllers\MobileWorkShiftManagementController;
use Modules\Mobile\Http\Controllers\MobileCustomerController;
use Modules\Mobile\Http\Controllers\MobileAgentPerformanceController;
use Modules\Mobile\Http\Controllers\MobileBootstrapController;

Route::middleware('auth:sanctum')->prefix('mobile')->group(function (): void {
    Route::get('me', [MobileBootstrapController::class, 'me']);
    Route::get('bootstrap', [MobileBootstrapController::class, 'bootstrap']);
    Route::post('broadcasting/auth', [MobileBootstrapController::class, 'broadcastAuth']);
    Route::post('devices/fcm-token', [MobileDeviceController::class, 'storeFcmToken']);
    Route::delete('devices/fcm-token', [MobileDeviceController::class, 'destroyFcmToken']);

    Route::get('conversations', [MobileConversationController::class, 'conversations']);
    Route::get('conversations/stream', [MobileConversationStreamController::class, 'inbox']);
    Route::get('conversations/{conversation}', [MobileConversationController::class, 'conversation']);
    Route::get('conversations/{conversation}/messages', [MobileConversationController::class, 'messages']);
    Route::get('conversations/{conversation}/messages/stream', [MobileConversationStreamController::class, 'conversation']);
    Route::post('conversations/{conversation}/messages', [MobileConversationController::class, 'sendMessage']);
    Route::post('conversations/{conversation}/read', [MobileConversationController::class, 'markRead']);
    Route::post('conversations/{conversation}/assign', [MobileConversationController::class, 'assign']);

    Route::get('agents/performance', [MobileAgentPerformanceController::class, 'index']);
    Route::get('agents/{agent}/performance', [MobileAgentPerformanceController::class, 'show']);

    Route::get('work-shifts/overview', [MobileWorkShiftManagementController::class, 'overview']);
    Route::get('work-shifts/current', [MobileWorkShiftManagementController::class, 'current']);
    Route::get('work-shifts', [MobileWorkShiftManagementController::class, 'index']);
    Route::post('work-shifts', [MobileWorkShiftManagementController::class, 'store']);
    Route::get('work-shifts/{workShift}', [MobileWorkShiftManagementController::class, 'show']);
    Route::put('work-shifts/{workShift}', [MobileWorkShiftManagementController::class, 'update']);
    Route::delete('work-shifts/{workShift}', [MobileWorkShiftManagementController::class, 'destroy']);

    Route::get('customers', [MobileCustomerController::class, 'index']);
    Route::get('customers/tags', [MobileCustomerController::class, 'tags']);
    Route::post('customers/{customer}/tags', [MobileCustomerController::class, 'attachTag']);
    Route::delete('customers/{customer}/tags/{tagId}', [MobileCustomerController::class, 'detachTag']);
    Route::post('customers/{customer}/potential', [MobileCustomerController::class, 'markPotential']);
    Route::delete('customers/{customer}/potential', [MobileCustomerController::class, 'unmarkPotential']);
    Route::get('customers/{customer}', [MobileCustomerController::class, 'show']);
    Route::get('notifications', [MobileNotificationController::class, 'index']);
    Route::post('notifications/{notification}/read', [MobileNotificationController::class, 'markRead']);
    Route::post('notifications/read-all', [MobileNotificationController::class, 'markAllRead']);
});
