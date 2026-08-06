<?php

namespace Modules\Conversation\Services;

use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Modules\Conversation\Models\Conversation;
use Modules\Conversation\Models\WorkShift;
use Modules\Conversation\Support\ConversationStatus;

class WorkShiftQueryService
{
    /** Lấy danh sách ca, ca hiện tại/được chọn, nhân viên và số liệu hội thoại theo trạng thái. */
    public function overview(?int $selectedId = null, string $status = 'all', int $limit = 100): array
    {
        $shifts = WorkShift::query()->with('agents')->orderBy('starts_at')->limit($limit)->get();
        $now = now();
        $current = $shifts->first(fn (WorkShift $shift): bool => $this->isCurrent($shift));
        $next = $shifts->first(fn (WorkShift $shift): bool => $shift->is_active && $shift->starts_at?->greaterThan($now));
        $selected = $shifts->firstWhere('id', $selectedId) ?: $current ?: $next ?: $shifts->first();
        $status = $this->normalizeStatus($status);

        return [
            'shifts' => $shifts,
            'managed_shifts' => $shifts->filter(fn (WorkShift $shift): bool => $this->matchesStatus($shift, $status))->values(),
            'current_shift' => $current,
            'next_shift' => $next,
            'selected_shift' => $selected,
            'staff_members' => $selected ? $this->staffMembers($selected) : collect(),
            'staff_metrics' => $this->metrics($selected),
            'agents' => $agents = $this->agents(),
            'busy_agent_ids' => $busy = $this->busyAgentIds(),
            'create_agents' => $agents->reject(fn (User $agent): bool => $busy->contains($agent->id))->values(),
            'manage_status' => $status,
        ];
    }

    /** Phân trang ca trực theo trạng thái, giới hạn nhân viên thường vào các ca của họ. */
    public function paginateFor(User $user, ?string $status, int $perPage): LengthAwarePaginator
    {
        return WorkShift::query()->with('agents')
            ->when(! $user->can('user.manage'), fn (Builder $query): Builder => $query
                ->whereHas('agents', fn (Builder $agents): Builder => $agents->whereKey($user->id)))
            ->when($status, function (Builder $query) use ($status): void {
                match ($status) {
                    'active' => $query->where('is_active', true),
                    'inactive' => $query->where('is_active', false),
                    'current' => $query->where('is_active', true)->where('starts_at', '<=', now())->where('ends_at', '>', now()),
                    default => null,
                };
            })->orderByDesc('starts_at')->paginate(min(max($perPage, 1), 100));
    }

    /** Kiểm tra nhân viên có được xem ca dựa trên quyền quản lý hoặc thành viên ca. */
    public function canView(User $user, WorkShift $shift): bool
    {
        return $user->can('user.manage') || $shift->agents()->whereKey($user->id)->exists();
    }

    /** Đếm hội thoại đang chờ, đang xử lý, đã hoàn tất và tổng cộng của một ca. */
    public function metrics(?WorkShift $shift): array
    {
        if (! $shift) return ['waiting' => 0, 'handling' => 0, 'finished' => 0, 'total' => 0];
        $waiting = Conversation::query()->where('queue_shift_id', $shift->id)->where('status', ConversationStatus::WAITING)->whereNull('assigned_to')->count();
        $handling = Conversation::query()->where('owner_shift_id', $shift->id)->whereIn('status', ConversationStatus::ACTIVE)->whereNotNull('assigned_to')->count();
        $finished = Conversation::query()->where('owner_shift_id', $shift->id)->whereIn('status', [ConversationStatus::RESOLVED, ConversationStatus::CLOSED])->count();
        return compact('waiting', 'handling', 'finished') + ['total' => $waiting + $handling + $finished];
    }

    /** Kiểm tra ca có phải ca đang hoạt động tại thời điểm hiện tại hay không. */
    public function isCurrent(WorkShift $shift): bool
    {
        if (! $shift->is_active || ! $shift->starts_at || ! $shift->ends_at) return false;
        $now = now();
        if ($shift->starts_at <= $now && $shift->ends_at > $now) return true;
        $minute = fn ($value): int => ((int) $value->format('H') * 60) + (int) $value->format('i');
        [$start, $end, $current] = [$minute($shift->starts_at), $minute($shift->ends_at), $minute($now)];
        return $start === $end || ($start < $end ? $current >= $start && $current < $end : $current >= $start || $current < $end);
    }

    /** Lấy danh sách nhân sự có thể được phân vào ca trực. */
    private function staffMembers(WorkShift $shift): Collection
    {
        return $shift->agents()->withCount([
            'assignedConversations as active_conversations_count' => fn (Builder $query) => $query->where('owner_shift_id', $shift->id)->whereIn('status', ConversationStatus::ACTIVE),
            'assignedConversations as finished_conversations_count' => fn (Builder $query) => $query->where('owner_shift_id', $shift->id)->whereIn('status', [ConversationStatus::RESOLVED, ConversationStatus::CLOSED]),
        ])->orderBy('name')->get();
    }

    /** Lấy danh sách nhân viên phù hợp với điều kiện hiện tại. */
    private function agents(): Collection
    {
        return User::role(['CSKH', 'User'])->where('is_active', true)
            ->withCount(['assignedConversations as active_conversations_count' => fn (Builder $query) => $query->whereIn('status', ConversationStatus::ACTIVE)])
            ->orderBy('name')->get();
    }

    /** Lấy ID các nhân viên đang bận xử lý hội thoại. */
    private function busyAgentIds(): Collection
    {
        return WorkShift::query()->where('is_active', true)->with('agents:id')->get()
            ->flatMap(fn (WorkShift $shift) => $shift->agents->pluck('id'))->unique()->values();
    }

    /** Chuẩn hóa bộ lọc trạng thái về all, active hoặc inactive. */
    private function normalizeStatus(string $status): string
    {
        return in_array($status, ['all', 'active', 'inactive', 'current'], true) ? $status : 'all';
    }

    /** Kiểm tra tài nguyên có khớp bộ lọc trạng thái hay không. */
    private function matchesStatus(WorkShift $shift, string $status): bool
    {
        return match ($status) { 'active' => $shift->is_active, 'inactive' => ! $shift->is_active, 'current' => $this->isCurrent($shift), default => true };
    }
}
