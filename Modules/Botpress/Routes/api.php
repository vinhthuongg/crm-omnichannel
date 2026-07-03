<?php

use Illuminate\Support\Facades\Route;
use Modules\Botpress\Http\Controllers\BotpressWebhookController;

Route::post('webhook/botpress', BotpressWebhookController::class)->name('botpress.callback');
