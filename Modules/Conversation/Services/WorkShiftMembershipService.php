<?php

namespace Modules\Conversation\Services;

use App\Models\User;
use Modules\Conversation\Models\WorkShift;

class WorkShiftMembershipService
{
    /** Nhận WorkShiftTimeMatcher để so khớp thời điểm với khung giờ ca trực. */
    public function __construct(private readonly WorkShiftTimeMatcher $time) {}

    /** Kiểm tra người dùng có nằm trong danh sách thành viên của ca đang hoạt động hay không. */
    public function isCurrentMember(User $user, WorkShift $shift): bool
    {
        return $shift->is_active && $this->time->contains($shift, now()) && $shift->loadMissing('agents')->agents->contains('id', $user->id);
    }

    /** Kiểm tra thành viên có thuộc phạm vi được yêu cầu hay không. */
    public function belongs(User $user, int $shiftId): bool
    {
        return WorkShift::query()->whereKey($shiftId)->where('is_active', true)
            ->whereHas('agents', fn ($query) => $query->whereKey($user->id))->exists();
    }
}
