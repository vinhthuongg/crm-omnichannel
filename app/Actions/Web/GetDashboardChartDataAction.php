<?php

namespace App\Actions\Web;

use App\Models\User;
use Modules\Dashboard\Services\DashboardConversationChartService;
use Modules\Dashboard\Services\DashboardCustomerChartService;

class GetDashboardChartDataAction
{
    /** Nhận dịch vụ tạo biểu đồ hội thoại và dịch vụ thống kê nhãn/hoạt động khách hàng. */
    public function __construct(
        private readonly DashboardConversationChartService $conversationCharts,
        private readonly DashboardCustomerChartService $customerCharts,
    ) {
    }

    /** Tạo chuỗi biểu đồ dashboard theo kỳ tuần, tháng hoặc năm người dùng được xem. */
    public function execute(User $user, array $filters = []): array
    {
        $period = in_array(($filters['period'] ?? 'week'), ['week', 'month', 'year'], true)
            ? (string) $filters['period']
            : 'week';

        return array_merge(
            $this->conversationCharts->build($user, $period),
            $this->customerCharts->build($user),
        );
    }
}
