<?php

namespace App\Http\Controllers\Web;

use App\Actions\Web\GetDashboardChartDataAction;
use App\Actions\Web\GetDashboardViewDataAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Web\UpdateFacebookFirstContactMenuRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;
use Modules\Facebook\Services\FacebookFirstContactMenuSettings;
use Modules\Notification\Services\NotificationManagementService;

class DashboardController extends Controller
{
    /** Nhận NotificationManagementService để đánh dấu notification đã đọc. */
    public function __construct(private readonly NotificationManagementService $notifications) {}

    /** Kiểm tra quyền khu vực rồi hiển thị dashboard với dữ liệu đã lọc. */
    public function __invoke(Request $request, GetDashboardViewDataAction $action): View|RedirectResponse
    {
        $section = (string) $request->route('section', 'dashboard');
        if (! $request->user()->can('user.manage') && ! in_array($section, ['notifications', 'settings'], true)) {
            return redirect()->route('crm.conversations');
        }

        return view('dashboard.index', $action->execute($request->user(), [
            'section' => $section,
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

    /** Trả dữ liệu biểu đồ hội thoại, kênh, nhãn và hoạt động khách hàng theo kỳ lọc. */
    public function charts(Request $request, GetDashboardChartDataAction $action): JsonResponse
    {
        return response()->json($action->execute($request->user(), [
            'period' => $request->string('period', 'week')->toString(),
        ]));
    }

    /** Xác thực mật khẩu hiện tại rồi băm và lưu mật khẩu mới cho tài khoản web. */
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

    /** Lưu menu carousel chào khách đầu tiên do Admin tùy chỉnh và áp dụng ngay cho hội thoại mới. */
    public function updateFacebookFirstContactMenu(
        UpdateFacebookFirstContactMenuRequest $request,
        FacebookFirstContactMenuSettings $settings,
    ): RedirectResponse {
        $settings->update($request->validated());

        return back()->with('settings_status', 'Đã cập nhật menu Messenger cho khách nhắn tin lần đầu.');
    }

    /** Đánh dấu một notification thuộc người dùng hiện tại là đã đọc. */
    public function markNotificationRead(Request $request, string $notification): RedirectResponse
    {
        $this->notifications->markRead($request->user(), $notification);

        return back()->with('notification_status', 'Đã đánh dấu thông báo là đã đọc.');
    }

    /** Đánh dấu toàn bộ notification chưa đọc của người dùng hiện tại là đã đọc. */
    public function markAllNotificationsRead(Request $request): RedirectResponse
    {
        $this->notifications->markAllRead($request->user());

        return back()->with('notification_status', 'Đã đánh dấu tất cả thông báo là đã đọc.');
    }
}
