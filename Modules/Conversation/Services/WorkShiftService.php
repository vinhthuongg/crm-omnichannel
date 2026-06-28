<?php

namespace Modules\Conversation\Services;

use App\Models\User;
use Modules\Conversation\Models\WorkShift;

class WorkShiftService
{
    public function currentShift(): ?WorkShift
    {
        $now = now();

        $exact = WorkShift::query()
            ->where('is_active', true)
            ->where('starts_at', '<=', $now)
            ->where('ends_at', '>', $now)
            ->with('agents')
            ->orderByDesc('starts_at')
            ->first();

        if ($exact) {
            return $exact;
        }

        return $this->activeShiftByDailyTime($now);
    }

    public function currentShiftFor(User $user): ?WorkShift
    {
        if ($user->can('conversation.view_all')) {
            return $this->currentShift();
        }

        $now = now();
        $exact = WorkShift::query()
            ->where('is_active', true)
            ->where('starts_at', '<=', $now)
            ->where('ends_at', '>', $now)
            ->whereHas('agents', fn ($query) => $query->whereKey($user->id))
            ->with('agents')
            ->orderByDesc('starts_at')
            ->first();

        if ($exact) {
            return $exact;
        }

        return $this->activeShiftByDailyTime($now, $user);
    }

    public function userIsInCurrentShift(User $user, ?int $shiftId = null): bool
    {
        $shift = $shiftId
            ? WorkShift::query()
                ->whereKey($shiftId)
                ->where('is_active', true)
                ->with('agents')
                ->first()
            : $this->currentShift();

        if (! $shift) {
            return false;
        }

        if ($shiftId && ! $this->containsNow($shift)) {
            return false;
        }

        return $shift->agents->contains('id', $user->id);
    }

    public function userBelongsToShift(User $user, ?int $shiftId): bool
    {
        if (! $shiftId) {
            return false;
        }

        return WorkShift::query()
            ->whereKey($shiftId)
            ->where('is_active', true)
            ->whereHas('agents', fn ($query) => $query->whereKey($user->id))
            ->exists();
    }

    private function activeShiftByDailyTime($now, ?User $user = null): ?WorkShift
    {
        return WorkShift::query()
            ->where('is_active', true)
            ->when($user, fn ($query) => $query->whereHas('agents', fn ($agents) => $agents->whereKey($user->id)))
            ->with('agents')
            ->orderBy('starts_at')
            ->get()
            ->first(fn (WorkShift $shift): bool => $this->containsDailyTime($shift, $now));
    }

    private function containsNow(WorkShift $shift): bool
    {
        $now = now();

        if ($shift->starts_at <= $now && $shift->ends_at > $now) {
            return true;
        }

        return $this->containsDailyTime($shift, $now);
    }

    private function containsDailyTime(WorkShift $shift, $now): bool
    {
        if (! $shift->starts_at || ! $shift->ends_at) {
            return false;
        }

        $start = $this->secondsOfDay($shift->starts_at);
        $end = $this->secondsOfDay($shift->ends_at);
        $current = $this->secondsOfDay($now);

        if ($start === $end) {
            return true;
        }

        if ($start < $end) {
            return $current >= $start && $current < $end;
        }

        return $current >= $start || $current < $end;
    }

    private function secondsOfDay($dateTime): int
    {
        return ((int) $dateTime->format('H') * 3600)
            + ((int) $dateTime->format('i') * 60)
            + (int) $dateTime->format('s');
    }
}
