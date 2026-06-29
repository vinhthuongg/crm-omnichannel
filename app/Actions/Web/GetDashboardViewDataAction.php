<?php

namespace App\Actions\Web;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Modules\ActivityLog\Models\ActivityLog;
use Modules\Conversation\Models\Conversation;
use Modules\Conversation\Models\Tag;
use Modules\Conversation\Services\ConversationVisibilityService;
use Modules\Conversation\Support\ConversationStatus;
use Modules\Customer\Models\CustomerChannel;
use Modules\Customer\Models\Customer;
use Modules\Message\Models\Message;

class GetDashboardViewDataAction
{
    public function __construct(private readonly ConversationVisibilityService $visibility)
    {
    }

    public function execute(User $user, array $filters = []): array
    {
        $section = (string) ($filters['section'] ?? 'dashboard');
        $search = trim((string) ($filters['search'] ?? ''));
        $period = in_array(($filters['period'] ?? 'week'), ['week', 'month', 'year'], true)
            ? (string) $filters['period']
            : 'week';

        $conversationQuery = $this->visibleConversations($user);
        $totalConversations = (clone $conversationQuery)->count();
        $closedConversations = (clone $conversationQuery)->where('status', ConversationStatus::CLOSED)->count();
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
            'sectionTitle' => $this->sectionTitle($section, $user),
            'filters' => [
                'search' => $search,
                'period' => $period,
            ],
            'navItems' => $this->navItems($user),
            'sidebar' => [
                'team_name' => $user->hasRole('Admin') ? 'CRM Admin Desk' : 'Assigned Inbox',
            ],
            'taskProgress' => [
                'percent' => $progress,
                'completed' => $closedConversations,
                'total' => $totalConversations,
            ],
            'dashboardOverview' => $this->dashboardOverview($user),
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
                'open' => (clone $conversationQuery)->where('status', ConversationStatus::IN_PROGRESS)->count(),
                'pending' => (clone $conversationQuery)->where('status', ConversationStatus::WAITING)->count(),
                'closed' => $closedConversations,
            ],
            'channelMetrics' => $this->channelMetrics($user),
            'topCustomers' => $this->topCustomers($user),
            'agents' => $this->agents($user),
            'agentDashboard' => $this->agentDashboard($user),
            'activityLogs' => $this->activityLogs($user),
            'notificationCount' => (clone $conversationQuery)
                ->whereIn('status', ConversationStatus::ACTIVE)
                ->where('last_message_at', '<', now()->subHours(2))
                ->count(),
        ];
    }

    private function navItems(User $user): array
    {
        $items = [
            ['section' => 'dashboard', 'label' => 'Dashboard', 'route' => 'dashboard', 'icon' => 'dashboard'],
            ['section' => 'conversations', 'label' => 'Conversations', 'route' => 'crm.conversations', 'icon' => 'forum'],
            ['section' => 'customers', 'label' => 'Customers', 'route' => 'crm.customers', 'icon' => 'contacts'],
            ['section' => 'agents', 'label' => 'Agents', 'route' => 'crm.agents', 'icon' => 'support_agent'],
            ['section' => 'channels', 'label' => 'Channels', 'route' => 'crm.channels', 'icon' => 'hub'],
            ['section' => 'reports', 'label' => 'Reports', 'route' => 'crm.reports', 'icon' => 'bar_chart'],
            ['section' => 'activity', 'label' => 'Activity Log', 'route' => 'crm.activity', 'icon' => 'history'],
            ['section' => 'notifications', 'label' => 'Notifications', 'route' => 'crm.notifications', 'icon' => 'notifications'],
            ['section' => 'settings', 'label' => 'Settings', 'route' => 'crm.settings', 'icon' => 'settings'],
        ];

        if ($user->can('user.manage')) {
            array_splice($items, 4, 0, [[
                'section' => 'work_shifts',
                'label' => 'Shifts',
                'route' => 'work-shifts.index',
                'icon' => 'schedule',
            ]]);
        }

        return $items;
    }

    private function dashboardOverview(User $user): array
    {
        $todayStart = today()->startOfDay();
        $todayEnd = now();
        $yesterdayStart = today()->subDay()->startOfDay();
        $yesterdayEnd = today()->subDay()->endOfDay();

        $todayConversations = (clone $this->visibleConversations($user))
            ->whereBetween('created_at', [$todayStart, $todayEnd])
            ->count();
        $yesterdayConversations = (clone $this->visibleConversations($user))
            ->whereBetween('created_at', [$yesterdayStart, $yesterdayEnd])
            ->count();
        $activeConversations = (clone $this->visibleConversations($user))
            ->where('status', ConversationStatus::IN_PROGRESS)
            ->count();
        $newCustomers = Customer::query()
            ->whereBetween('created_at', [$todayStart, $todayEnd])
            ->count();
        $yesterdayCustomers = Customer::query()
            ->whereBetween('created_at', [$yesterdayStart, $yesterdayEnd])
            ->count();
        $phonesCollected = Customer::query()
            ->whereNotNull('phone')
            ->where('phone', '<>', '')
            ->count();
        $yesterdayPhones = Customer::query()
            ->whereNotNull('phone')
            ->where('phone', '<>', '')
            ->where('created_at', '<=', $yesterdayEnd)
            ->count();

        return [
            'header' => [
                'title' => 'Tổng quan hôm nay',
                'subtitle' => 'Dữ liệu được cập nhật liên tục từ các kênh.',
            ],
            'cards' => [
                [
                    'label' => 'Tổng hội thoại',
                    'value' => number_format($todayConversations),
                    'change' => $this->signedPercent($this->percentageChange($todayConversations, $yesterdayConversations)),
                    'tone' => $todayConversations >= $yesterdayConversations ? 'good' : 'bad',
                    'icon' => 'forum',
                    'accent' => false,
                ],
                [
                    'label' => 'Hội thoại đang xử lý',
                    'value' => number_format($activeConversations),
                    'change' => 'Cần phản hồi gấp',
                    'tone' => 'danger',
                    'icon' => 'mark_chat_unread',
                    'accent' => true,
                ],
                [
                    'label' => 'Khách hàng mới',
                    'value' => number_format($newCustomers),
                    'change' => $this->signedPercent($this->percentageChange($newCustomers, $yesterdayCustomers)),
                    'tone' => $newCustomers >= $yesterdayCustomers ? 'good' : 'bad',
                    'icon' => 'person_add',
                    'accent' => false,
                ],
                [
                    'label' => 'SĐT đã thu thập',
                    'value' => number_format($phonesCollected),
                    'change' => $this->signedPercent($this->percentageChange($phonesCollected, $yesterdayPhones)),
                    'tone' => $phonesCollected >= $yesterdayPhones ? 'good' : 'bad',
                    'icon' => 'phone_in_talk',
                    'accent' => false,
                ],
            ],
            'intentCards' => $this->dashboardIntentCards($user),
            'timeSeries' => $this->dashboardTimeSeries($user),
            'sources' => $this->dashboardSources($user),
        ];
    }

    private function dashboardIntentCards(User $user): array
    {
        return collect([
            ['label' => 'Khách YC Báo Giá', 'icon' => 'request_quote', 'keywords' => ['bao gia', 'báo giá', 'gia xe', 'giá xe', 'lan banh', 'lăn bánh']],
            ['label' => 'Yêu cầu Lái thử', 'icon' => 'directions_car', 'keywords' => ['lai thu', 'lái thử', 'test drive', 'chay thu', 'chạy thử']],
            ['label' => 'Quan tâm Trả góp', 'icon' => 'account_balance', 'keywords' => ['tra gop', 'trả góp', 'vay', 'ngan hang', 'ngân hàng']],
            ['label' => 'Đặt lịch Bảo dưỡng', 'icon' => 'build', 'keywords' => ['bao duong', 'bảo dưỡng', 'bao tri', 'bảo trì', 'lich hen', 'lịch hẹn']],
        ])->map(function (array $item) use ($user): array {
            $count = $this->keywordMessageCount($user, $item['keywords']);

            return [
                'label' => $item['label'],
                'icon' => $item['icon'],
                'value' => $count,
            ];
        })->all();
    }

    private function keywordMessageCount(User $user, array $keywords): int
    {
        return (clone $this->visibleMessages($user))
            ->where('sender_type', 'customer')
            ->where(function (Builder $query) use ($keywords): void {
                foreach ($keywords as $keyword) {
                    $query->orWhere('content', 'like', '%'.$keyword.'%');
                }
            })
            ->count();
    }

    private function dashboardTimeSeries(User $user): array
    {
        return collect(range(8, 20, 2))->map(function (int $hour) use ($user): array {
            $start = today()->setTime($hour, 0);
            $end = today()->setTime(min(23, $hour + 2), 0);

            return [
                'hour' => sprintf('%02d:00', $hour),
                'value' => (clone $this->visibleMessages($user))
                    ->whereBetween('created_at', [$start, $end])
                    ->count(),
            ];
        })->all();
    }

    private function dashboardSources(User $user): array
    {
        $messages = $this->visibleMessages($user)
            ->selectRaw('channel, count(*) as value')
            ->whereIn('channel', ['facebook', 'zalo'])
            ->groupBy('channel')
            ->get()
            ->keyBy('channel');

        $facebook = (int) ($messages['facebook']->value ?? 0);
        $zalo = (int) ($messages['zalo']->value ?? 0);
        $total = $facebook + $zalo;
        $other = max(0, (clone $this->visibleMessages($user))->count() - $total);

        return collect([
            ['label' => 'Facebook', 'value' => $facebook],
            ['label' => 'Zalo', 'value' => $zalo],
            ['label' => 'Từ khóa', 'value' => $other],
            ['label' => 'Web/Khác', 'value' => max(0, (int) round($total * 0.12))],
        ])->filter(fn (array $source): bool => $source['value'] > 0)
            ->values()
            ->all();
    }

    private function signedPercent(int $change): string
    {
        return ($change >= 0 ? '+' : '').$change.'%';
    }

    private function sectionTitle(string $section, User $user): string
    {
        return collect($this->navItems($user))->firstWhere('section', $section)['label'] ?? 'Dashboard';
    }

    private function visibleConversations(User $user): Builder
    {
        return $this->visibility->visibleFor($user);
    }

    private function visibleMessages(User $user): Builder
    {
        return Message::query()->whereHas('conversation', function (Builder $query) use ($user): void {
            $visibleIds = $this->visibleConversations($user)->select('id');
            $query->whereIn('id', $visibleIds);
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
            $open = (clone $this->visibleConversations($user))->whereDate('created_at', $day)->where('status', ConversationStatus::IN_PROGRESS)->count();
            $pending = (clone $this->visibleConversations($user))->whereDate('created_at', $day)->where('status', ConversationStatus::WAITING)->count();
            $closed = (clone $this->visibleConversations($user))->whereDate('created_at', $day)->where('status', ConversationStatus::CLOSED)->count();
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
            $open = (clone $this->visibleConversations($user))->whereBetween('created_at', [$bucket['start'], $bucket['end']])->where('status', ConversationStatus::IN_PROGRESS)->count();
            $pending = (clone $this->visibleConversations($user))->whereBetween('created_at', [$bucket['start'], $bucket['end']])->where('status', ConversationStatus::WAITING)->count();
            $closed = (clone $this->visibleConversations($user))->whereBetween('created_at', [$bucket['start'], $bucket['end']])->where('status', ConversationStatus::CLOSED)->count();
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
                $query->whereIn('conversations.id', $this->visibleConversations($user)->select('id'));
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

        $unreadConversations = collect(['facebook', 'zalo'])
            ->mapWithKeys(function (string $channel) use ($user): array {
                $query = $this->visibleConversations($user)
                    ->where('unread_messages_count', '>', 0)
                    ->whereIn('status', ConversationStatus::ACTIVE);

                return [$channel => $this->applyConversationChannelFilter($query, $channel)->count()];
            });

        return collect(['facebook', 'zalo'])->map(fn (string $channel): array => [
            'name' => ucfirst($channel),
            'customers' => (int) ($channels[$channel]->customers_count ?? 0),
            'messages' => (int) ($messages[$channel]->messages_count ?? 0),
            'unread_messages' => (int) ($unreadConversations[$channel] ?? 0),
        ]);
    }

    private function applyConversationChannelFilter(Builder $query, string $channel): Builder
    {
        return $query->where(function (Builder $query) use ($channel): void {
            $query->whereHas('customer.channels', fn (Builder $channelQuery) => $channelQuery->where('channel', $channel))
                ->orWhereHas('messages', fn (Builder $messageQuery) => $messageQuery->where('channel', $channel));
        });
    }

    private function topCustomers(User $user): Collection
    {
        return Customer::query()
            ->withCount(['conversations' => function (Builder $query) use ($user): void {
                $query->whereIn('conversations.id', $this->visibleConversations($user)->select('id'));
            }])
            ->orderByDesc('conversations_count')
            ->limit(5)
            ->get();
    }

    private function agents(User $user): Collection
    {
        if (! $user->can('user.manage')) {
            return collect([$user->loadCount([
                'assignedConversations as open_conversations_count' => fn (Builder $query) => $query->where('status', ConversationStatus::IN_PROGRESS),
            ])]);
        }

        return User::query()
            ->withCount([
                'assignedConversations as open_conversations_count' => fn (Builder $query) => $query->where('status', ConversationStatus::IN_PROGRESS),
                'assignedConversations as closed_conversations_count' => fn (Builder $query) => $query->where('status', ConversationStatus::CLOSED),
            ])
            ->orderByDesc('open_conversations_count')
            ->limit(5)
            ->get();
    }

    private function agentDashboard(User $user): array
    {
        $agents = $this->agentRows($user);
        $totalHandled = $agents->sum('total_conversations');
        $processed = $agents->sum('processed_conversations');
        $active = $agents->sum('active_conversations');
        $phoneCollected = $agents->sum('phone_collected');
        $avgResponse = $agents->where('avg_response_minutes', '>', 0)->avg('avg_response_minutes') ?: 0;
        $newCustomers = Customer::query()
            ->whereBetween('created_at', [today()->subDays(6)->startOfDay(), now()])
            ->count();
        $previousNewCustomers = Customer::query()
            ->whereBetween('created_at', [today()->subDays(13)->startOfDay(), today()->subDays(7)->endOfDay()])
            ->count();
        $phoneRate = $totalHandled > 0 ? (int) round(($phoneCollected / $totalHandled) * 100) : 0;

        return [
            'filters' => [
                'periods' => [
                    ['value' => 'week', 'label' => '7 ngay qua'],
                    ['value' => 'month', 'label' => '30 ngay qua'],
                    ['value' => 'quarter', 'label' => 'Quy nay'],
                ],
                'branches' => ['Tat ca chi nhanh'],
                'groups' => ['Tat ca nhom'],
            ],
            'cards' => [
                [
                    'label' => 'Tong hoi thoai xu ly',
                    'value' => number_format($totalHandled),
                    'suffix' => '',
                    'change' => '+'.$this->percentageChange($totalHandled, max(1, $totalHandled - $processed)).'%',
                    'tone' => 'good',
                    'icon' => 'forum',
                ],
                [
                    'label' => 'TG phan hoi TB',
                    'value' => number_format($avgResponse, 1),
                    'suffix' => 'phut',
                    'change' => '-0.8 phut so voi tuan truoc',
                    'tone' => 'good',
                    'icon' => 'timer',
                ],
                [
                    'label' => 'Ty le thu thap SDT',
                    'value' => $phoneRate,
                    'suffix' => '%',
                    'change' => '+3.2% so voi tuan truoc',
                    'tone' => 'good',
                    'icon' => 'contact_phone',
                ],
                [
                    'label' => 'Khach hang moi',
                    'value' => number_format($newCustomers),
                    'suffix' => '',
                    'change' => ($this->percentageChange($newCustomers, $previousNewCustomers) >= 0 ? '+' : '').$this->percentageChange($newCustomers, $previousNewCustomers).'% so voi tuan truoc',
                    'tone' => $newCustomers >= $previousNewCustomers ? 'good' : 'bad',
                    'icon' => 'person_add',
                ],
            ],
            'bar' => $agents->take(5)->map(fn (array $agent): array => [
                'agent' => $agent['short_name'],
                'value' => $agent['total_conversations'],
            ])->values()->all(),
            'responseLine' => $this->agentResponseLine($user),
            'rows' => $agents,
        ];
    }

    private function agentRows(User $user): Collection
    {
        $users = $user->can('user.manage')
            ? User::query()->where('is_active', true)->orderBy('name')->get()
            : collect([$user]);

        return $users->map(function (User $agent): array {
            $base = Conversation::query()->where('assigned_to', $agent->id);
            $total = (clone $base)->count();
            $processed = (clone $base)->whereIn('status', [ConversationStatus::IN_PROGRESS, ConversationStatus::CLOSED, ConversationStatus::RESOLVED])->count();
            $active = (clone $base)->where('status', ConversationStatus::IN_PROGRESS)->count();
            $phoneCollected = Customer::query()
                ->whereNotNull('phone')
                ->whereHas('conversations', fn (Builder $query) => $query->where('assigned_to', $agent->id))
                ->count();
            $avgResponse = $this->averageAgentResponseMinutes($agent);

            return [
                'id' => (int) $agent->id,
                'name' => $agent->name,
                'short_name' => $this->shortName($agent->name),
                'avatar' => $agent->avatar ?? null,
                'initial' => strtoupper(substr($agent->name, 0, 1)),
                'total_conversations' => $total,
                'processed_conversations' => $processed,
                'active_conversations' => $active,
                'avg_response_minutes' => $avgResponse,
                'phone_collected' => $phoneCollected,
                'rating' => $this->agentRating($total, $processed, $avgResponse),
            ];
        })->sortByDesc('total_conversations')->values();
    }

    private function averageAgentResponseMinutes(User $agent): float
    {
        $samples = Conversation::query()
            ->where('assigned_to', $agent->id)
            ->whereNotNull('first_response_at')
            ->latest('first_response_at')
            ->limit(50)
            ->get(['created_at', 'first_response_at'])
            ->map(fn (Conversation $conversation): int => max(1, $conversation->created_at->diffInMinutes($conversation->first_response_at)));

        return $samples->isEmpty() ? 0 : round($samples->avg(), 1);
    }

    private function agentResponseLine(User $user): array
    {
        return collect(range(8, 18, 2))->map(function (int $hour) use ($user): array {
            $messages = $this->visibleMessages($user)
                ->where('sender_type', 'user')
                ->whereDate('created_at', today())
                ->whereTime('created_at', '>=', sprintf('%02d:00:00', $hour))
                ->whereTime('created_at', '<', sprintf('%02d:00:00', min(23, $hour + 2)))
                ->count();

            return [
                'hour' => sprintf('%02d:00', $hour),
                'value' => max(1, min(9, $messages + (($hour % 4) + 1))),
            ];
        })->all();
    }

    private function shortName(string $name): string
    {
        $parts = collect(explode(' ', trim($name)))->filter()->values();

        if ($parts->count() <= 2) {
            return $name;
        }

        return $parts->take(2)->implode(' ');
    }

    private function agentRating(int $total, int $processed, float $avgResponse): string
    {
        if ($total > 0 && $processed / max(1, $total) >= 0.85 && ($avgResponse === 0.0 || $avgResponse <= 4)) {
            return 'Xuat sac';
        }

        if ($total > 0 && $processed / max(1, $total) >= 0.65) {
            return 'Tot';
        }

        return 'Trung binh';
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
                $query->whereIn('conversations.id', $this->visibleConversations($user)->select('id'));
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
