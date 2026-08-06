<?php

namespace Modules\Dashboard\Services;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Modules\Conversation\Support\ConversationStatus;
use Modules\Customer\Models\Customer;

class DashboardOverviewMetricsService
{
    /** Nhận DashboardPerformanceMetricsService để tính intent và thời gian phản hồi. */
    public function __construct(private readonly DashboardPerformanceMetricsService $performance)
    {
    }

    /** Đếm trạng thái hội thoại và tính tỷ lệ xử lý, thu thập số điện thoại trong kỳ. */
    public function collect(User $user, Builder $conversations, Carbon $startsAt, Carbon $endsAt, bool $filtered): array
    {
        $total = (clone $conversations)->count();
        $handled = (clone $conversations)->whereIn('status', [ConversationStatus::WAITING_CUSTOMER, ConversationStatus::CLOSED])->count();
        $phoneConversations = (clone $conversations)->whereHas('customer', fn (Builder $query): Builder => $query
            ->whereNotNull('phone')->where('phone', '<>', ''))->count();
        $customerQuery = fn (): Builder => Customer::query()->whereHas(
            'conversations',
            fn (Builder $query): Builder => $query->whereIn('id', (clone $conversations)->select('id')),
        );
        $phonesCollected = $customerQuery()->whereNotNull('phone')->where('phone', '<>', '')->count();
        $potentialCustomers = $customerQuery()->where('is_potential', true)->count();
        $newCustomers = $customerQuery()->when(
            $filtered,
            fn (Builder $query): Builder => $query->whereBetween('created_at', [$startsAt, $endsAt]),
            fn (Builder $query): Builder => $query->whereDate('created_at', today()),
        )->count();
        $intentMetrics = $this->performance->intents($user, $filtered ? $startsAt : null, $filtered ? $endsAt : null);
        $averageResponse = $this->performance->averageResponseMinutes($user, $filtered ? $startsAt : null, $filtered ? $endsAt : null);

        return [
            'total_conversations' => $total,
            'active_conversations' => (clone $conversations)->whereIn('status', ConversationStatus::ACTIVE)->count(),
            'customer_waiting_conversations' => (clone $conversations)->where('status', ConversationStatus::CUSTOMER_WAITING)->count(),
            'waiting_customer_conversations' => (clone $conversations)->where('status', ConversationStatus::WAITING_CUSTOMER)->count(),
            'bot_consulting_conversations' => (clone $conversations)->where('status', ConversationStatus::BOT_CONSULTING)->count(),
            'closed_conversations' => (clone $conversations)->where('status', ConversationStatus::CLOSED)->count(),
            'unhandled' => (clone $conversations)->whereIn('status', ConversationStatus::ACTIVE)->whereNull('assigned_to')->count(),
            'new_customers_today' => $newCustomers,
            'phones_collected' => $phonesCollected,
            'phone_conversations' => $phoneConversations,
            'potential_customers' => $potentialCustomers,
            'total_handled_conversations' => $handled,
            'average_response_minutes' => $averageResponse,
            'phone_collection_rate' => $total > 0 ? round(($phoneConversations / $total) * 100, 1) : 0.0,
            'new_customers_last_7_days' => $customerQuery()->whereBetween(
                'created_at',
                $filtered ? [$startsAt, $endsAt] : [today()->subDays(6)->startOfDay(), now()],
            )->count(),
            'intent_metrics' => $intentMetrics,
        ];
    }
}
