<?php

use Illuminate\Support\Facades\Route;
use Modules\Zalo\Http\Controllers\ZaloWebhookController;

Route::post('webhook/zalo', ZaloWebhookController::class);