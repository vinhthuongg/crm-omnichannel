<?php

namespace App\Actions\Web;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Modules\ActivityLog\Models\ActivityLog;
use Modules\Conversation\Models\Conversation;
use Modules\Conversation\Models\Tag;
use Modules\Customer\Models\CustomerChannel;
use Modules\Customer\Models\Customer;
use Modules\Message\Models\Message;

class GetDashboardViewDataAction
{
    public function execute(User $user, array $filters = []): array
    {
        $section = (string) ($filters['section'] ?? 'dashboard');
        $search = trim((string) ($filters['search'] ?? ''));
        $period = in_array(($filters['period'] ?? 'week'), ['week', 'month', 'year'], true)
            ? (string) $filters['period']
            : 'week';

        $conversationQuery = $this->visibleConversations($user);
        $totalConversations = (clone $conversationQuery)->count();
        $closedConversations = (clone $conversationQuery)->where('status', 'closed')->count();
        $progress = $totalConversations > 0 ? (int) round(($closedConversations / $totalConversations) * 100) : 0;

        $monthMessages = $this->visibleMessages($user)
            ->whereBetween('created_at', [now()->startOfMonth(), now()->endOfMonth()])
            ->count();
        $previousMonthMessages = $this->visibleMessages($user)
            ->whereBetween('created_at', [
                now()->subMonthNoOverflow()->startOfMonth(),
                now()->subMonthNoOverflow()->endOfMonth(),
            ])
            ->count();

        $weeklySummary = $this->conversationSummary($user, $period);
        $yearTrend = $this->yearTrend($user);
        $topCustomer = $this->topCustomer($user);
        $recentConversations = (clone $conversationQuery)
            ->with(['customer', 'assignee', 'messages' => fn ($query) => $query->latest()->limit(1)])
            ->when($search !== '', function (Builder $query) use ($search): void {
                $query->whereHas('customer', function (Builder $customerQuery) use ($search): void {
                    $customerQuery->where('name', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%")
                        ->orWhere('phone', 'like', "%{$search}%");
                });
            })
            ->latest('last_message_at')
            ->limit(6)
            ->get();

        return [
            'currentUser' => $user,
            'activeSection' => $section,
            'sectionTitle' => $this->sectionTitle($section),
            'filters' => [
                'search' => $search,
                'period' => $period,
            ],
            'navItems' => $this->navItems(),
            'sidebar' => [
                'team_name' => $user->hasRole('Admin') ? 'CRM Admin Desk' : 'Assigned Inbox',
            ],
            'taskProgress' => [
                'percent' => $progress,
                'completed' => $closedConversations,
                'total' => $totalConversations,
            ],
            'messageMetric' => [
                'value' => $monthMessages,
                'change' => $this->percentageChange($monthMessages, $previousMonthMessages),
                'sparkline' => $this->sparkline($this->dailyMessageCounts($user, 7), 160, 72),
            ],
            'finishedTask' => [
                'value' => $closedConversations,
                'heatmap' => $this->heatmap($user),
            ],
            'weeklySummary' => $weeklySummary,
            'yearTrend' => [
                'total' => array_sum($yearTrend['values']->all()),
                'change' => $this->percentageChange(
                    (int) $yearTrend['values']->last(),
                    (int) $yearTrend['values']->slice(-2, 1)->first()
                ),
                'points' => $this->sparkline($yearTrend['values'], 320, 112),
                'labels' => $yearTrend['labels'],
            ],
            'allocation' => $this->allocation($user),
            'highlightedCustomer' => $topCustomer,
            'completedTask' => [
                'value' => $closedConversations,
            ],
            'recentConversations' => $recentConversations,
            'statusCounts' => [
                'open' => (clone $conversationQuery)->where('status', 'open')->count(),
                'pending' => (clone $conversationQuery)->where('status', 'pending')->count(),
                'closed' => $closedConversations,
            ],
            'channelMetrics' => $this->channelMetrics($user),
            'topCustomers' => $this->topCustomers($user),
            'agents' => $this->agents($user),
            'activityLogs' => $this->activityLogs($user),
            'notificationCount' => (clone $conversationQuery)
                ->whereIn('status', ['open', 'pending'])
                ->where('last_message_at', '<', now()->subHours(2))
                ->count(),
        ];
    }

    private function navItems(): array
    {
        return [
            ['section' => 'dashboard', 'label' => 'Dashboard', 'route' => 'dashboard', 'icon' => 'D'],
            ['section' => 'conversations', 'label' => 'Conversations', 'route' => 'crm.conversations', 'icon' => 'C'],
            ['section' => 'customers', 'label' => 'Customers', 'route' => 'crm.customers', 'icon' => 'K'],
            ['section' => 'agents', 'label' => 'Agents', 'route' => 'crm.agents', 'icon' => 'A'],
            ['section' => 'channels', 'label' => 'Channels', 'route' => 'crm.channels', 'icon' => 'O'],
            ['section' => 'reports', 'label' => 'Reports', 'route' => 'crm.reports', 'icon' => 'R'],
            ['section' => 'activity', 'label' => 'Activity Log', 'route' => 'crm.activity', 'icon' => 'L'],
            ['section' => 'notifications', 'label' => 'Notifications', 'route' => 'crm.notifications', 'icon' => 'N'],
            ['section' => 'settings', 'label' => 'Settings', 'route' => 'crm.settings', 'icon' => 'S'],
        ];
    }

    private function sectionTitle(string $section): string
    {
        return collect($this->navItems())->firstWhere('section', $section)['label'] ?? 'Dashboard';
    }

    private function visibleConversations(User $user): Builder
    {
        $query = Conversation::query();

        if (! $user->can('conversation.view_all')) {
            $query->where('assigned_to', $user->id);
        }

        return $query;
    }

    private function visibleMessages(User $user): Builder
    {
        return Message::query()->whereHas('conversation', function (Builder $query) use ($user): void {
            if (! $user->can('conversation.view_all')) {
                $query->where('assigned_to', $user->id);
            }
        });
    }

    private function percentageChange(int $current, ?int $previous): int
    {
        if (! $previous) {
            return $current > 0 ? 100 : 0;
        }

        return (int) round((($current - $previous) / $previous) * 100);
    }

    private function dailyMessageCounts(User $user, int $days): Collection
    {
        return collect(range($days - 1, 0))->map(function (int $offset) use ($user): int {
            $day = today()->subDays($offset);

            return $this->visibleMessages($user)->whereDate('created_at', $day)->count();
        });
    }

    private function conversationSummary(User $user, string $period): array
    {
        return match ($period) {
            'month' => $this->bucketedConversationSummary($user, collect(range(3, 0))->map(function (int $offset): array {
                $start = today()->subWeeks($offset)->startOfWeek();

                return [
                    'label' => $start->format('j M'),
                    'start' => $start,
                    'end' => $start->copy()->endOfWeek(),
                ];
            })),
            'year' => $this->bucketedConversationSummary($user, collect(range(11, 0))->map(function (int $offset): array {
                $start = today()->subMonthsNoOverflow($offset)->startOfMonth();

                return [
                    'label' => $start->format('M'),
                    'start' => $start,
                    'end' => $start->copy()->endOfMonth(),
                ];
            })),
            default => $this->weeklySummary($user),
        };
    }

    private function weeklySummary(User $user): array
    {
        $start = today()->subDays(6);
        $days = collect(range(0, 6))->map(fn (int $offset): Carbon => $start->copy()->addDays($offset));

        $bars = $days->map(function (Carbon $day) use ($user): array {
            $open = (clone $this->visibleConversations($user))->whereDate('created_at', $day)->where('status', 'open')->count();
            $pending = (clone $this->visibleConversations($user))->whereDate('created_at', $day)->where('status', 'pending')->count();
            $closed = (clone $this->visibleConversations($user))->whereDate('created_at', $day)->where('status', 'closed')->count();
            $total = max(1, $open + $pending + $closed);

            return [
                'label' => $day->format('D, j M'),
                'open' => $open,
                'pending' => $pending,
                'closed' => $closed,
                'total' => $open + $pending + $closed,
                'open_height' => ($open / $total) * 100,
                'pending_height' => ($pending / $total) * 100,
                'closed_height' => ($closed / $total) * 100,
            ];
        });

        return [
            'total' => $bars->sum('total'),
            'change' => $this->percentageChange($bars->sum('total'), (int) (clone $this->visibleConversations($user))
                ->whereBetween('created_at', [today()->subDays(13)->startOfDay(), today()->subDays(7)->endOfDay()])
                ->count()),
            'bars' => $bars,
        ];
    }

    private function bucketedConversationSummary(User $user, Collection $buckets): array
    {
        $bars = $buckets->map(function (array $bucket) use ($user): array {
            $open = (clone $this->visibleConversations($user))->whereBetween('created_at', [$bucket['start'], $bucket['end']])->where('status', 'open')->count();
            $pending = (clone $this->visibleConversations($user))->whereBetween('created_at', [$bucket['start'], $bucket['end']])->where('status', 'pending')->count();
            $closed = (clone $this->visibleConversations($user))->whereBetween('created_at', [$bucket['start'], $bucket['end']])->where('status', 'closed')->count();
            $total = max(1, $open + $pending + $closed);

            return [
                'label' => $bucket['label'],
                'open' => $open,
                'pending' => $pending,
                'closed' => $closed,
                'total' => $open + $pending + $closed,
                'open_height' => ($open / $total) * 100,
                'pending_height' => ($pending / $total) * 100,
                'closed_height' => ($closed / $total) * 100,
            ];
        });

        $firstStart = $buckets->first()['start'];
        $lastEnd = $buckets->last()['end'];
        $durationDays = max(1, $firstStart->diffInDays($lastEnd) + 1);

        return [
            'total' => $bars->sum('total'),
            'change' => $this->percentageChange($bars->sum('total'), (int) (clone $this->visibleConversations($user))
                ->whereBetween('created_at', [
                    $firstStart->copy()->subDays($durationDays),
                    $firstStart->copy()->subSecond(),
                ])
                ->count()),
            'bars' => $bars,
        ];
    }

    private function heatmap(User $user): array
    {
        $start = today()->subDays(27);

        $weeks = collect(range(0, 3))->map(function (int $weekIndex) use ($start, $user): array {
            return collect(range(0, 6))->map(function (int $dayIndex) use ($start, $weekIndex, $user): array {
                $day = $start->copy()->addDays(($weekIndex * 7) + $dayIndex);
                $count = $this->visibleMessages($user)->whereDate('created_at', $day)->count();

                return [
                    'date' => $day->toDateString(),
                    'count' => $count,
                    'tone' => min(4, (int) ceil($count / 2)),
                ];
            })->all();
        });

        return [
            'labels' => ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'],
            'weeks' => $weeks,
        ];
    }

    private function yearTrend(User $user): array
    {
        $years = collect(range((int) now()->subYears(4)->format('Y'), (int) now()->format('Y')));

        return [
            'labels' => $years,
            'values' => $years->map(fn (int $year): int => (clone $this->visibleConversations($user))->whereYear('created_at', $year)->count()),
        ];
    }

    private function allocation(User $user): Collection
    {
        $tags = Tag::query()
            ->withCount(['conversations' => function (Builder $query) use ($user): void {
                if (! $user->can('conversation.view_all')) {
                    $query->where('assigned_to', $user->id);
                }
            }])
            ->orderByDesc('conversations_count')
            ->limit(4)
            ->get();

        $max = max(1, (int) $tags->max('conversations_count'));

        return $tags->map(fn (Tag $tag): array => [
            'name' => $tag->name,
            'value' => (int) $tag->conversations_count,
            'percent' => ((int) $tag->conversations_count / $max) * 100,
        ]);
    }

    private function channelMetrics(User $user): Collection
    {
        $channels = CustomerChannel::query()
            ->selectRaw('channel, count(*) as customers_count')
            ->groupBy('channel')
            ->orderBy('channel')
            ->get()
            ->keyBy('channel');

        $messages = $this->visibleMessages($user)
            ->selectRaw('channel, count(*) as messages_count')
            ->groupBy('channel')
            ->get()
            ->keyBy('channel');

        $unreadMessages = $this->visibleMessages($user)
            ->where('sender_type', 'customer')
            ->whereHas('conversation', fn (Builder $query): Builder => $query->whereIn('status', ['open', 'pending']))
            ->selectRaw('channel, count(*) as unread_messages_count')
            ->groupBy('channel')
            ->get()
            ->keyBy('channel');

        return collect(['facebook', 'zalo'])->map(fn (string $channel): array => [
            'name' => ucfirst($channel),
            'customers' => (int) ($channels[$channel]->customers_count ?? 0),
            'messages' => (int) ($messages[$channel]->messages_count ?? 0),
            'unread_messages' => (int) ($unreadMessages[$channel]->unread_messages_count ?? 0),
        ]);
    }

    private function topCustomers(User $user): Collection
    {
        return Customer::query()
            ->withCount(['conversations' => function (Builder $query) use ($user): void {
                if (! $user->can('conversation.view_all')) {
                    $query->where('assigned_to', $user->id);
                }
            }])
            ->orderByDesc('conversations_count')
            ->limit(5)
            ->get();
    }

    private function agents(User $user): Collection
    {
        if (! $user->can('user.manage')) {
            return collect([$user->loadCount([
                'assignedConversations as open_conversations_count' => fn (Builder $query) => $query->where('status', 'open'),
            ])]);
        }

        return User::query()
            ->withCount([
                'assignedConversations as open_conversations_count' => fn (Builder $query) => $query->where('status', 'open'),
                'assignedConversations as closed_conversations_count' => fn (Builder $query) => $query->where('status', 'closed'),
            ])
            ->orderByDesc('open_conversations_count')
            ->limit(5)
            ->get();
    }

    private function activityLogs(User $user): Collection
    {
        return ActivityLog::query()
            ->with('user')
            ->when(! $user->can('conversation.view_all'), fn (Builder $query) => $query->where('user_id', $user->id))
            ->latest()
            ->limit(5)
            ->get();
    }

    private function topCustomer(User $user): array
    {
        $customer = Customer::query()
            ->withCount(['conversations' => function (Builder $query) use ($user): void {
                if (! $user->can('conversation.view_all')) {
                    $query->where('assigned_to', $user->id);
                }
            }])
            ->orderByDesc('conversations_count')
            ->first();

        if (! $customer) {
            return [
                'name' => 'No Customer',
                'subtitle' => 'CRM',
                'initial' => 'N',
                'activities' => 0,
                'bars' => collect(),
            ];
        }

        $messageQuery = $this->visibleMessages($user)->whereHas('conversation', fn (Builder $query): Builder => $query->where('customer_id', $customer->id));
        $activities = (clone $messageQuery)->count();
        $daily = collect(range(9, 0))->map(function (int $offset) use ($messageQuery): int {
            $day = today()->subDays($offset);

            return (clone $messageQuery)->whereDate('created_at', $day)->count();
        });
        $max = max(1, (int) $daily->max());

        return [
            'name' => $customer->name,
            'subtitle' => $customer->email ? 'Customer Account' : 'CRM Contact',
            'initial' => strtoupper(substr($customer->name, 0, 1)),
            'activities' => $activities,
            'bars' => $daily->map(fn (int $value): array => [
                'value' => $value,
                'height' => 30 + (($value / $max) * 70),
            ]),
        ];
    }

    private function sparkline(Collection $values, int $width, int $height): string
    {
        $max = max(1, (int) $values->max());
        $min = min(0, (int) $values->min());
        $range = max(1, $max - $min);
        $count = max(1, $values->count() - 1);

        return $values->values()->map(function (int $value, int $index) use ($width, $height, $min, $range, $count): string {
            $x = ($index / $count) * $width;
            $y = $height - ((($value - $min) / $range) * ($height - 10)) - 5;

            return round($x, 2).','.round($y, 2);
        })->implode(' ');
    }
}
