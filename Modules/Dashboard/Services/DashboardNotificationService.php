<?php

namespace Modules\Dashboard\Services;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Collection;

class DashboardNotificationService
{
    /** Định dạng notification gần đây và đếm số chưa đọc cho dashboard. */
    public function build(User $user, array $filters): array
    {
        $baseQuery = $this->baseQuery($user);
        $types = (clone $baseQuery)->latest()->limit(200)->get(['data'])
            ->map(fn (DatabaseNotification $notification): string => (string) data_get($notification->data, 'type', 'system'))
            ->filter()->unique()->values()
            ->map(fn (string $type): array => ['key' => $type, 'label' => $this->typeLabel($type)]);

        $filteredQuery = $this->applyFilters($this->baseQuery($user), $filters);
        $notifications = (clone $filteredQuery)->latest()->limit(50)->get();
        $typeCounts = $notifications
            ->groupBy(fn (DatabaseNotification $notification): string => (string) data_get($notification->data, 'type', 'system'))
            ->map(fn (Collection $items, string $type): array => [
                'key' => $type,
                'label' => $this->typeLabel($type),
                'count' => $items->count(),
            ])->sortByDesc('count')->values();

        return [
            'notifications' => $notifications,
            'types' => $types,
            'typeCounts' => $typeCounts,
            'filters' => [
                'status' => $filters['status'] ?: 'all',
                'type' => $filters['type'] ?: 'all',
                'keyword' => $filters['keyword'] ?: '',
            ],
            'summary' => [
                'total' => (clone $filteredQuery)->count(),
                'unread' => (clone $this->baseQuery($user))->whereNull('read_at')->count(),
                'today' => (clone $filteredQuery)->whereDate('created_at', today())->count(),
                'types' => $typeCounts->count(),
            ],
        ];
    }

    /** Khởi tạo truy vấn nền dùng chung trước khi áp dụng bộ lọc. */
    private function baseQuery(User $user): Builder
    {
        return DatabaseNotification::query()
            ->where('notifiable_type', $user->getMorphClass())
            ->where('notifiable_id', $user->getKey());
    }

    /** Áp dụng các điều kiện lọc đầu vào lên truy vấn hiện tại. */
    private function applyFilters(Builder $query, array $filters): Builder
    {
        $status = (string) ($filters['status'] ?? 'all');
        $type = (string) ($filters['type'] ?? 'all');
        $keyword = trim((string) ($filters['keyword'] ?? ''));

        return $query
            ->when($status === 'unread', fn (Builder $builder) => $builder->whereNull('read_at'))
            ->when($status === 'read', fn (Builder $builder) => $builder->whereNotNull('read_at'))
            ->when($type !== '' && $type !== 'all', fn (Builder $builder) => $builder->where('data->type', $type))
            ->when($keyword !== '', function (Builder $builder) use ($keyword): void {
                $builder->where(function (Builder $keywordQuery) use ($keyword): void {
                    $keywordQuery->where('id', 'like', "%{$keyword}%")
                        ->orWhere('type', 'like', "%{$keyword}%")
                        ->orWhere('data', 'like', "%{$keyword}%");
                });
            });
    }

    /** Chuyển mã loại notification thành nhãn tiếng Việt hiển thị trên dashboard. */
    private function typeLabel(string $type): string
    {
        return match ($type) {
            'new_message' => 'Tin nhắn mới',
            'conversation_assigned' => 'Phân công hội thoại',
            'conversation_waiting' => 'Khách đang đợi',
            'system' => 'Hệ thống',
            default => str($type)->replace(['_', '-'], ' ')->headline()->toString(),
        };
    }
}
