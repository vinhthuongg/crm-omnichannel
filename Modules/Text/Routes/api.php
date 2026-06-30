<?php

use Illuminate\Support\Facades\Route;
use Modules\Text\Http\Controllers\TextWebhookController;

Route::post('webhook/text', TextWebhookController::class);
