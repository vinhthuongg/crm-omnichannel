<?php

namespace Modules\ActivityLog\Repositories;

use Modules\ActivityLog\Models\ActivityLog;

class ActivityLogRepository
{
    /** Phân trang nhật ký hoạt động mới nhất và nạp người dùng đã thực hiện thao tác. */
    public function paginate(array $filters): mixed
    {
        return ActivityLog::query()->with('user')->latest()->paginate((int) ($filters['per_page'] ?? 20));
    }
}
