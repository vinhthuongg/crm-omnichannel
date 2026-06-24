<?php

use Illuminate\Support\Facades\Route;
use Modules\Facebook\Http\Controllers\FacebookAuthController;
use Modules\Facebook\Http\Controllers\FacebookPageController;

Route::middleware(['auth', 'can:user.manage'])->group(function (): void {
    Route::get('auth/facebook', [FacebookAuthController::class, 'redirect'])->name('facebook.redirect');
    Route::get('auth/facebook/callback', [FacebookAuthController::class, 'callback'])->name('facebook.callback');
    Route::get('facebook/pages', [FacebookPageController::class, 'index'])->name('facebook.pages');
    Route::post('facebook/connect-page', [FacebookPageController::class, 'connect'])->name('facebook.connect-page');
    Route::post('facebook/pages/{facebookPage}/sync-messages', [FacebookPageController::class, 'sync'])
        ->middleware('facebook.page.connected')
        ->name('facebook.pages.sync-messages');
});
