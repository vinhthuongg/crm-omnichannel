<?php

namespace Modules\Mobile\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Notification\Services\MobileNotificationService;
use Modules\Shared\Http\Controllers\ApiController;

class MobileNotificationController extends ApiController
{
    /** Nhận dịch vụ đọc và cập nhật trạng thái notification cho API mobile. */
    public function __construct(private readonly MobileNotificationService $notifications)
    {
    }

    /** Trả danh sách notification theo phạm vi cá nhân/admin/nhân viên với phân trang tối đa 100 bản ghi. */
    public function index(Request $request): JsonResponse
    {
        $perPage = min(max($request->integer('per_page', 20), 1), 100);

        return response()->json($this->notifications->list(
            $request->user(),
            (string) $request->query('scope', 'mine'),
            $perPage,
        ));
    }

    /** Đánh dấu một notification thuộc người dùng hiện tại là đã đọc. */
    public function markRead(Request $request, string $notification): JsonResponse
    {
        $this->notifications->markRead($request->user(), $notification);

        return response()->json(status: 204);
    }

    /** Đánh dấu toàn bộ notification chưa đọc của người dùng hiện tại là đã đọc. */
    public function markAllRead(Request $request): JsonResponse
    {
        $this->notifications->markAllRead($request->user());

        return response()->json(status: 204);
    }
}
