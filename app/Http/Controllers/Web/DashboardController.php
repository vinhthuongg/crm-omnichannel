<?php

namespace App\Http\Controllers\Web;

use App\Actions\Web\GetDashboardViewDataAction;
use App\Actions\Web\GetDashboardChartDataAction;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __invoke(Request $request, GetDashboardViewDataAction $action): View
    {
        return view('dashboard.index', $action->execute($request->user(), [
            'section' => $request->route('section', 'dashboard'),
            'search' => $request->string('q')->toString(),
            'period' => $request->string('period', 'week')->toString(),
            'activity_agent' => $request->string('activity_agent', 'all')->toString(),
            'activity_type' => $request->string('activity_type', 'all')->toString(),
            'activity_keyword' => $request->string('activity_keyword')->toString(),
            'notification_status' => $request->string('notification_status', 'all')->toString(),
            'notification_type' => $request->string('notification_type', 'all')->toString(),
            'notification_keyword' => $request->string('notification_keyword')->toString(),
        ]));
    }

    public function charts(Request $request, GetDashboardChartDataAction $action): JsonResponse
    {
        return response()->json($action->execute($request->user(), [
            'period' => $request->string('period', 'week')->toString(),
        ]));
    }

    public function updatePassword(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'current_password' => ['required', 'current_password'],
            'password' => ['required', 'confirmed', Password::defaults()],
        ]);

        $request->user()->forceFill([
            'password' => Hash::make($validated['password']),
        ])->save();

        return back()->with('settings_status', 'Đã đổi mật khẩu thành công.');
    }

    public function markNotificationRead(Request $request, string $notification): RedirectResponse
    {
        $request->user()->notifications()->whereKey($notification)->firstOrFail()->markAsRead();

        return back()->with('notification_status', 'Đã đánh dấu thông báo là đã đọc.');
    }

    public function markAllNotificationsRead(Request $request): RedirectResponse
    {
        DatabaseNotification::query()
            ->where('notifiable_type', $request->user()->getMorphClass())
            ->where('notifiable_id', $request->user()->getKey())
            ->whereNull('read_at')
            ->update(['read_at' => now()]);

        return back()->with('notification_status', 'Đã đánh dấu tất cả thông báo là đã đọc.');
    }
}
