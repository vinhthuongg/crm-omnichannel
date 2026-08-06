<?php

namespace Modules\Mobile\Presenters;

use App\Models\User;
use Illuminate\Notifications\DatabaseNotification;

class MobileNotificationPresenter
{
    /** Định dạng dữ liệu notification thành cấu trúc phản hồi dành cho ứng dụng mobile hoặc dashboard. */
    public function notification(DatabaseNotification $notification): array
    {
        return ['id' => $notification->id, 'type' => $notification->type,
            'owner' => $notification->notifiable instanceof User ? $this->user($notification->notifiable) : null,
            'data' => $notification->data, 'read_at' => $notification->read_at?->toISOString(),
            'created_at' => $notification->created_at?->toISOString()];
    }

    /** Định dạng dữ liệu group thành cấu trúc phản hồi dành cho ứng dụng mobile hoặc dashboard. */
    public function group(?User $owner, $page, int $unread): array
    {
        return ['owner' => $owner ? $this->user($owner) : null, 'unread_count' => $unread,
            'data' => $page->getCollection()->map(fn (DatabaseNotification $item): array => $this->notification($item))->values(),
            'meta' => $this->pagination($page)];
    }

    /** Trả current/last page, per-page, total và cờ còn trang cho danh sách notification. */
    public function pagination($page): array
    {
        return ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(),
            'total' => $page->total(), 'has_more' => $page->hasMorePages()];
    }

    /** Định dạng dữ liệu emptyGroup thành cấu trúc phản hồi dành cho ứng dụng mobile hoặc dashboard. */
    public function emptyGroup(): array
    {
        return ['owner' => null, 'unread_count' => 0, 'data' => [],
            'meta' => ['current_page' => 1, 'last_page' => 1, 'per_page' => 0, 'total' => 0, 'has_more' => false]];
    }

    /** Định dạng dữ liệu user thành cấu trúc phản hồi dành cho ứng dụng mobile hoặc dashboard. */
    private function user(User $user): array
    {
        return ['id' => (int) $user->id, 'name' => $user->name, 'email' => $user->email, 'is_active' => (bool) $user->is_active,
            'roles' => $user->relationLoaded('roles') ? $user->roles->pluck('name')->values() : $user->getRoleNames()->values()];
    }
}
