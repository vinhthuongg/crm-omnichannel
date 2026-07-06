<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Web\AuthController;
use App\Http\Controllers\Web\DashboardController;
use App\Http\Controllers\Web\MessengerController;
use App\Http\Controllers\Web\WorkShiftController;
use Modules\Customer\Http\Controllers\CustomerPageController;

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
        Route::get('conversations/messages/stream', [MessengerController::class, 'inboxMessageStream'])->name('crm.conversations.messages.stream.inbox');
        Route::delete('conversations/{conversation}', [MessengerController::class, 'destroy'])->name('crm.conversations.destroy');
        Route::get('conversations/{conversation}/messages/stream', [MessengerController::class, 'messageStream'])->name('crm.conversations.messages.stream');
        Route::get('conversations/{conversation}/messages', [MessengerController::class, 'messages'])->name('crm.conversations.messages.index');
        Route::get('conversations/{conversation}/reply-suggestions', [MessengerController::class, 'replySuggestions'])->name('crm.conversations.reply-suggestions');
        Route::delete('conversations/{conversation}/messages', [MessengerController::class, 'clearMessages'])->name('crm.conversations.messages.clear');
        Route::get('conversations/{conversation}', [MessengerController::class, 'show'])->name('crm.conversations.show');
        Route::post('conversations/{conversation}/read', [MessengerController::class, 'markRead'])->name('crm.conversations.read');
        Route::patch('conversations/{conversation}/customer', [MessengerController::class, 'updateCustomer'])->name('crm.conversations.customer.update');
        Route::post('conversations/{conversation}/customer-notes', [MessengerController::class, 'storeCustomerNote'])->name('crm.conversations.customer-notes.store');
        Route::post('conversations/{conversation}/customer-tags', [MessengerController::class, 'storeCustomerTag'])->name('crm.conversations.customer-tags.store');
        Route::post('conversations/{conversation}/attachments', [MessengerController::class, 'uploadAttachments'])->name('crm.conversations.attachments.store');
        Route::post('conversations/{conversation}/claim', [MessengerController::class, 'claim'])->name('crm.conversations.claim');
        Route::post('conversations/{conversation}/assign', [MessengerController::class, 'assign'])->name('crm.conversations.assign');
        Route::post('conversations/{conversation}/tags', [MessengerController::class, 'tags'])->name('crm.conversations.tags.store');
        Route::get('conversation-tags', [MessengerController::class, 'conversationTags'])->name('crm.conversation-tags.index');
        Route::post('conversation-tags', [MessengerController::class, 'storeConversationTag'])->name('crm.conversation-tags.store');
        Route::patch('conversation-tags/{tag}', [MessengerController::class, 'updateConversationTag'])->name('crm.conversation-tags.update');
        Route::delete('conversation-tags/{tag}', [MessengerController::class, 'destroyConversationTag'])->name('crm.conversation-tags.destroy');
        Route::post('conversations/{conversation}/messages', [MessengerController::class, 'send'])->name('crm.conversations.messages.store');
        Route::patch('conversations/{conversation}/messages/{message}/recall', [MessengerController::class, 'recallMessage'])->name('crm.conversations.messages.recall');
        Route::delete('conversations/{conversation}/messages/{message}', [MessengerController::class, 'deleteMessage'])->name('crm.conversations.messages.delete');
    });
    Route::get('customers', CustomerPageController::class)->name('crm.customers');
    Route::get('agents', DashboardController::class)->defaults('section', 'agents')->name('crm.agents');
    Route::get('channels', DashboardController::class)->defaults('section', 'channels')->name('crm.channels');
    Route::get('admin/database', function () {
        abort_unless(request()->user()?->can('user.manage'), 403);

        return redirect(\Illuminate\Support\Facades\URL::temporarySignedRoute(
            'crm.admin.database',
            now()->addMinutes(2),
            ['nonce' => (string) \Illuminate\Support\Str::uuid()]
        ));
    })->name('crm.admin.database.launch');
    Route::get('admin/database/{nonce}', function (string $nonce) {
        abort_unless(request()->user()?->can('user.manage'), 403);

        return redirect('/phpmyadmin');
    })->middleware('signed')->name('crm.admin.database');
    Route::get('reports', DashboardController::class)->defaults('section', 'reports')->name('crm.reports');
    Route::get('activity', DashboardController::class)->defaults('section', 'activity')->name('crm.activity');
    Route::get('notifications', DashboardController::class)->defaults('section', 'notifications')->name('crm.notifications');
    Route::patch('notifications/read-all', [DashboardController::class, 'markAllNotificationsRead'])->name('crm.notifications.read-all');
    Route::patch('notifications/{notification}/read', [DashboardController::class, 'markNotificationRead'])->name('crm.notifications.read');
    Route::get('settings', DashboardController::class)->defaults('section', 'settings')->name('crm.settings');
    Route::patch('settings/password', [DashboardController::class, 'updatePassword'])->name('crm.settings.password.update');
    Route::get('work-shifts', [WorkShiftController::class, 'index'])->name('work-shifts.index');
    Route::post('work-shifts', [WorkShiftController::class, 'store'])->name('work-shifts.store');
    Route::put('work-shifts/{workShift}', [WorkShiftController::class, 'update'])->name('work-shifts.update');
    Route::delete('work-shifts/{workShift}', [WorkShiftController::class, 'destroy'])->name('work-shifts.destroy');
    Route::post('logout', [AuthController::class, 'destroy'])->name('logout');
});
