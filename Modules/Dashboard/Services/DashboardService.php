<?php

namespace Modules\Dashboard\Services;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\Conversation\Services\ConversationVisibilityService;
use Modules\Dashboard\Presenters\DashboardOverviewPresenter;

class DashboardService
{
    /** Nhận các thành phần giới hạn quyền, chuẩn hóa ngày, tính metrics/đội ngũ và định dạng dashboard overview. */
    public function __construct(
        private readonly ConversationVisibilityService $visibility,
        private readonly DashboardDateRange $dateRanges,
        private readonly DashboardOverviewMetricsService $metrics,
        private readonly DashboardTeamOverviewService $team,
        private readonly DashboardOverviewPresenter $presenter,
    ) {
    }

    /** Tổng hợp chỉ số hội thoại, đội ngũ và chuỗi biểu đồ trong khoảng thời gian được lọc. */
    public function overview(User $user, array $filters = []): array
    {
        [$startsAt, $endsAt, $filtered] = $this->dateRanges->from($filters);
        $visible = $this->visibility->visibleFor($user);
        $overview = $filtered ? $this->whereActivityBetween(clone $visible, $startsAt, $endsAt) : clone $visible;
        $activity = $filtered
            ? $this->whereActivityBetween(clone $visible, $startsAt, $endsAt)->count()
            : $this->whereActivityDate(clone $visible, today())->count();

        return $this->presenter->present(
            $this->metrics->collect($user, $overview, $startsAt, $endsAt, $filtered),
            $this->team->collect($overview, $startsAt, $endsAt, $filtered),
            $startsAt,
            $endsAt,
            $filtered,
            $activity,
        );
    }

    /** Lọc hội thoại có lần hoạt động cuối hoặc ngày tạo trùng một ngày cụ thể. */
    private function whereActivityDate(Builder $query, \DateTimeInterface $day): Builder
    {
        return $query->whereDate(DB::raw('COALESCE(last_message_at, created_at)'), $day);
    }

    /** Lọc hội thoại có lần hoạt động cuối hoặc ngày tạo nằm trong khoảng thời gian. */
    private function whereActivityBetween(Builder $query, Carbon $startsAt, Carbon $endsAt): Builder
    {
        return $query->whereBetween(DB::raw('COALESCE(last_message_at, created_at)'), [$startsAt, $endsAt]);
    }
}
