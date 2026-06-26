<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Web\AuthController;
use App\Http\Controllers\Web\DashboardController;
use App\Http\Controllers\Web\MessengerController;
use App\Http\Controllers\Web\WorkShiftController;

foreach (glob(base_path('Modules/*/Routes/web.php')) as $routeFile) {
    require $routeFile;
}

Route::get('/', function () {
    return redirect()->route('dashboard');
});

Route::middleware('guest')->group(function (): void {
    Route::get('login', [AuthController::class, 'create'])->name('login');
    Route::post('login', [AuthController::class, 'store'])->name('login.store');
});

Route::middleware('auth')->group(function (): void {
    Route::get('dashboard/charts', [DashboardController::class, 'charts'])->name('dashboard.charts');
    Route::get('dashboard', DashboardController::class)->name('dashboard');
    Route::middleware('omnichannel.connected')->group(function (): void {
        Route::get('messenger', [MessengerController::class, 'index'])->name('messenger.index');
        Route::get('conversations', [MessengerController::class, 'index'])->name('crm.conversations');
        Route::get('conversations/{conversation}/messages/stream', [MessengerController::class, 'messageStream'])->name('crm.conversations.messages.stream');
        Route::get('conversations/{conversation}/messages', [MessengerController::class, 'messages'])->name('crm.conversations.messages.index');
        Route::delete('conversations/{conversation}/messages', [MessengerController::class, 'clearMessages'])->name('crm.conversations.messages.clear');
        Route::get('conversations/{conversation}', [MessengerController::class, 'show'])->name('crm.conversations.show');
        Route::post('conversations/{conversation}/read', [MessengerController::class, 'markRead'])->name('crm.conversations.read');
        Route::post('conversations/{conversation}/customer-notes', [MessengerController::class, 'storeCustomerNote'])->name('crm.conversations.customer-notes.store');
        Route::post('conversations/{conversation}/customer-tags', [MessengerController::class, 'storeCustomerTag'])->name('crm.conversations.customer-tags.store');
        Route::post('conversations/{conversation}/attachments', [MessengerController::class, 'uploadAttachments'])->name('crm.conversations.attachments.store');
        Route::post('conversations/{conversation}/claim', [MessengerController::class, 'claim'])->name('crm.conversations.claim');
        Route::post('conversations/{conversation}/tags', [MessengerController::class, 'tags'])->name('crm.conversations.tags.store');
        Route::post('conversations/{conversation}/messages', [MessengerController::class, 'send'])->name('crm.conversations.messages.store');
        Route::patch('conversations/{conversation}/messages/{message}/recall', [MessengerController::class, 'recallMessage'])->name('crm.conversations.messages.recall');
        Route::delete('conversations/{conversation}/messages/{message}', [MessengerController::class, 'deleteMessage'])->name('crm.conversations.messages.delete');
    });
    Route::get('customers', DashboardController::class)->defaults('section', 'customers')->name('crm.customers');
    Route::get('agents', DashboardController::class)->defaults('section', 'agents')->name('crm.agents');
    Route::get('channels', DashboardController::class)->defaults('section', 'channels')->name('crm.channels');
    Route::get('reports', DashboardController::class)->defaults('section', 'reports')->name('crm.reports');
    Route::get('activity', DashboardController::class)->defaults('section', 'activity')->name('crm.activity');
    Route::get('notifications', DashboardController::class)->defaults('section', 'notifications')->name('crm.notifications');
    Route::get('settings', DashboardController::class)->defaults('section', 'settings')->name('crm.settings');
    Route::get('work-shifts', [WorkShiftController::class, 'index'])->name('work-shifts.index');
    Route::post('work-shifts', [WorkShiftController::class, 'store'])->name('work-shifts.store');
    Route::put('work-shifts/{workShift}', [WorkShiftController::class, 'update'])->name('work-shifts.update');
    Route::delete('work-shifts/{workShift}', [WorkShiftController::class, 'destroy'])->name('work-shifts.destroy');
    Route::post('logout', [AuthController::class, 'destroy'])->name('logout');
});
