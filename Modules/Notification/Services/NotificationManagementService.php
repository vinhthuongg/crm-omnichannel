<?php

namespace Modules\Notification\Services;

use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Notifications\DatabaseNotification;

class NotificationManagementService
{
    /** Phân trang notification mới nhất của người dùng với giới hạn từ 1 đến 100 bản ghi. */
    public function paginate(User $user, int $perPage): LengthAwarePaginator
    {
        return $user->notifications()->latest()->paginate(min(max($perPage, 1), 100));
    }

    /** Đánh dấu notification thuộc người dùng là đã đọc. */
    public function markRead(User $user, string $id): void
    {
        $user->notifications()->whereKey($id)->firstOrFail()->markAsRead();
    }

    /** Đánh dấu mọi database notification của người dùng là đã đọc. */
    public function markAllRead(User $user): void
    {
        DatabaseNotification::query()->where('notifiable_type', $user->getMorphClass())->where('notifiable_id', $user->getKey())
            ->whereNull('read_at')->update(['read_at' => now()]);
    }
}
