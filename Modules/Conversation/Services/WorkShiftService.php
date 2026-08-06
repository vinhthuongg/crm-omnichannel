<?php

namespace Modules\Conversation\Services;

use App\Models\User;
use Modules\Conversation\Models\WorkShift;

class WorkShiftService
{
    /** Nhận CurrentWorkShiftResolver để tìm ca trực đang hoạt động; WorkShiftMembershipService để kiểm tra người dùng thuộc ca trực. */
    public function __construct(private readonly CurrentWorkShiftResolver $resolver, private readonly WorkShiftMembershipService $membership) {}

    /** Tìm ca trực đang hoạt động tại thời điểm hiện tại. */
    public function currentShift(): ?WorkShift { return $this->resolver->resolve(); }

    /** Tìm ca trực hiện tại có chứa người dùng được chỉ định. */
    public function currentShiftFor(User $user): ?WorkShift
    {
        return $this->resolver->resolve($user->can('conversation.view_all') ? null : $user);
    }

    /** Kiểm tra người dùng có thuộc ca trực đang hoạt động hay không. */
    public function userIsInCurrentShift(User $user, ?int $shiftId = null): bool
    {
        $shift = $shiftId ? WorkShift::query()->whereKey($shiftId)->where('is_active', true)->with('agents')->first() : $this->currentShift();
        return $shift ? $this->membership->isCurrentMember($user, $shift) : false;
    }

    /** Kiểm tra người dùng có được gán vào ca trực cụ thể hay không. */
    public function userBelongsToShift(User $user, ?int $shiftId): bool
    {
        return $shiftId ? $this->membership->belongs($user, $shiftId) : false;
    }
}
