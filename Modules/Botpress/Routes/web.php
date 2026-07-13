<?php

use Illuminate\Support\Facades\Route;
use Modules\Botpress\Http\Controllers\BotpressAdminController;

Route::middleware('auth')->group(function (): void {
    Route::get('admin/chatbot', [BotpressAdminController::class, 'index'])
        ->name('botpress.admin.index');

    Route::get('admin/chatbot/conversations/{conversation}', [BotpressAdminController::class, 'show'])
        ->name('botpress.admin.conversations.show');

    Route::post('admin/chatbot/knowledge', [BotpressAdminController::class, 'storeKnowledge'])
        ->name('botpress.admin.knowledge.store');
});
