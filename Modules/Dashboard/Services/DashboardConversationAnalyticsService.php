<?php

namespace Modules\Dashboard\Services;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Modules\Conversation\Services\ConversationVisibilityService;
use Modules\Conversation\Support\ConversationStatus;

class DashboardConversationAnalyticsService
{
    /** Nhận ConversationVisibilityService để kiểm tra phạm vi truy cập; DashboardConversationSeriesService để tạo chuỗi thời gian, heatmap và xu hướng năm; DashboardTrendMath để tính tỷ lệ thay đổi và điểm sparkline. */
    public function __construct(
        private readonly ConversationVisibilityService $visibility,
        private readonly DashboardConversationSeriesService $series,
        private readonly DashboardTrendMath $trendMath,
    ) {
    }

    /** Tổng hợp tiến độ, heatmap, xu hướng, hội thoại gần đây và số theo trạng thái. */
    public function build(User $user, string $search, string $period): array
    {
        $query = $this->visibility->visibleFor($user);
        $total = (clone $query)->count();
        $closed = (clone $query)->where('status', ConversationStatus::CLOSED)->count();
        $month = $this->activityBetween($user, now()->startOfMonth(), now()->endOfMonth());
        $previousMonth = $this->activityBetween($user, now()->subMonthNoOverflow()->startOfMonth(), now()->subMonthNoOverflow()->endOfMonth());
        $yearTrend = $this->series->yearTrend($user);

        return [
            'taskProgress' => ['percent' => $total > 0 ? (int) round(($closed / $total) * 100) : 0, 'completed' => $closed, 'total' => $total],
            'messageMetric' => [
                'value' => $month,
                'change' => $this->trendMath->percentageChange($month, $previousMonth),
                'sparkline' => $this->trendMath->sparkline($this->series->dailyCounts($user, 7), 160, 72),
            ],
            'finishedTask' => ['value' => $closed, 'heatmap' => $this->series->heatmap($user)],
            'weeklySummary' => $this->series->summary($user, $period),
            'yearTrend' => [
                'total' => array_sum($yearTrend['values']->all()),
                'change' => $this->trendMath->percentageChange((int) $yearTrend['values']->last(), (int) $yearTrend['values']->slice(-2, 1)->first()),
                'points' => $this->trendMath->sparkline($yearTrend['values'], 320, 112),
                'labels' => $yearTrend['labels'],
            ],
            'completedTask' => ['value' => $closed],
            'recentConversations' => $this->recent($query, $search),
            'statusCounts' => [
                'open' => (clone $query)->where('status', ConversationStatus::IN_PROGRESS)->count(),
                'pending' => (clone $query)->where('status', ConversationStatus::WAITING)->count(),
                'closed' => $closed,
            ],
            'notificationCount' => (clone $query)->whereIn('status', ConversationStatus::ACTIVE)->where('last_message_at', '<', now()->subHours(2))->count(),
        ];
    }

    /** Lấy các hội thoại hoạt động gần đây theo từ khóa tìm kiếm. */
    private function recent(Builder $query, string $search)
    {
        return (clone $query)->with(['customer', 'assignee', 'messages' => fn ($messages) => $messages->latest()->limit(1)])
            ->when($search !== '', fn (Builder $conversations) => $conversations->whereHas('customer', fn (Builder $customers) => $customers
                ->where('name', 'like', "%{$search}%")->orWhere('email', 'like', "%{$search}%")->orWhere('phone', 'like', "%{$search}%")))
            ->latest('last_message_at')->limit(6)->get();
    }

    /** Đếm hội thoại có hoạt động trong khoảng thời gian yêu cầu. */
    private function activityBetween(User $user, $start, $end): int
    {
        return $this->visibility->visibleFor($user)
            ->whereBetween(\DB::raw('COALESCE(last_message_at, created_at)'), [$start, $end])->count();
    }
}
