<?php

namespace App\Actions\Web;

use App\Models\User;
use App\Services\CrmNavigationService;
use Modules\Dashboard\Services\DashboardActivityService;
use Modules\Dashboard\Services\DashboardAgentService;
use Modules\Dashboard\Services\DashboardChannelService;
use Modules\Dashboard\Services\DashboardConversationAnalyticsService;
use Modules\Dashboard\Services\DashboardCustomerService;
use Modules\Dashboard\Services\DashboardNotificationService;
use Modules\Dashboard\Services\DashboardTodayOverviewService;
use Modules\Facebook\Services\FacebookFirstContactMenuSettings;

class GetDashboardViewDataAction
{
    /** Nhận các dịch vụ tạo menu, hoạt động, nhân viên, kênh, hội thoại, khách hàng, notification và tổng quan hôm nay cho dashboard web. */
    public function __construct(
        private readonly CrmNavigationService $navigation,
        private readonly DashboardActivityService $activities,
        private readonly DashboardAgentService $agentMetrics,
        private readonly DashboardChannelService $channels,
        private readonly DashboardConversationAnalyticsService $conversationAnalytics,
        private readonly DashboardCustomerService $customers,
        private readonly DashboardNotificationService $notifications,
        private readonly DashboardTodayOverviewService $todayOverview,
        private readonly FacebookFirstContactMenuSettings $firstContactMenu,
    ) {}

    /** Tạo dữ liệu cho từng khu vực dashboard theo quyền, kỳ báo cáo và từ khóa tìm kiếm. */
    public function execute(User $user, array $filters = []): array
    {
        $section = (string) ($filters['section'] ?? 'dashboard');
        $search = trim((string) ($filters['search'] ?? ''));
        $period = in_array(($filters['period'] ?? 'week'), ['week', 'month', 'year'], true)
            ? (string) $filters['period']
            : 'week';
        $activityFilters = [
            'agent' => (string) ($filters['activity_agent'] ?? 'all'),
            'type' => (string) ($filters['activity_type'] ?? 'all'),
            'keyword' => trim((string) ($filters['activity_keyword'] ?? '')),
        ];
        $notificationFilters = [
            'status' => (string) ($filters['notification_status'] ?? 'all'),
            'type' => (string) ($filters['notification_type'] ?? 'all'),
            'keyword' => trim((string) ($filters['notification_keyword'] ?? '')),
        ];
        $conversation = $this->conversationAnalytics->build($user, $search, $period);
        $activity = $this->activities->build($user, $activityFilters);

        return [
            'currentUser' => $user,
            'activeSection' => $section,
            'sectionTitle' => $this->sectionTitle($section, $user),
            'filters' => ['search' => $search, 'period' => $period],
            'navItems' => $this->navigation->forUser($user),
            'sidebar' => ['team_name' => $user->hasRole('Admin') ? 'CRM Admin Desk' : 'Assigned Inbox'],
            'taskProgress' => $conversation['taskProgress'],
            'dashboardOverview' => $this->todayOverview->build($user),
            'messageMetric' => $conversation['messageMetric'],
            'finishedTask' => $conversation['finishedTask'],
            'weeklySummary' => $conversation['weeklySummary'],
            'yearTrend' => $conversation['yearTrend'],
            'allocation' => $this->customers->allocation($user),
            'highlightedCustomer' => $this->customers->highlighted($user),
            'completedTask' => $conversation['completedTask'],
            'recentConversations' => $conversation['recentConversations'],
            'statusCounts' => $conversation['statusCounts'],
            'channelMetrics' => $this->channels->metrics($user),
            'channelManagement' => $this->channels->management(),
            'topCustomers' => $this->customers->top($user),
            'agents' => $this->agentMetrics->agents($user),
            'agentDashboard' => $this->agentMetrics->build($user),
            'activityDashboard' => $activity,
            'activityLogs' => $activity['logs'],
            'notificationDashboard' => $this->notifications->build($user, $notificationFilters),
            'notificationCount' => $conversation['notificationCount'],
            'facebookFirstContactMenu' => $user->hasRole('Admin') ? $this->firstContactMenu->get() : null,
        ];
    }

    /** Ánh xạ mã section dashboard sang tiêu đề hiển thị. */
    private function sectionTitle(string $section, User $user): string
    {
        return collect($this->navigation->forUser($user))->firstWhere('section', $section)['label'] ?? 'Dashboard';
    }
}
