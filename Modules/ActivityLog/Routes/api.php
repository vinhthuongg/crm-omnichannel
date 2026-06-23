<?php

use Illuminate\Support\Facades\Route;
use Modules\ActivityLog\Http\Controllers\ActivityLogController;

Route::middleware('auth:sanctum')->get('activity-logs', [ActivityLogController::class, 'index']);