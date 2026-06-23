<?php

namespace Modules\ActivityLog\Repositories;

use Modules\ActivityLog\Models\ActivityLog;

class ActivityLogRepository
{
    public function paginate(array $filters): mixed
    {
        return ActivityLog::query()->with('user')->latest()->paginate((int) ($filters['per_page'] ?? 20));
    }
}