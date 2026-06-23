<?php

namespace Modules\ActivityLog\Services;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Modules\ActivityLog\Models\ActivityLog;

class ActivityLogService
{
    public function record(?User $user, string $action, Model $subject, array $metadata = []): ActivityLog
    {
        return ActivityLog::query()->create(['user_id' => $user?->id, 'action' => $action, 'subject_type' => $subject->getMorphClass(), 'subject_id' => $subject->getKey(), 'metadata' => $metadata ?: null]);
    }
}