<?php

namespace Modules\Dashboard\Services;

use Illuminate\Support\Carbon;

class DashboardDateRange
{
    /** Chuẩn hóa bộ lọc ngày thành thời điểm bắt đầu, kết thúc và cờ đã lọc. */
    public function from(array $filters): array
    {
        $filtered = filled($filters['date'] ?? null) || filled($filters['start_date'] ?? null) || filled($filters['end_date'] ?? null);
        $startDate = $filters['date'] ?? $filters['start_date'] ?? $filters['end_date'] ?? today()->toDateString();
        $endDate = $filters['date'] ?? $filters['end_date'] ?? $filters['start_date'] ?? $startDate;
        $start = Carbon::parse($startDate)->startOfDay();
        $end = Carbon::parse($endDate)->endOfDay();
        if ($start->gt($end)) [$start, $end] = [$end->copy()->startOfDay(), $start->copy()->endOfDay()];
        return [$start, $end, $filtered];
    }
}
