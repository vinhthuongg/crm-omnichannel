<?php

namespace App\Actions\Web;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\ActivityLog\Models\ActivityLog;
use Modules\Conversation\Models\Conversation;
use Modules\Conversation\Models\Tag;
use Modules\Conversation\Services\ConversationIntentService;
use Modules\Conversation\Services\ConversationVisibilityService;
use Modules\Conversation\Support\ConversationStatus;
use Modules\Customer\Models\CustomerChannel;
use Modules\Customer\Models\Customer;
use Modules\Facebook\Models\FacebookPage;

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

        $monthConversations = $this->whereConversationActivityBetween(
            clone $this->visibleConversations($user),
            now()->startOfMonth(),
            now()->endOfMonth(),
        )->count();
        $previousMonthConversations = $this->whereConversationActivityBetween(
            clone $this->visibleConversations($user),
            now()->subMonthNoOverflow()->startOfMonth(),
            now()->subMonthNoOverflow()->endOfMonth(),
        )->count();

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
                'value' => $monthConversations,
                'change' => $this->percentageChange($monthConversations, $previousMonthConversations),
                'sparkline' => $this->sparkline($this->dailyConversationCounts($user, 7), 160, 72),
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
            'channelManagement' => $this->channelManagement($user),
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

        $todayConversations = $this->whereConversationActivityBetween(
            clone $this->visibleConversations($user),
            $todayStart,
            $todayEnd,
        )->count();
        $yesterdayConversations = $this->whereConversationActivityBetween(
            clone $this->visibleConversations($user),
            $yesterdayStart,
            $yesterdayEnd,
        )->count();
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
        return $this->dashboardIntentCardsV2($user);

        return collect([
            ['label' => 'Khách YC Báo Giá', 'icon' => 'request_quote', 'keywords' => ['bao gia', 'báo giá', 'gia xe', 'giá xe', 'lan banh', 'lăn bánh']],
            ['label' => 'Yêu cầu Lái thử', 'icon' => 'directions_car', 'keywords' => ['lai thu', 'lái thử', 'test drive', 'chay thu', 'chạy thử']],
            ['label' => 'Quan tâm Trả góp', 'icon' => 'account_balance', 'keywords' => ['tra gop', 'trả góp', 'vay', 'ngan hang', 'ngân hàng']],
            ['label' => 'Đặt lịch Bảo dưỡng', 'icon' => 'build', 'keywords' => ['bao duong', 'bảo dưỡng', 'bao tri', 'bảo trì', 'lich hen', 'lịch hẹn']],
        ])->map(function (array $item) use ($user): array {
            $count = $this->keywordConversationCount($user, $item['keywords']);

            return [
                'label' => $item['label'],
                'icon' => $item['icon'],
                'value' => $count,
            ];
        })->all();
    }

    private function keywordConversationCount(User $user, array $keywords): int
    {
        return (clone $this->visibleConversations($user))
            ->whereHas('messages', function (Builder $query) use ($keywords): void {
                $query->where('sender_type', 'customer')
                    ->where(function (Builder $query) use ($keywords): void {
                        foreach ($keywords as $keyword) {
                            $query->orWhere('content', 'like', '%'.$keyword.'%');
                        }
                    });
            })
            ->count();
    }

    private function dashboardIntentCardsV2(User $user): array
    {
        return collect([
            ['label' => 'Khach YC Bao Gia', 'icon' => 'request_quote', 'tag' => ConversationIntentService::TAG_QUOTE, 'keywords' => ['bao gia', 'gia xe', 'lan banh']],
            ['label' => 'Yeu cau Lai thu', 'icon' => 'directions_car', 'tag' => ConversationIntentService::TAG_TEST_DRIVE, 'keywords' => ['lai thu', 'test drive', 'chay thu']],
            ['label' => 'Quan tam Tra gop', 'icon' => 'account_balance', 'tag' => ConversationIntentService::TAG_INSTALLMENT, 'keywords' => ['tra gop', 'vay', 'ngan hang', 'lai suat']],
            ['label' => 'Khach Dat Lich', 'icon' => 'event_available', 'tag' => ConversationIntentService::TAG_APPOINTMENT, 'keywords' => ['dat lich', 'lich hen', 'ghe showroom']],
            ['label' => 'Dat lich Bao duong', 'icon' => 'build', 'tag' => ConversationIntentService::TAG_MAINTENANCE, 'keywords' => ['bao duong', 'bao tri', 'xuong dich vu']],
        ])->map(fn (array $item): array => [
            'label' => $item['label'],
            'icon' => $item['icon'],
            'value' => $this->intentConversationCount($user, $item['tag'], $item['keywords']),
        ])->all();
    }

    private function intentConversationCount(User $user, string $tagName, array $keywords): int
    {
        return (clone $this->visibleConversations($user))
            ->where(function (Builder $query) use ($tagName, $keywords): void {
                $query->whereHas('tags', fn (Builder $tagQuery) => $tagQuery->where('name', $tagName))
                    ->orWhereHas('messages', function (Builder $messageQuery) use ($keywords): void {
                        $messageQuery->whereIn('sender_type', ['customer', 'system'])
                            ->where(function (Builder $keywordQuery) use ($keywords): void {
                                foreach ($keywords as $keyword) {
                                    $keywordQuery->orWhere('content', 'like', '%'.$keyword.'%');
                                }
                            });
                    });
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
                'value' => $this->whereConversationActivityBetween(
                    clone $this->visibleConversations($user),
                    $start,
                    $end,
                )->count(),
            ];
        })->all();
    }

    private function dashboardSources(User $user): array
    {
        $facebook = $this->applyConversationChannelFilter(clone $this->visibleConversations($user), 'facebook')->count();
        $zalo = $this->applyConversationChannelFilter(clone $this->visibleConversations($user), 'zalo')->count();

        return collect([
            ['label' => 'Facebook', 'value' => $facebook],
            ['label' => 'Zalo', 'value' => $zalo],
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

    private function conversationActivityColumn(): \Illuminate\Database\Query\Expression
    {
        return DB::raw('COALESCE(last_message_at, created_at)');
    }

    private function whereConversationActivityBetween(Builder $query, Carbon $start, Carbon $end): Builder
    {
        return $query->whereBetween($this->conversationActivityColumn(), [$start, $end]);
    }

    private function whereConversationActivityDate(Builder $query, Carbon $day): Builder
    {
        return $query->whereDate($this->conversationActivityColumn(), $day);
    }

    private function percentageChange(int $current, ?int $previous): int
    {
        if (! $previous) {
            return $current > 0 ? 100 : 0;
        }

        return (int) round((($current - $previous) / $previous) * 100);
    }

    private function dailyConversationCounts(User $user, int $days): Collection
    {
        return collect(range($days - 1, 0))->map(function (int $offset) use ($user): int {
            $day = today()->subDays($offset);

            return $this->whereConversationActivityDate(clone $this->visibleConversations($user), $day)->count();
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
            $open = $this->whereConversationActivityDate(clone $this->visibleConversations($user), $day)->where('status', ConversationStatus::IN_PROGRESS)->count();
            $pending = $this->whereConversationActivityDate(clone $this->visibleConversations($user), $day)->where('status', ConversationStatus::WAITING)->count();
            $closed = $this->whereConversationActivityDate(clone $this->visibleConversations($user), $day)->where('status', ConversationStatus::CLOSED)->count();
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
                ->whereBetween($this->conversationActivityColumn(), [today()->subDays(13)->startOfDay(), today()->subDays(7)->endOfDay()])
                ->count()),
            'bars' => $bars,
        ];
    }

    private function bucketedConversationSummary(User $user, Collection $buckets): array
    {
        $bars = $buckets->map(function (array $bucket) use ($user): array {
            $open = $this->whereConversationActivityBetween(clone $this->visibleConversations($user), $bucket['start'], $bucket['end'])->where('status', ConversationStatus::IN_PROGRESS)->count();
            $pending = $this->whereConversationActivityBetween(clone $this->visibleConversations($user), $bucket['start'], $bucket['end'])->where('status', ConversationStatus::WAITING)->count();
            $closed = $this->whereConversationActivityBetween(clone $this->visibleConversations($user), $bucket['start'], $bucket['end'])->where('status', ConversationStatus::CLOSED)->count();
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
                ->whereBetween($this->conversationActivityColumn(), [
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
                $count = $this->whereConversationActivityDate(clone $this->visibleConversations($user), $day)->count();

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
            'values' => $years->map(fn (int $year): int => $this->whereConversationActivityBetween(
                clone $this->visibleConversations($user),
                Carbon::create($year)->startOfYear(),
                Carbon::create($year)->endOfYear(),
            )->count()),
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
            'conversations' => $this->applyConversationChannelFilter(clone $this->visibleConversations($user), $channel)->count(),
            'unread_messages' => (int) ($unreadConversations[$channel] ?? 0),
        ]);
    }

    private function channelManagement(User $user): array
    {
        $facebookPage = FacebookPage::query()
            ->latest('updated_at')
            ->first();
        $facebookConnected = (bool) $facebookPage;
        $zaloConnected = trim((string) config('services.zalo.access_token')) !== '';

        return [
            'title' => 'Quản lý kết nối',
            'subtitle' => 'Quản lý và đồng bộ các kênh giao tiếp đa phương tiện.',
            'cards' => [
                [
                    'key' => 'facebook',
                    'title' => 'Facebook Page',
                    'icon' => 'thumb_up',
                    'icon_label' => null,
                    'connected' => $facebookConnected,
                    'status' => $facebookConnected ? 'Đang hoạt động' : 'Mất kết nối',
                    'status_tone' => $facebookConnected ? 'healthy' : 'failed',
                    'account' => $facebookPage?->page_name ?: 'Chưa kết nối',
                    'last_sync' => $this->lastChannelSync('facebook') ?: 'Chưa có',
                    'webhook' => $facebookPage?->subscribed_at && ($facebookPage->token_status ?? 'valid') !== 'invalid' ? 'HEALTHY' : 'FAILED',
                    'webhook_tone' => $facebookPage?->subscribed_at && ($facebookPage->token_status ?? 'valid') !== 'invalid' ? 'healthy' : 'failed',
                    'connect_url' => route('facebook.redirect'),
                    'sync_url' => $facebookPage ? route('facebook.pages.sync-messages', $facebookPage) : null,
                    'menu' => true,
                ],
                [
                    'key' => 'zalo',
                    'title' => 'Zalo OA',
                    'icon' => null,
                    'icon_label' => 'Zalo',
                    'connected' => $zaloConnected,
                    'status' => $zaloConnected ? 'Đang hoạt động' : 'Mất kết nối',
                    'status_tone' => $zaloConnected ? 'healthy' : 'failed',
                    'account' => $zaloConnected ? (string) config('services.zalo.oa_name', 'Zalo OA') : 'Chưa kết nối',
                    'last_sync' => $this->lastChannelSync('zalo') ?: 'Chưa có',
                    'webhook' => $zaloConnected ? 'HEALTHY' : 'FAILED',
                    'webhook_tone' => $zaloConnected ? 'healthy' : 'failed',
                    'connect_url' => route('crm.settings', ['panel' => 'zalo']),
                    'sync_url' => null,
                    'menu' => false,
                ],
            ],
        ];
    }

    private function lastChannelSync(string $channel): ?string
    {
        $syncedAt = $this->applyConversationChannelFilter(Conversation::query(), $channel)
            ->latest('last_message_at')
            ->value('last_message_at');

        return $syncedAt ? Carbon::parse($syncedAt)->format('H:i d/m/Y') : null;
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
            'cards' => [
                [
                    'label' => 'Tong hoi thoai xu ly',
                    'value' => number_format($totalHandled),
                    'suffix' => '',
                    'change' => number_format($processed).' da xu ly',
                    'tone' => 'good',
                    'icon' => 'forum',
                ],
                [
                    'label' => 'TG phan hoi TB',
                    'value' => number_format($avgResponse, 1),
                    'suffix' => 'phut',
                    'change' => 'Tinh tu hoi thoai co phan hoi',
                    'tone' => 'good',
                    'icon' => 'timer',
                ],
                [
                    'label' => 'Ty le thu thap SDT',
                    'value' => $phoneRate,
                    'suffix' => '%',
                    'change' => number_format($phoneCollected).' so dien thoai',
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
            $conversations = (clone $this->visibleConversations($user))
                ->whereNotNull('first_response_at')
                ->whereDate('created_at', today())
                ->whereTime('created_at', '>=', sprintf('%02d:00:00', $hour))
                ->whereTime('created_at', '<', sprintf('%02d:00:00', min(23, $hour + 2)))
                ->get(['created_at', 'first_response_at']);

            return [
                'hour' => sprintf('%02d:00', $hour),
                'value' => round($conversations->map(
                    fn (Conversation $conversation): int => max(1, $conversation->created_at->diffInMinutes($conversation->first_response_at))
                )->avg() ?: 0, 1),
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
            ->limit(24)
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

        $conversationQuery = (clone $this->visibleConversations($user))->where('customer_id', $customer->id);
        $activities = (clone $conversationQuery)->count();
        $daily = collect(range(9, 0))->map(function (int $offset) use ($conversationQuery): int {
            $day = today()->subDays($offset);

            return $this->whereConversationActivityDate(clone $conversationQuery, $day)->count();
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
