<?php

namespace Modules\Dashboard\Actions;

use App\Models\User;
use Modules\Dashboard\Services\DashboardService;

class GetDashboardOverviewAction
{
    /** Nhận DashboardService để tổng hợp metrics, nhân sự và card trong khoảng ngày được lọc. */
    public function __construct(private readonly DashboardService $service)
    {
    }

    /** Tổng hợp card, metrics và chỉ số nhân viên trong khoảng ngày lọc. */
    public function execute(User $user, array $filters = []): array
    {
        return $this->service->overview($user, $filters);
    }
}
