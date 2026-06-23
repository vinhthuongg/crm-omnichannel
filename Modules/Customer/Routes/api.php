<?php

use Illuminate\Support\Facades\Route;
use Modules\Customer\Http\Controllers\CustomerController;

Route::middleware('auth:sanctum')->apiResource('customers', CustomerController::class)->except(['destroy']);