<?php

namespace Modules\Dashboard\Services;

use Illuminate\Support\Collection;

class DashboardPeriodFactory
{
    /** Tạo các khoảng thời gian và nhãn biểu đồ tương ứng với kỳ tuần, tháng hoặc năm. */
    public function buckets(string $period): Collection
    {
        return match ($period) {
            'month' => collect(range(3, 0))->map(function (int $offset): array {
                $start = today()->subWeeks($offset)->startOfWeek();
                return ['label' => $start->format('j M'), 'start' => $start, 'end' => $start->copy()->endOfWeek()];
            }),
            'year' => collect(range(11, 0))->map(function (int $offset): array {
                $start = today()->subMonthsNoOverflow($offset)->startOfMonth();
                return ['label' => $start->format('M'), 'start' => $start, 'end' => $start->copy()->endOfMonth()];
            }),
            default => collect(range(6, 0))->map(function (int $offset): array {
                $day = today()->subDays($offset);
                return ['label' => $day->format('D, j M'), 'start' => $day->copy()->startOfDay(), 'end' => $day->copy()->endOfDay()];
            }),
        };
    }

    /** Tạo danh sách các năm liên tiếp kết thúc ở năm hiện tại. */
    public function years(int $count = 5): Collection
    {
        return collect(range((int) now()->subYears($count - 1)->format('Y'), (int) now()->format('Y')));
    }
}
