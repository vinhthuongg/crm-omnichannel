<?php

use Illuminate\Support\Facades\Route;
use Modules\Text\Http\Controllers\TextAuthController;

Route::middleware(['auth', 'can:user.manage'])->group(function (): void {
    Route::get('auth/text', [TextAuthController::class, 'redirect'])->name('text.redirect');
    Route::get('auth/text/callback', [TextAuthController::class, 'callback'])->name('text.callback');
});
