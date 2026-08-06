<?php

use Illuminate\Support\Facades\Route;
use Modules\Dashboard\Http\Controllers\DashboardController;

Route::middleware('auth:sanctum')->get('dashboard/overview', [DashboardController::class, 'overview']);
