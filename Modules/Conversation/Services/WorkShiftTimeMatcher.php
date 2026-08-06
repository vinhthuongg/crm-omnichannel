<?php

namespace Modules\Conversation\Services;

use Modules\Conversation\Models\WorkShift;

class WorkShiftTimeMatcher
{
    /** Kiểm tra một thời điểm có nằm trong khoảng ca trực hay không. */
    public function contains(WorkShift $shift, $now): bool
    {
        if (! $shift->starts_at || ! $shift->ends_at) return false;
        if ($shift->starts_at <= $now && $shift->ends_at > $now) return true;
        $start = $this->seconds($shift->starts_at); $end = $this->seconds($shift->ends_at); $current = $this->seconds($now);
        if ($start === $end) return true;
        return $start < $end ? $current >= $start && $current < $end : $current >= $start || $current < $end;
    }

    /** Chuyển thời điểm ca trực thành số giây tính từ đầu ngày. */
    private function seconds($value): int
    {
        return ((int) $value->format('H') * 3600) + ((int) $value->format('i') * 60) + (int) $value->format('s');
    }
}
