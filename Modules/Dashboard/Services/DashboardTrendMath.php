<?php

namespace Modules\Dashboard\Services;

use Illuminate\Support\Collection;

class DashboardTrendMath
{
    /** Tính tỷ lệ phần trăm thay đổi giữa giá trị hiện tại và kỳ trước. */
    public function percentageChange(int $current, ?int $previous): int
    {
        return $previous ? (int) round((($current - $previous) / $previous) * 100) : ($current > 0 ? 100 : 0);
    }

    /** Chuyển chuỗi số liệu thành tập điểm dùng để vẽ sparkline. */
    public function sparkline(Collection $values, int $width, int $height): string
    {
        $max = max(1, (int) $values->max());
        $min = min(0, (int) $values->min());
        $range = max(1, $max - $min);
        $count = max(1, $values->count() - 1);
        return $values->values()->map(function (int $value, int $index) use ($width, $height, $min, $range, $count): string {
            $x = ($index / $count) * $width;
            $y = $height - ((($value - $min) / $range) * ($height - 10)) - 5;
            return round($x, 2).','.round($y, 2);
        })->implode(' ');
    }
}
