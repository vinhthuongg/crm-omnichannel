<?php

use Illuminate\Support\Facades\Route;
use Modules\Botpress\Http\Controllers\BotpressAdminController;

Route::middleware('auth')->get('admin/chatbot', [BotpressAdminController::class, 'launch'])
    ->name('botpress.admin.launch');

Route::get('chatbot-admin/{token}', [BotpressAdminController::class, 'index'])
    ->name('botpress.admin.public');

Route::get('chatbot-admin/{token}/conversations/{conversation}', [BotpressAdminController::class, 'show'])
    ->name('botpress.admin.conversations.show');

Route::post('chatbot-admin/{token}/knowledge', [BotpressAdminController::class, 'storeKnowledge'])
    ->name('botpress.admin.knowledge.store');
