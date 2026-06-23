<?php

use Illuminate\Support\Facades\Route;
use Modules\Message\Http\Controllers\MessageController;

Route::middleware('auth:sanctum')->prefix('conversations/{conversation}/messages')->group(function (): void {
    Route::get('/', [MessageController::class, 'index']);
    Route::post('/', [MessageController::class, 'store']);
});