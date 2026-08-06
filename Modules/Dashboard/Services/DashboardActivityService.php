<?php

namespace Modules\Dashboard\Services;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Modules\ActivityLog\Models\ActivityLog;

class DashboardActivityService
{
    /** Lấy activity log gần đây và định dạng loại, nội dung, thời gian cho dashboard. */
    public function build(User $user, array $filters): array
    {
        $baseQuery = $this->baseQuery($user);
        $agents = User::query()
            ->whereIn('id', (clone $baseQuery)->select('user_id')->whereNotNull('user_id'))
            ->orderBy('name')->get(['id', 'name'])
            ->map(fn (User $agent): array => ['id' => $agent->id, 'name' => $agent->name]);
        $types = (clone $baseQuery)->select('action')->distinct()->pluck('action')
            ->map(fn (string $action): string => $this->type($action))->unique()->values()
            ->map(fn (string $type): array => ['key' => $type, 'label' => $this->typeLabel($type)]);

        $filteredQuery = $this->applyFilters($this->baseQuery($user), $filters);
        $logs = (clone $filteredQuery)->with('user')->latest()->limit(50)->get();
        $typeCounts = $logs->groupBy(fn (ActivityLog $log): string => $this->type($log->action))
            ->map(fn (Collection $items, string $type): array => [
                'key' => $type, 'label' => $this->typeLabel($type), 'count' => $items->count(),
            ])->sortByDesc('count')->values();
        $agentCounts = $logs->groupBy(fn (ActivityLog $log): string => $log->user?->name ?: 'Hệ thống')
            ->map(fn (Collection $items, string $name): array => [
                'name' => $name,
                'initial' => mb_strtoupper(mb_substr($name, 0, 1)),
                'count' => $items->count(),
            ])->sortByDesc('count')->values();

        return [
            'logs' => $logs,
            'agents' => $agents,
            'types' => $types,
            'typeCounts' => $typeCounts,
            'agentCounts' => $agentCounts,
            'filters' => [
                'agent' => $filters['agent'] ?: 'all',
                'type' => $filters['type'] ?: 'all',
                'keyword' => $filters['keyword'] ?: '',
            ],
            'summary' => [
                'total' => (clone $filteredQuery)->count(),
                'today' => (clone $filteredQuery)->whereDate('created_at', today())->count(),
                'agents' => (clone $filteredQuery)->whereNotNull('user_id')->distinct('user_id')->count('user_id'),
                'types' => $typeCounts->count(),
            ],
        ];
    }

    /** Khởi tạo truy vấn nền dùng chung trước khi áp dụng bộ lọc. */
    private function baseQuery(User $user): Builder
    {
        return ActivityLog::query()
            ->when(! $user->can('conversation.view_all'), fn (Builder $query) => $query->where('user_id', $user->id));
    }

    /** Áp dụng các điều kiện lọc đầu vào lên truy vấn hiện tại. */
    private function applyFilters(Builder $query, array $filters): Builder
    {
        $agent = (string) ($filters['agent'] ?? 'all');
        $type = (string) ($filters['type'] ?? 'all');
        $keyword = trim((string) ($filters['keyword'] ?? ''));

        return $query
            ->when($agent !== '' && $agent !== 'all', fn (Builder $builder) => $builder->where('user_id', (int) $agent))
            ->when($type !== '' && $type !== 'all', function (Builder $builder) use ($type): void {
                if ($type === 'system') {
                    $builder->where(fn (Builder $query) => $query->whereNull('user_id')
                        ->orWhere('action', 'like', 'system.%')->orWhere('action', 'like', 'auto.%'));

                    return;
                }

                $builder->where('action', 'like', $type.'.%');
            })
            ->when($keyword !== '', function (Builder $builder) use ($keyword): void {
                $builder->where(function (Builder $query) use ($keyword): void {
                    $query->where('action', 'like', "%{$keyword}%")
                        ->orWhere('subject_type', 'like', "%{$keyword}%")
                        ->orWhere('subject_id', 'like', "%{$keyword}%")
                        ->orWhere('metadata', 'like', "%{$keyword}%")
                        ->orWhereHas('user', fn (Builder $userQuery) => $userQuery->where('name', 'like', "%{$keyword}%"));
                });
            });
    }

    /** Suy ra loại system, message hoặc conversation từ mã hành động nhật ký. */
    private function type(string $action): string
    {
        if (str_contains($action, 'system') || str_contains($action, 'auto')) {
            return 'system';
        }

        return str_contains($action, '.') ? str($action)->before('.')->toString() : 'other';
    }

    /** Chuyển loại hoạt động thành nhãn hội thoại, tin nhắn hoặc hoạt động chung. */
    private function typeLabel(string $type): string
    {
        return match ($type) {
            'conversation' => 'Hội thoại',
            'message' => 'Tin nhắn',
            'customer' => 'Khách hàng',
            'facebook' => 'Facebook',
            'system' => 'Hệ thống',
            default => 'Khác',
        };
    }
}
