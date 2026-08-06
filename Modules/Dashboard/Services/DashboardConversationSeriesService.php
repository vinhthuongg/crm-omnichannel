<?php

namespace Modules\Dashboard\Services;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Conversation\Services\ConversationVisibilityService;
use Modules\Conversation\Support\ConversationStatus;

class DashboardConversationSeriesService
{
    /** Nhận ConversationVisibilityService để kiểm tra phạm vi truy cập; DashboardPeriodFactory để tạo dữ liệu theo cấu hình; DashboardTrendMath để tính tỷ lệ thay đổi và điểm sparkline. */
    public function __construct(
        private readonly ConversationVisibilityService $visibility,
        private readonly DashboardPeriodFactory $periods,
        private readonly DashboardTrendMath $trendMath,
    ) {
    }

    /** Tạo chuỗi số hội thoại theo từng mốc của kỳ tuần, tháng hoặc năm. */
    public function summary(User $user, string $period): array
    {
        $buckets = $period === 'week'
            ? collect(range(0, 6))->map(fn (int $offset): array => [
                'label' => today()->subDays(6)->addDays($offset)->format('D, j M'),
                'start' => today()->subDays(6)->addDays($offset),
                'end' => today()->subDays(6)->addDays($offset),
            ])
            : $this->periods->buckets($period);
        $bars = $buckets->map(fn (array $bucket): array => $this->statusBar($user, $bucket));
        $first = $buckets->first()['start'];
        $last = $buckets->last()['end'];
        $days = max(1, $first->diffInDays($last) + 1);
        $previous = $this->between($this->visibility->visibleFor($user), $first->copy()->subDays($days), $first->copy()->subSecond())->count();

        return ['total' => $bars->sum('total'), 'change' => $this->trendMath->percentageChange($bars->sum('total'), $previous), 'bars' => $bars];
    }

    /** Đếm số hội thoại theo từng ngày trong khoảng thời gian yêu cầu. */
    public function dailyCounts(User $user, int $days): Collection
    {
        return collect(range($days - 1, 0))->map(fn (int $offset): int => $this->onDate(
            $this->visibility->visibleFor($user), today()->subDays($offset)
        )->count());
    }

    /** Tạo dữ liệu heatmap thể hiện mật độ hoạt động theo ngày. */
    public function heatmap(User $user): array
    {
        $start = today()->subDays(27);
        $weeks = collect(range(0, 3))->map(fn (int $week): array => collect(range(0, 6))->map(function (int $day) use ($start, $week, $user): array {
            $date = $start->copy()->addDays(($week * 7) + $day);
            $count = $this->onDate($this->visibility->visibleFor($user), $date)->count();

            return ['date' => $date->toDateString(), 'count' => $count, 'tone' => min(4, (int) ceil($count / 2))];
        })->all());

        return ['labels' => ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'], 'weeks' => $weeks];
    }

    /** Tổng hợp số lượng hội thoại theo từng năm để biểu diễn xu hướng. */
    public function yearTrend(User $user): array
    {
        $labels = $this->periods->years();

        return ['labels' => $labels, 'values' => $labels->map(fn (int $year): int => $this->between(
            $this->visibility->visibleFor($user), Carbon::create($year)->startOfYear(), Carbon::create($year)->endOfYear()
        )->count())];
    }

    /** Tính số lượng và tỷ lệ chiều cao cho từng trạng thái trong một bucket. */
    private function statusBar(User $user, array $bucket): array
    {
        $query = fn (): Builder => $bucket['start']->isSameDay($bucket['end'])
            ? $this->onDate($this->visibility->visibleFor($user), $bucket['start'])
            : $this->between($this->visibility->visibleFor($user), $bucket['start'], $bucket['end']);
        $open = $query()->where('status', ConversationStatus::IN_PROGRESS)->count();
        $pending = $query()->where('status', ConversationStatus::WAITING)->count();
        $closed = $query()->where('status', ConversationStatus::CLOSED)->count();
        $heightTotal = max(1, $open + $pending + $closed);

        return [
            'label' => $bucket['label'], 'open' => $open, 'pending' => $pending, 'closed' => $closed,
            'total' => $open + $pending + $closed,
            'open_height' => ($open / $heightTotal) * 100,
            'pending_height' => ($pending / $heightTotal) * 100,
            'closed_height' => ($closed / $heightTotal) * 100,
        ];
    }

    /** Giới hạn truy vấn vào khoảng thời gian bắt đầu và kết thúc. */
    private function between(Builder $query, Carbon $start, Carbon $end): Builder
    {
        return $query->whereBetween($this->activityColumn(), [$start, $end]);
    }

    /** Giới hạn truy vấn vào hoạt động phát sinh trong một ngày cụ thể. */
    private function onDate(Builder $query, Carbon $day): Builder
    {
        return $query->whereDate($this->activityColumn(), $day);
    }

    /** Tạo biểu thức ngày hoạt động từ thời điểm tin cuối hoặc thời điểm tạo. */
    private function activityColumn(): \Illuminate\Database\Query\Expression
    {
        return DB::raw('COALESCE(last_message_at, created_at)');
    }
}
