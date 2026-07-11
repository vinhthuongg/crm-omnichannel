<?php

namespace Modules\Dashboard\Services;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
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

    public function overview(User $user, array $filters = []): array
    {
        [$startsAt, $endsAt, $hasDateFilter] = $this->dateRange($filters);
        $visibleConversations = $this->visibility->visibleFor($user);
        $overviewConversations = $hasDateFilter
            ? $this->whereActivityBetween(clone $visibleConversations, $startsAt, $endsAt)
            : clone $visibleConversations;
        $totalConversations = (clone $overviewConversations)->count();
        $totalHandled = (clone $overviewConversations)
            ->whereIn('status', [ConversationStatus::IN_PROGRESS, ConversationStatus::RESOLVED, ConversationStatus::CLOSED])
            ->count();
        $activeConversations = (clone $overviewConversations)->where('status', ConversationStatus::IN_PROGRESS)->count();
        $phonesCollected = Customer::query()
            ->whereNotNull('phone')
            ->where('phone', '<>', '')
            ->whereHas('conversations', fn (Builder $query): Builder => $query->whereIn('id', (clone $overviewConversations)->select('id')))
            ->count();
        $phoneConversations = (clone $overviewConversations)
            ->whereHas('customer', fn (Builder $query): Builder => $query
                ->whereNotNull('phone')
                ->where('phone', '<>', ''))
            ->count();
        $potentialCustomers = Customer::query()
            ->where('is_potential', true)
            ->whereHas('conversations', fn (Builder $query): Builder => $query->whereIn('id', (clone $overviewConversations)->select('id')))
            ->count();
        $newCustomersToday = Customer::query()
            ->when(
                $hasDateFilter,
                fn (Builder $query): Builder => $query->whereBetween('created_at', [$startsAt, $endsAt]),
                fn (Builder $query): Builder => $query->whereDate('created_at', today()),
            )
            ->count();
        $intentMetrics = $this->intentMetrics($user, $hasDateFilter ? $startsAt : null, $hasDateFilter ? $endsAt : null);
        $averageResponseMinutes = $this->averageResponseMinutes($user, $hasDateFilter ? $startsAt : null, $hasDateFilter ? $endsAt : null);
        $phoneCollectionRate = $totalConversations > 0 ? round(($phoneConversations / $totalConversations) * 100, 1) : 0.0;
        $activityConversations = $hasDateFilter
            ? $this->whereActivityBetween(clone $visibleConversations, $startsAt, $endsAt)->count()
            : $this->whereActivityDate(clone $visibleConversations, today())->count();

        return [
            'date_range' => [
                'date' => $hasDateFilter && $startsAt->isSameDay($endsAt) ? $startsAt->toDateString() : null,
                'start_date' => $startsAt->toDateString(),
                'end_date' => $endsAt->toDateString(),
                'starts_at' => $startsAt->toISOString(),
                'ends_at' => $endsAt->toISOString(),
                'is_filtered' => $hasDateFilter,
            ],
            'conversations_today' => $activityConversations,
            'new_messages_today' => $activityConversations,
            'unhandled' => (clone $overviewConversations)->whereIn('status', ConversationStatus::ACTIVE)->whereNull('assigned_to')->count(),
            'by_employee' => User::query()->withCount(['assignedConversations as open_conversations_count' => fn ($query) => $query->where('status', ConversationStatus::IN_PROGRESS)])->get(['id', 'name', 'email']),
            'top_fast_responders' => Message::query()
                ->select('sender_id', DB::raw('COUNT(*) as replies_count'))
                ->where('sender_type', 'user')
                ->when(
                    $hasDateFilter,
                    fn (Builder $query): Builder => $query->whereBetween('created_at', [$startsAt, $endsAt]),
                    fn (Builder $query): Builder => $query->whereDate('created_at', today()),
                )
                ->whereIn('conversation_id', (clone $overviewConversations)->select('id'))
                ->groupBy('sender_id')
                ->orderByDesc('replies_count')
                ->limit(10)
                ->get(),
            'summary' => [
                'total_conversations' => $totalConversations,
                'active_conversations' => $activeConversations,
                'new_customers_today' => $newCustomersToday,
                'phones_collected' => $phonesCollected,
                'phone_conversations' => $phoneConversations,
                'potential_customers' => $potentialCustomers,
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
                'phone_conversations' => $phoneConversations,
                'potential_customers' => $potentialCustomers,
                'new_customers_last_7_days' => Customer::query()
                    ->whereBetween('created_at', $hasDateFilter ? [$startsAt, $endsAt] : [today()->subDays(6)->startOfDay(), now()])
                    ->count(),
            ],
            'cards' => [
                ['key' => 'total_conversations', 'label' => 'Tổng hội thoại', 'value' => $totalConversations],
                ['key' => 'test_drive_requests', 'label' => 'Yêu cầu lái thử', 'value' => $intentMetrics['test_drive_requests']],
                ['key' => 'installment_interests', 'label' => 'Quan tâm trả góp', 'value' => $intentMetrics['installment_interests']],
                ['key' => 'active_conversations', 'label' => 'Hội thoại đang xử lý', 'value' => $activeConversations],
                ['key' => 'new_customers_today', 'label' => 'Khách hàng mới', 'value' => $newCustomersToday],
                ['key' => 'potential_customers', 'label' => 'Khách hàng tiềm năng', 'value' => $potentialCustomers, 'tone' => 'potential', 'icon' => null],
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

    private function whereActivityBetween(Builder $query, Carbon $startsAt, Carbon $endsAt): Builder
    {
        return $query->whereBetween(DB::raw('COALESCE(last_message_at, created_at)'), [$startsAt, $endsAt]);
    }

    private function dateRange(array $filters): array
    {
        $hasDateFilter = filled($filters['date'] ?? null)
            || filled($filters['start_date'] ?? null)
            || filled($filters['end_date'] ?? null);

        $startDate = $filters['date'] ?? $filters['start_date'] ?? $filters['end_date'] ?? today()->toDateString();
        $endDate = $filters['date'] ?? $filters['end_date'] ?? $filters['start_date'] ?? $startDate;
        $startsAt = Carbon::parse($startDate)->startOfDay();
        $endsAt = Carbon::parse($endDate)->endOfDay();

        if ($startsAt->gt($endsAt)) {
            [$startsAt, $endsAt] = [$endsAt->copy()->startOfDay(), $startsAt->copy()->endOfDay()];
        }

        return [$startsAt, $endsAt, $hasDateFilter];
    }

    private function intentMetrics(User $user, ?Carbon $startsAt = null, ?Carbon $endsAt = null): array
    {
        return [
            'quote_requests' => $this->intentConversationCount($user, ConversationIntentService::TAG_QUOTE, ['bao gia', 'gia xe', 'lan banh'], $startsAt, $endsAt),
            'test_drive_requests' => $this->intentConversationCount($user, ConversationIntentService::TAG_TEST_DRIVE, ['lai thu', 'test drive', 'chay thu'], $startsAt, $endsAt),
            'installment_interests' => $this->intentConversationCount($user, ConversationIntentService::TAG_INSTALLMENT, ['tra gop', 'vay', 'ngan hang', 'lai suat'], $startsAt, $endsAt),
            'appointment_bookings' => $this->intentConversationCount($user, ConversationIntentService::TAG_APPOINTMENT, ['dat lich', 'lich hen', 'ghe showroom'], $startsAt, $endsAt),
            'maintenance_bookings' => $this->intentConversationCount($user, ConversationIntentService::TAG_MAINTENANCE, ['bao duong', 'bao tri', 'xuong dich vu'], $startsAt, $endsAt),
        ];
    }

    private function intentConversationCount(User $user, string $tagName, array $keywords, ?Carbon $startsAt = null, ?Carbon $endsAt = null): int
    {
        $query = (clone $this->visibility->visibleFor($user))
            ->whereHas('customer.tags', fn (Builder $tagQuery): Builder => $tagQuery->where('name', $tagName))
            ->when($startsAt && $endsAt, fn (Builder $query): Builder => $this->whereActivityBetween($query, $startsAt, $endsAt));

        return $query->count();
    }

    private function averageResponseMinutes(User $user, ?Carbon $startsAt = null, ?Carbon $endsAt = null): float
    {
        $samples = (clone $this->visibility->visibleFor($user))
            ->whereNotNull('first_response_at')
            ->when($startsAt && $endsAt, fn (Builder $query): Builder => $query->whereBetween('first_response_at', [$startsAt, $endsAt]))
            ->latest('first_response_at')
            ->limit(100)
            ->get(['created_at', 'first_response_at'])
            ->map(fn (Conversation $conversation): int => max(1, $conversation->created_at->diffInMinutes($conversation->first_response_at)));

        return $samples->isEmpty() ? 0.0 : round($samples->avg(), 1);
    }
}
