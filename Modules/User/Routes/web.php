<?php

use Illuminate\Support\Facades\Route;
use Modules\User\Http\Controllers\UserController;

Route::middleware(['auth', 'can:user.manage'])->group(function (): void {
    Route::post('agents', [UserController::class, 'storeWeb'])->name('crm.agents.store');
});
