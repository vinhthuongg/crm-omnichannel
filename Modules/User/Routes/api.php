<?php

use Illuminate\Support\Facades\Route;
use Modules\User\Http\Controllers\UserController;

Route::middleware('auth:sanctum')->apiResource('users', UserController::class)->except(['destroy']);
