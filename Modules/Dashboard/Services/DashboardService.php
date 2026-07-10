<?php

namespace Modules\Dashboard\Services;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Modules\Conversation\Models\Conversation;
use Modules\Conversation\Services\ConversationVisibilityService;
use Modules\Conversation\Services\ConversationIntentService;
use Modules\Conversation\Support\ConversationStatus;
use Modules\Customer\Models\Customer;
use Modules\Message\Models\Message;

class DashboardService
{
    public function __construct(private readonly ConversationVisibilityService $visibility)
    {
    }

    public function overview(User $user): array
    {
        $visibleConversations = $this->visibility->visibleFor($user);
        $totalConversations = (clone $visibleConversations)->count();
        $totalHandled = (clone $visibleConversations)
            ->whereIn('status', [ConversationStatus::IN_PROGRESS, ConversationStatus::RESOLVED, ConversationStatus::CLOSED])
            ->count();
        $activeConversations = (clone $visibleConversations)->where('status', ConversationStatus::IN_PROGRESS)->count();
        $phonesCollected = Customer::query()
            ->whereNotNull('phone')
            ->where('phone', '<>', '')
            ->whereHas('conversations', fn (Builder $query): Builder => $query->whereIn('id', $this->visibility->visibleFor($user)->select('id')))
            ->count();
        $newCustomersToday = Customer::query()->whereDate('created_at', today())->count();
        $intentMetrics = $this->intentMetrics($user);
        $averageResponseMinutes = $this->averageResponseMinutes($user);
        $phoneCollectionRate = $totalHandled > 0 ? round(($phonesCollected / $totalHandled) * 100, 1) : 0.0;

        return [
            'conversations_today' => $this->whereActivityDate(clone $visibleConversations, today())->count(),
            'new_messages_today' => $this->whereActivityDate(clone $visibleConversations, today())->count(),
            'unhandled' => (clone $visibleConversations)->whereIn('status', ConversationStatus::ACTIVE)->whereNull('assigned_to')->count(),
            'by_employee' => User::query()->withCount(['assignedConversations as open_conversations_count' => fn ($query) => $query->where('status', ConversationStatus::IN_PROGRESS)])->get(['id', 'name', 'email']),
            'top_fast_responders' => Message::query()
                ->select('sender_id', DB::raw('COUNT(*) as replies_count'))
                ->where('sender_type', 'user')
                ->whereDate('created_at', today())
                ->whereIn('conversation_id', $this->visibility->visibleFor($user)->select('id'))
                ->groupBy('sender_id')
                ->orderByDesc('replies_count')
                ->limit(10)
                ->get(),
            'summary' => [
                'total_conversations' => $totalConversations,
                'active_conversations' => $activeConversations,
                'new_customers_today' => $newCustomersToday,
                'phones_collected' => $phonesCollected,
                'total_handled_conversations' => $totalHandled,
                'average_response_minutes' => $averageResponseMinutes,
                'phone_collection_rate' => $phoneCollectionRate,
            ],
            'intent_metrics' => $intentMetrics,
            'agent_metrics' => [
                'total_handled_conversations' => $totalHandled,
                'average_response_minutes' => $averageResponseMinutes,
                'phone_collection_rate' => $phoneCollectionRate,
                'phones_collected' => $phonesCollected,
                'new_customers_last_7_days' => Customer::query()
                    ->whereBetween('created_at', [today()->subDays(6)->startOfDay(), now()])
                    ->count(),
            ],
            'cards' => [
                ['key' => 'total_conversations', 'label' => 'Tổng hội thoại', 'value' => $totalConversations],
                ['key' => 'test_drive_requests', 'label' => 'Yêu cầu lái thử', 'value' => $intentMetrics['test_drive_requests']],
                ['key' => 'installment_interests', 'label' => 'Quan tâm trả góp', 'value' => $intentMetrics['installment_interests']],
                ['key' => 'active_conversations', 'label' => 'Hội thoại đang xử lý', 'value' => $activeConversations],
                ['key' => 'new_customers_today', 'label' => 'Khách hàng mới', 'value' => $newCustomersToday],
                ['key' => 'appointment_bookings', 'label' => 'Khách đặt lịch', 'value' => $intentMetrics['appointment_bookings']],
                ['key' => 'phones_collected', 'label' => 'SĐT đã thu thập', 'value' => $phonesCollected],
                ['key' => 'maintenance_bookings', 'label' => 'Đặt lịch bảo dưỡng', 'value' => $intentMetrics['maintenance_bookings']],
                ['key' => 'quote_requests', 'label' => 'Khách yêu cầu báo giá', 'value' => $intentMetrics['quote_requests']],
                ['key' => 'average_response_minutes', 'label' => 'TG phản hồi TB', 'value' => $averageResponseMinutes, 'suffix' => 'phút'],
                ['key' => 'total_handled_conversations', 'label' => 'Tổng hội thoại xử lý', 'value' => $totalHandled],
                ['key' => 'phone_collection_rate', 'label' => 'Tỷ lệ thu thập SĐT', 'value' => $phoneCollectionRate, 'suffix' => '%'],
            ],
        ];
    }

    private function whereActivityDate(Builder $query, \DateTimeInterface $day): Builder
    {
        return $query->whereDate(DB::raw('COALESCE(last_message_at, created_at)'), $day);
    }

    private function intentMetrics(User $user): array
    {
        return [
            'quote_requests' => $this->intentConversationCount($user, ConversationIntentService::TAG_QUOTE, ['bao gia', 'gia xe', 'lan banh']),
            'test_drive_requests' => $this->intentConversationCount($user, ConversationIntentService::TAG_TEST_DRIVE, ['lai thu', 'test drive', 'chay thu']),
            'installment_interests' => $this->intentConversationCount($user, ConversationIntentService::TAG_INSTALLMENT, ['tra gop', 'vay', 'ngan hang', 'lai suat']),
            'appointment_bookings' => $this->intentConversationCount($user, ConversationIntentService::TAG_APPOINTMENT, ['dat lich', 'lich hen', 'ghe showroom']),
            'maintenance_bookings' => $this->intentConversationCount($user, ConversationIntentService::TAG_MAINTENANCE, ['bao duong', 'bao tri', 'xuong dich vu']),
        ];
    }

    private function intentConversationCount(User $user, string $tagName, array $keywords): int
    {
        return (clone $this->visibility->visibleFor($user))
            ->whereHas('customer.tags', fn (Builder $tagQuery): Builder => $tagQuery->where('name', $tagName))
            ->count();
    }

    private function averageResponseMinutes(User $user): float
    {
        $samples = (clone $this->visibility->visibleFor($user))
            ->whereNotNull('first_response_at')
            ->latest('first_response_at')
            ->limit(100)
            ->get(['created_at', 'first_response_at'])
            ->map(fn (Conversation $conversation): int => max(1, $conversation->created_at->diffInMinutes($conversation->first_response_at)));

        return $samples->isEmpty() ? 0.0 : round($samples->avg(), 1);
    }
}
