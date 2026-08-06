<?php

namespace Modules\Dashboard\Presenters;

use Illuminate\Support\Carbon;

class DashboardOverviewPresenter
{
    /** Ghép khoảng ngày, metrics, đội ngũ và card thành response dashboard overview. */
    public function present(
        array $metrics,
        array $team,
        Carbon $startsAt,
        Carbon $endsAt,
        bool $filtered,
        int $activityConversations,
    ): array {
        return [
            'date_range' => [
                'date' => $filtered && $startsAt->isSameDay($endsAt) ? $startsAt->toDateString() : null,
                'start_date' => $startsAt->toDateString(),
                'end_date' => $endsAt->toDateString(),
                'starts_at' => $startsAt->toISOString(),
                'ends_at' => $endsAt->toISOString(),
                'is_filtered' => $filtered,
            ],
            'conversations_today' => $activityConversations,
            'new_messages_today' => $activityConversations,
            'unhandled' => $metrics['unhandled'],
            ...$team,
            'summary' => $this->summary($metrics),
            'intent_metrics' => $metrics['intent_metrics'],
            'agent_metrics' => $this->agentMetrics($metrics),
            'cards' => $this->cards($metrics),
        ];
    }

    /** Chỉ lấy các chỉ số hội thoại và hiệu suất cần hiển thị trong thẻ tổng quan. */
    private function summary(array $metrics): array
    {
        return array_intersect_key($metrics, array_flip([
            'total_conversations', 'active_conversations', 'customer_waiting_conversations',
            'waiting_customer_conversations', 'bot_consulting_conversations', 'closed_conversations',
            'new_customers_today', 'phones_collected', 'phone_conversations', 'potential_customers',
            'total_handled_conversations', 'average_response_minutes', 'phone_collection_rate',
        ]));
    }

    /** Chỉ lấy các chỉ số hiệu suất nhân viên cần hiển thị trên dashboard. */
    private function agentMetrics(array $metrics): array
    {
        return array_intersect_key($metrics, array_flip([
            'total_handled_conversations', 'average_response_minutes', 'phone_collection_rate',
            'phones_collected', 'phone_conversations', 'potential_customers', 'new_customers_last_7_days',
        ]));
    }

    /** Định dạng dữ liệu cards thành cấu trúc phản hồi dành cho ứng dụng mobile hoặc dashboard. */
    private function cards(array $metrics): array
    {
        $intents = $metrics['intent_metrics'];

        return [
            ['key' => 'total_conversations', 'label' => 'Tổng hội thoại', 'value' => $metrics['total_conversations']],
            ['key' => 'test_drive_requests', 'label' => 'Yêu cầu lái thử', 'value' => $intents['test_drive_requests']],
            ['key' => 'installment_interests', 'label' => 'Quan tâm trả góp', 'value' => $intents['installment_interests']],
            ['key' => 'active_conversations', 'label' => 'Hội thoại đang xử lý', 'value' => $metrics['active_conversations']],
            ['key' => 'new_customers_today', 'label' => 'Khách hàng mới', 'value' => $metrics['new_customers_today']],
            ['key' => 'potential_customers', 'label' => 'Khách hàng tiềm năng', 'value' => $metrics['potential_customers'], 'tone' => 'potential', 'icon' => null],
            ['key' => 'appointment_bookings', 'label' => 'Khách đặt lịch', 'value' => $intents['appointment_bookings']],
            ['key' => 'phones_collected', 'label' => 'SĐT đã thu thập', 'value' => $metrics['phones_collected']],
            ['key' => 'maintenance_bookings', 'label' => 'Đặt lịch bảo dưỡng', 'value' => $intents['maintenance_bookings']],
            ['key' => 'quote_requests', 'label' => 'Khách yêu cầu báo giá', 'value' => $intents['quote_requests']],
            ['key' => 'average_response_minutes', 'label' => 'TG phản hồi TB', 'value' => $metrics['average_response_minutes'], 'suffix' => 'phút'],
            ['key' => 'total_handled_conversations', 'label' => 'Tổng hội thoại xử lý', 'value' => $metrics['total_handled_conversations']],
            ['key' => 'phone_collection_rate', 'label' => 'Tỷ lệ thu thập SĐT', 'value' => $metrics['phone_collection_rate'], 'suffix' => '%'],
        ];
    }
}
