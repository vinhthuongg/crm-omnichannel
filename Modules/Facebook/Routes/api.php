<?php

use Illuminate\Support\Facades\Route;
use Modules\Facebook\Http\Controllers\FacebookWebhookController;

Route::get('webhook/facebook', [FacebookWebhookController::class, 'verify']);
Route::post('webhook/facebook', FacebookWebhookController::class);
