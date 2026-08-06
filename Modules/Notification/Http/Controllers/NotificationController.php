<?php

namespace Modules\Notification\Http\Controllers;

use Illuminate\Http\Request;
use Modules\Shared\Http\Controllers\ApiController;
use Modules\Notification\Services\NotificationManagementService;

class NotificationController extends ApiController
{
    /** Nhận NotificationManagementService để đánh dấu notification đã đọc. */
    public function __construct(private readonly NotificationManagementService $notifications) {}

    /** Trả danh sách notification của người dùng hiện tại theo kích thước trang yêu cầu. */
    public function index(Request $request)
    {
        return response()->json(['data' => $this->notifications->paginate($request->user(), $request->integer('per_page', 20))]);
    }

    /** Đánh dấu notification thuộc tài khoản hiện tại là đã đọc và trả dữ liệu mới. */
    public function markAsRead(Request $request, string $id)
    {
        $this->notifications->markRead($request->user(), $id);
        return response()->json(status: 204);
    }
}
