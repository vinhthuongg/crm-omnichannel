<?php

namespace Modules\Conversation\Services;

use App\Models\User;
use Modules\Conversation\Models\WorkShift;

class CurrentWorkShiftResolver
{
    /** Nhận WorkShiftTimeMatcher để so khớp thời điểm với khung giờ ca trực. */
    public function __construct(private readonly WorkShiftTimeMatcher $time) {}

    /** Tìm ca đang hoạt động có khung giờ chứa thời điểm hiện tại, kể cả ca qua nửa đêm. */
    public function resolve(?User $user = null): ?WorkShift
    {
        $now = now();
        $query = WorkShift::query()->where('is_active', true)->where('starts_at', '<=', $now)->where('ends_at', '>', $now)
            ->when($user, fn ($query) => $query->whereHas('agents', fn ($agents) => $agents->whereKey($user->id)))
            ->with('agents')->orderByDesc('starts_at');
        if ($shift = $query->first()) return $shift;
        return WorkShift::query()->where('is_active', true)
            ->when($user, fn ($query) => $query->whereHas('agents', fn ($agents) => $agents->whereKey($user->id)))
            ->with('agents')->orderBy('starts_at')->get()->first(fn (WorkShift $shift): bool => $this->time->contains($shift, $now));
    }
}
