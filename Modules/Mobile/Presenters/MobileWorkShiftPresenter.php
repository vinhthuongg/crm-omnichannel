<?php

namespace Modules\Mobile\Presenters;

use App\Models\User;
use Modules\Conversation\Models\WorkShift;
use Modules\Conversation\Services\WorkShiftQueryService;

class MobileWorkShiftPresenter
{
    /** Nhận WorkShiftQueryService để truy vấn dữ liệu. */
    public function __construct(private readonly WorkShiftQueryService $queries) {}

    /** Định dạng dữ liệu shift thành cấu trúc phản hồi dành cho ứng dụng mobile hoặc dashboard. */
    public function shift(WorkShift $shift): array
    {
        $metrics = $this->queries->metrics($shift);
        return [
            'id' => (int) $shift->id, 'name' => $shift->name,
            'starts_at' => $shift->starts_at?->toISOString(), 'ends_at' => $shift->ends_at?->toISOString(),
            'starts_time' => $shift->starts_at?->format('H:i'), 'ends_time' => $shift->ends_at?->format('H:i'),
            'is_active' => (bool) $shift->is_active, 'is_current' => $this->queries->isCurrent($shift),
            'agents' => $shift->relationLoaded('agents') ? $shift->agents->map(fn (User $user): array => $this->agent($user))->values() : [],
            'metrics' => $this->metrics($metrics),
            'created_at' => $shift->created_at?->toISOString(), 'updated_at' => $shift->updated_at?->toISOString(),
        ];
    }

    /** Trả ID, hồ sơ, trạng thái và vai trò của nhân viên trong ca trực. */
    public function agent(User $user): array
    {
        return ['id' => (int) $user->id, 'name' => $user->name, 'email' => $user->email,
            'is_active' => (bool) $user->is_active,
            'roles' => $user->relationLoaded('roles') ? $user->roles->pluck('name')->values() : $user->getRoleNames()->values()];
    }

    /** Đổi số hội thoại chờ, đang xử lý, hoàn tất thành payload thống kê mobile. */
    public function metrics(array $metrics): array
    {
        return ['waiting_conversations' => $metrics['waiting'], 'handling_conversations' => $metrics['handling'],
            'finished_conversations' => $metrics['finished'], 'total_conversations' => $metrics['total']];
    }
}
