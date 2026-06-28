<?php

use Illuminate\Support\Facades\Route;
use Modules\Conversation\Http\Controllers\ConversationController;

Route::middleware('auth:sanctum')->prefix('conversations')->group(function (): void {
    Route::get('/', [ConversationController::class, 'index']);
    Route::get('{conversation}', [ConversationController::class, 'show']);
    Route::post('{conversation}/assign', [ConversationController::class, 'assign']);
    Route::post('{conversation}/transfer', [ConversationController::class, 'transfer']);
    Route::post('{conversation}/release', [ConversationController::class, 'release']);
    Route::post('{conversation}/resolve', [ConversationController::class, 'resolve']);
    Route::post('{conversation}/close', [ConversationController::class, 'close']);
    Route::post('{conversation}/reopen', [ConversationController::class, 'reopen']);
    Route::post('{conversation}/tags', [ConversationController::class, 'tag']);
});
