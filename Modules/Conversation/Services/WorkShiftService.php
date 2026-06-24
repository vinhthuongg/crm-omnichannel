<?php

namespace Modules\Conversation\Services;

use App\Models\User;
use Modules\Conversation\Models\WorkShift;

class WorkShiftService
{
    public function currentShift()
    {
        $now = now();

        return WorkShift::query()
            ->where('is_active', true)
            ->where('starts_at', '<=', $now)
            ->where('ends_at', '>', $now)
            ->with('agents')
            ->orderByDesc('starts_at')
            ->first();
    }

    public function currentShiftFor(User $user): ?WorkShift
    {
        if ($user->can('conversation.view_all')) {
            return $this->currentShift();
        }

        $now = now();

        return WorkShift::query()
            ->where('is_active', true)
            ->where('starts_at', '<=', $now)
            ->where('ends_at', '>', $now)
            ->whereHas('agents', fn ($query) => $query->whereKey($user->id))
            ->with('agents')
            ->orderByDesc('starts_at')
            ->first();
    }

    public function userIsInCurrentShift(User $user, ?int $shiftId = null): bool
    {
        $shift = $shiftId
            ? WorkShift::query()
                ->whereKey($shiftId)
                ->where('is_active', true)
                ->where('starts_at', '<=', now())
                ->where('ends_at', '>', now())
                ->first()
            : $this->currentShift();

        if (! $shift) {
            return false;
        }

        return $shift->agents()->whereKey($user->id)->exists();
    }
}
