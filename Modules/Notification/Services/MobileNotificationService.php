<?php

namespace Modules\Notification\Services;

use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Notifications\DatabaseNotification;
use Modules\Mobile\Presenters\MobileNotificationPresenter;

class MobileNotificationService
{
    /** Nhận presenter để chuẩn hóa notification và metadata phân trang cho ứng dụng mobile. */
    public function __construct(private readonly MobileNotificationPresenter $presenter) {}

    /** Trả notification của bản thân hoặc nhóm admin/nhân viên khác cùng tổng chưa đọc và phân trang. */
    public function list(User $user, string $scope, int $perPage): array
    {
        if ($scope === 'all') {
            $groups = [
                'mine' => [
                    'label' => 'Thông báo của tôi',
                    ...$this->groupPayload($user, $this->queryForUsers(collect([$user])), $perPage),
                ],
                'other_admins' => [
                    'label' => 'Thông báo của admin khác',
                    ...($user->hasRole('Admin')
                        ? $this->groupPayload(null, $this->queryForUsers($this->otherAdmins($user)), $perPage)
                        : $this->presenter->emptyGroup()),
                ],
                'other_agents' => [
                    'label' => 'Thông báo của nhân viên khác',
                    ...($user->hasRole('Admin')
                        ? $this->groupPayload(null, $this->queryForUsers($this->otherAgents($user)), $perPage)
                        : $this->presenter->emptyGroup()),
                ],
            ];

            return [
                'data' => [
                    'scope' => 'all',
                    'summary' => collect($groups)->map(fn (array $group): array => [
                        'total' => (int) data_get($group, 'meta.total', 0),
                        'unread' => (int) data_get($group, 'unread_count', 0),
                    ])->all(),
                    'groups' => $groups,
                ],
            ];
        }

        $query = match ($scope) {
            'other_admins' => $this->adminQuery($user, true),
            'other_agents' => $this->adminQuery($user, false),
            default => $user->notifications()->getQuery()->with('notifiable'),
        };
        $unreadCount = $this->unreadCount(clone $query);
        $page = $query->latest()->paginate($perPage);

        return [
            'data' => $page->getCollection()->map(fn (DatabaseNotification $notification): array => $this->presenter->notification($notification))->values(),
            'meta' => $this->presenter->pagination($page),
            'summary' => [
                'scope' => in_array($scope, ['mine', 'other_admins', 'other_agents'], true) ? $scope : 'mine',
                'unread_count' => $unreadCount,
            ],
        ];
    }

    /** Đánh dấu notification có ID tương ứng thuộc người dùng là đã đọc. */
    public function markRead(User $user, string $notificationId): void
    {
        $user->notifications()->whereKey($notificationId)->firstOrFail()->markAsRead();
    }

    /** Đánh dấu toàn bộ notification chưa đọc của người dùng là đã đọc. */
    public function markAllRead(User $user): void
    {
        $user->unreadNotifications->markAsRead();
    }

    /** Phân trang một truy vấn notification và tạo payload nhóm kèm chủ sở hữu, tổng chưa đọc. */
    private function groupPayload(?User $owner, Builder $query, int $perPage): array
    {
        $unreadCount = $this->unreadCount(clone $query);
        $page = $query->with('notifiable')->latest()->paginate($perPage);

        return $this->presenter->group($owner, $page, $unreadCount);
    }

    /** Tạo truy vấn database notification thuộc danh sách người dùng được cung cấp. */
    private function queryForUsers($users): Builder
    {
        $ids = collect($users)->map(fn (User $user): int => (int) $user->getKey())->filter()->values();

        return DatabaseNotification::query()
            ->with('notifiable')
            ->where('notifiable_type', (new User())->getMorphClass())
            ->whereIn('notifiable_id', $ids->all());
    }

    /** Chỉ cho admin tạo truy vấn notification của các admin hoặc nhân viên còn lại. */
    private function adminQuery(User $user, bool $admins): Builder
    {
        if (! $user->hasRole('Admin')) throw new AuthorizationException;
        return $this->queryForUsers($admins ? $this->otherAdmins($user) : $this->otherAgents($user));
    }

    /** Lấy các tài khoản Admin khác, loại trừ admin đang yêu cầu dữ liệu. */
    private function otherAdmins(User $user)
    {
        return User::role('Admin')->whereKeyNot($user->getKey())->get();
    }

    /** Lấy các tài khoản CSKH và User khác, loại trừ người đang yêu cầu dữ liệu. */
    private function otherAgents(User $user)
    {
        return User::role(['CSKH', 'User'])->whereKeyNot($user->getKey())->get();
    }

    /** Đếm số notification chưa có thời điểm đọc trong truy vấn được cung cấp. */
    private function unreadCount(Builder $query): int
    {
        return (int) $query->whereNull('read_at')->count();
    }

}
