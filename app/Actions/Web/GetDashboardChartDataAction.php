<?php

namespace App\Actions\Web;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Modules\Conversation\Models\Conversation;
use Modules\Conversation\Services\ConversationVisibilityService;
use Modules\Conversation\Support\ConversationStatus;
use Modules\Customer\Models\Customer;
use Modules\Customer\Models\CustomerTag;

class GetDashboardChartDataAction
{
    public function __construct(private readonly ConversationVisibilityService $visibility)
    {
    }

    public function execute(User $user, array $filters = []): array
    {
        $period = in_array(($filters['period'] ?? 'week'), ['week', 'month', 'year'], true)
            ? (string) $filters['period']
            : 'week';

        return [
            'closure' => $this->closureData($user),
            'messages' => $this->dailyConversations($user, 14),
            'conversation_status' => $this->conversationStatus($user, $period),
            'trend' => $this->yearTrend($user),
            'tags' => $this->tagAllocation($user),
            'channels' => $this->channelConversations($user),
            'customer_activity' => $this->topCustomerActivity($user),
        ];
    }

    private function visibleConversations(User $user): Builder
    {
        return $this->visibility->visibleFor($user);
    }

    private function closureData(User $user): array
    {
        return collect([
            'open' => ConversationStatus::IN_PROGRESS,
            'pending' => ConversationStatus::WAITING,
            'closed' => ConversationStatus::CLOSED,
        ])->map(fn (string $status, string $label): array => [
            'label' => ucfirst($label),
            'value' => (clone $this->visibleConversations($user))->where('status', $status)->count(),
        ])->values()->all();
    }

    private function dailyConversations(User $user, int $days): array
    {
        return collect(range($days - 1, 0))->map(function (int $offset) use ($user): array {
            $day = today()->subDays($offset);

            return [
                'date' => $day->toDateString(),
                'value' => (clone $this->visibleConversations($user))->whereDate('created_at', $day)->count(),
            ];
        })->all();
    }

    private function conversationStatus(User $user, string $period): array
    {
        $buckets = match ($period) {
            'month' => collect(range(3, 0))->map(function (int $offset): array {
                $start = today()->subWeeks($offset)->startOfWeek();

                return ['label' => $start->format('j M'), 'start' => $start, 'end' => $start->copy()->endOfWeek()];
            }),
            'year' => collect(range(11, 0))->map(function (int $offset): array {
                $start = today()->subMonthsNoOverflow($offset)->startOfMonth();

                return ['label' => $start->format('M'), 'start' => $start, 'end' => $start->copy()->endOfMonth()];
            }),
            default => collect(range(6, 0))->map(function (int $offset): array {
                $day = today()->subDays($offset);

                return ['label' => $day->format('D, j M'), 'start' => $day->copy()->startOfDay(), 'end' => $day->copy()->endOfDay()];
            }),
        };

        return $buckets->map(function (array $bucket) use ($user): array {
            return [
                'period' => $bucket['label'],
                'open' => (clone $this->visibleConversations($user))->whereBetween('created_at', [$bucket['start'], $bucket['end']])->where('status', ConversationStatus::IN_PROGRESS)->count(),
                'pending' => (clone $this->visibleConversations($user))->whereBetween('created_at', [$bucket['start'], $bucket['end']])->where('status', ConversationStatus::WAITING)->count(),
                'closed' => (clone $this->visibleConversations($user))->whereBetween('created_at', [$bucket['start'], $bucket['end']])->where('status', ConversationStatus::CLOSED)->count(),
            ];
        })->all();
    }

    private function yearTrend(User $user): array
    {
        return collect(range((int) now()->subYears(4)->format('Y'), (int) now()->format('Y')))
            ->map(fn (int $year): array => [
                'year' => (string) $year,
                'value' => (clone $this->visibleConversations($user))->whereYear('created_at', $year)->count(),
            ])
            ->all();
    }

    private function tagAllocation(User $user): array
    {
        return CustomerTag::query()
            ->orderBy('name')
            ->get()
            ->map(function (CustomerTag $tag) use ($user): CustomerTag {
                $tag->conversations_count = (clone $this->visibleConversations($user))
                    ->whereHas('customer.tags', fn (Builder $query): Builder => $query->whereKey($tag->id))
                    ->count();

                return $tag;
            })
            ->sortByDesc('conversations_count')
            ->take(5)
            ->values()
            ->map(fn (CustomerTag $tag): array => [
                'tag' => $tag->name,
                'value' => (int) $tag->conversations_count,
            ])
            ->all();
    }

    private function channelConversations(User $user): array
    {
        return collect(['facebook', 'zalo'])
            ->map(fn (string $channel): array => [
                'label' => ucfirst($channel),
                'value' => $this->applyConversationChannelFilter(clone $this->visibleConversations($user), $channel)->count(),
            ])
            ->filter(fn (array $item): bool => $item['value'] > 0)
            ->values()
            ->all();
    }

    private function topCustomerActivity(User $user): array
    {
        $customer = Customer::query()
            ->withCount(['conversations' => function (Builder $query) use ($user): void {
                $query->whereIn('conversations.id', $this->visibleConversations($user)->select('id'));
            }])
            ->orderByDesc('conversations_count')
            ->first();

        if (! $customer) {
            return [];
        }

        return collect(range(9, 0))->map(function (int $offset) use ($customer, $user): array {
            $day = today()->subDays($offset);

            return [
                'date' => $day->format('j M'),
                'value' => (clone $this->visibleConversations($user))
                    ->where('customer_id', $customer->id)
                    ->whereDate('created_at', $day)
                    ->count(),
            ];
        })->all();
    }

    private function applyConversationChannelFilter(Builder $query, string $channel): Builder
    {
        return $query->where(function (Builder $query) use ($channel): void {
            $query->whereHas('customer.channels', fn (Builder $channelQuery) => $channelQuery->where('channel', $channel))
                ->orWhereHas('messages', fn (Builder $messageQuery) => $messageQuery->where('channel', $channel));
        });
    }
}
