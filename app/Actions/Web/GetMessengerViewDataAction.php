<?php

namespace App\Actions\Web;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Modules\Conversation\Models\Conversation;
use Modules\Conversation\Models\Tag;
use Modules\Conversation\Services\ConversationVisibilityService;
use Modules\Conversation\Services\WorkShiftService;

class GetMessengerViewDataAction
{
    private const INITIAL_MESSAGE_LIMIT = 10;

    public function __construct(
        private readonly WorkShiftService $shifts,
        private readonly ConversationVisibilityService $visibility,
    ) {
    }

    public function execute(User $user, ?Conversation $selectedConversation = null, array $filters = []): array
    {
        $search = trim((string) ($filters['search'] ?? ''));
        $tag = trim((string) ($filters['tag'] ?? ''));
        $channel = in_array(($filters['channel'] ?? 'all'), ['facebook', 'zalo'], true)
            ? (string) $filters['channel']
            : 'all';
        $status = in_array(($filters['status'] ?? 'all'), ['unread', 'mine'], true)
            ? (string) $filters['status']
            : 'all';
        $conversationQuery = $this->visibleConversations($user)
            ->with(['customer.channels', 'customer.tags', 'assignee', 'tags', 'messages' => fn ($query) => $query->latest()->limit(1)])
            ->when($search !== '', function (Builder $query) use ($search): void {
                $query->whereHas('customer', function (Builder $customerQuery) use ($search): void {
                    $customerQuery->where('name', 'like', "%{$search}%")
                        ->orWhere('phone', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%");
                });
            })
            ->when($tag !== '', function (Builder $query) use ($tag): void {
                $query->whereHas('tags', fn (Builder $tagQuery) => $tagQuery->where('name', $tag));
            });

        $conversationQuery = $this->applyStatusFilter($this->applyChannelFilter($conversationQuery, $channel), $status, $user)
            ->latest('last_message_at');

        $conversations = $conversationQuery->limit(30)->get();
        $activeConversation = $this->resolveActiveConversation($user, $selectedConversation);
        $messages = $activeConversation
            ? $this->messageTimeline($activeConversation, $user)
            : collect();
        $oldestMessageId = (int) ($messages->first()['id'] ?? 0);

        return [
            'currentUser' => $user,
            'activeSection' => 'conversations',
            'navItems' => $this->navItems($user),
            'sidebar' => [
                'team_name' => $user->hasRole('Admin') ? 'CRM Admin Desk' : 'Assigned Inbox',
            ],
            'filters' => ['search' => $search, 'tag' => $tag, 'channel' => $channel, 'status' => $status],
            'inboxChannels' => $this->inboxChannels($user, $search, $tag, $channel, $status),
            'inboxStatuses' => $this->inboxStatuses($user, $search, $tag, $channel, $status),
            'tagPresets' => $this->tagPresets(),
            'allTags' => $this->allTags(),
            'allCustomerTags' => $this->allTags(),
            'tagManager' => [
                'index_url' => route('crm.conversation-tags.index'),
                'store_url' => route('crm.conversation-tags.store'),
            ],
            'assignableAgents' => $this->assignableAgents($user),
            'conversations' => $conversations,
            'activeConversation' => $activeConversation,
            'profilePanel' => $this->profilePanel($activeConversation),
            'messages' => $messages,
            'hasOlderMessages' => $activeConversation && $oldestMessageId > 0
                ? $activeConversation->messages()->where('id', '<', $oldestMessageId)->exists()
                : false,
            'activeChannel' => $activeConversation?->messages()
                ->whereIn('channel', ['facebook', 'zalo'])
                ->latest()
                ->value('channel')
                ?? $activeConversation?->customer?->channels?->first()?->channel
                ?? 'facebook',
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

    private function assignableAgents(User $user): Collection
    {
        if (! $user->can('conversation.assign') && ! $user->can('conversation.transfer')) {
            return collect();
        }

        return User::query()
            ->permission('conversation.reply')
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'email']);
    }

    private function visibleConversations(User $user): Builder
    {
        return $this->visibility->visibleFor($user);
    }

    private function applyChannelFilter(Builder $query, string $channel): Builder
    {
        if (! in_array($channel, ['facebook', 'zalo'], true)) {
            return $query;
        }

        return $query->where(function (Builder $query) use ($channel): void {
            $query->whereHas('customer.channels', fn (Builder $channelQuery) => $channelQuery->where('channel', $channel))
                ->orWhereHas('messages', fn (Builder $messageQuery) => $messageQuery->where('channel', $channel));
        });
    }

    private function applyStatusFilter(Builder $query, string $status, User $user): Builder
    {
        return match ($status) {
            'unread' => $query->where('unread_messages_count', '>', 0),
            'mine' => $query->where('assigned_to', $user->id),
            default => $query,
        };
    }

    private function inboxChannels(User $user, string $search, string $tag, string $activeChannel, string $status): array
    {
        return collect([
            ['key' => 'all', 'label' => 'Tat ca'],
            ['key' => 'facebook', 'label' => 'Facebook'],
            ['key' => 'zalo', 'label' => 'Zalo'],
        ])->map(function (array $item) use ($user, $search, $tag, $activeChannel, $status): array {
            $query = $this->visibleConversations($user)
                ->when($search !== '', function (Builder $query) use ($search): void {
                    $query->whereHas('customer', function (Builder $customerQuery) use ($search): void {
                        $customerQuery->where('name', 'like', "%{$search}%")
                            ->orWhere('phone', 'like', "%{$search}%")
                            ->orWhere('email', 'like', "%{$search}%");
                    });
                })
                ->when($tag !== '', function (Builder $query) use ($tag): void {
                    $query->whereHas('tags', fn (Builder $tagQuery) => $tagQuery->where('name', $tag));
                });

            $query = $this->applyStatusFilter($query, $status, $user);

            return [
                ...$item,
                'active' => $activeChannel === $item['key'],
                'unread' => (int) $this->applyChannelFilter($query, $item['key'])
                    ->where('unread_messages_count', '>', 0)
                    ->count(),
            ];
        })->all();
    }

    private function inboxStatuses(User $user, string $search, string $tag, string $channel, string $activeStatus): array
    {
        return collect([
            ['key' => 'all', 'label' => 'Tat ca'],
            ['key' => 'unread', 'label' => 'Chua doc'],
            ['key' => 'mine', 'label' => 'Cua toi'],
        ])->map(function (array $item) use ($user, $search, $tag, $channel, $activeStatus): array {
            $query = $this->visibleConversations($user)
                ->when($search !== '', function (Builder $query) use ($search): void {
                    $query->whereHas('customer', function (Builder $customerQuery) use ($search): void {
                        $customerQuery->where('name', 'like', "%{$search}%")
                            ->orWhere('phone', 'like', "%{$search}%")
                            ->orWhere('email', 'like', "%{$search}%");
                    });
                })
                ->when($tag !== '', function (Builder $query) use ($tag): void {
                    $query->whereHas('tags', fn (Builder $tagQuery) => $tagQuery->where('name', $tag));
                });

            $query = $this->applyChannelFilter($query, $channel);

            return [
                ...$item,
                'active' => $activeStatus === $item['key'],
                'count' => (int) $this->applyStatusFilter($query, $item['key'], $user)->count(),
            ];
        })->all();
    }

    private function resolveActiveConversation(User $user, ?Conversation $selectedConversation): ?Conversation
    {
        if ($selectedConversation) {
            abort_unless($this->canViewConversation($user, $selectedConversation), 403);

            return $selectedConversation->load(['customer.channels', 'customer.notes.user', 'customer.tags', 'assignee', 'tags']);
        }

        return null;
    }

    private function tagPresets(): Collection
    {
        return $this->allTags()
            ->map(fn (Tag $tag): array => [
                'id' => (int) $tag->id,
                'name' => $tag->name,
                'color' => $tag->color,
                'is_default' => (bool) $tag->is_default,
            ]);
    }

    private function allTags(): Collection
    {
        Tag::ensureDefaults();

        return Tag::query()
            ->orderByDesc('is_default')
            ->orderBy('name')
            ->get();
    }

    private function profilePanel(?Conversation $conversation): array
    {
        if (! $conversation?->customer) {
            return [
                'facebook_profile_url' => '#',
                'contact' => [
                    'name' => '',
                    'phone' => '',
                    'email' => '',
                    'channel' => '',
                ],
                'details' => [],
                'notes' => [],
                'tags' => [],
            ];
        }

        $customer = $conversation->customer;
        $primaryChannel = $customer->channels?->first();

        return [
            'facebook_profile_url' => $this->facebookProfileUrl($conversation),
            'contact' => [
                'name' => $customer->name ?? '',
                'phone' => $customer->phone ?? '',
                'email' => $customer->email ?? '',
                'channel' => $primaryChannel?->channel ? ucfirst($primaryChannel->channel) : '',
            ],
            'details' => collect([
                ['label' => 'Ten cong khai', 'value' => $customer->name],
                ['label' => 'So dien thoai', 'value' => $customer->phone],
                ['label' => 'Email', 'value' => $customer->email],
                ['label' => 'Kenh', 'value' => $primaryChannel?->channel ? ucfirst($primaryChannel->channel) : null],
            ])
                ->filter(fn (array $detail): bool => filled($detail['value']))
                ->values()
                ->all(),
            'notes' => $customer->notes
                ?->sortByDesc('created_at')
                ->map(fn ($note): array => [
                    'body' => $note->body,
                    'author' => $note->user?->name ?? 'Admin',
                    'created_at' => $note->created_at?->format('H:i d/m/Y'),
                ])
                ->values()
                ->all() ?? [],
            'tags' => $conversation->tags
                ?->take(1)
                ->map(fn ($tag): array => [
                    'name' => $tag->name,
                    'color' => $tag->color ?: '#2563eb',
                ])
                ->values()
                ->all() ?? [],
        ];
    }

    private function facebookProfileUrl(Conversation $conversation): string
    {
        $customer = $conversation->customer;
        $facebookChannel = $customer?->channels?->firstWhere('channel', 'facebook');
        $metadata = (array) ($facebookChannel?->metadata ?? []);
        $profileUrl = (string) (
            data_get($metadata, 'profile.link')
            ?: data_get($metadata, 'profile.url')
            ?: data_get($metadata, 'profile.profile_url')
            ?: data_get($metadata, 'profile_url')
            ?: data_get($metadata, 'link')
        );

        if (filter_var($profileUrl, FILTER_VALIDATE_URL)) {
            return $profileUrl;
        }

        return '#';
    }

    private function canViewConversation(User $user, Conversation $conversation): bool
    {
        if ($user->can('conversation.view_all') || $conversation->assigned_to === $user->id) {
            return true;
        }

        return $this->visibility->canView($user, $conversation);
    }

    private function messageTimeline(Conversation $conversation, User $currentUser): Collection
    {
        $messages = $conversation->messages()
            ->latest()
            ->limit(self::INITIAL_MESSAGE_LIMIT)
            ->get()
            ->reverse()
            ->values();
        $userIds = $messages->where('sender_type', 'user')->pluck('sender_id')->filter()->unique();
        $users = User::query()->whereIn('id', $userIds)->get()->keyBy('id');

        return $messages->map(function ($message) use ($conversation, $currentUser, $users): array {
            $senderName = match ($message->sender_type) {
                'customer' => $conversation->customer?->name ?? 'Customer',
                'user' => $users[$message->sender_id]?->name ?? 'Agent',
                default => 'System',
            };

            return [
                'id' => $message->id,
                'sender_type' => $message->sender_type,
                'sender_name' => $senderName,
                'sender_avatar' => $message->sender_type === 'customer' ? $conversation->customer?->avatar : null,
                'is_mine' => $message->sender_type === 'user' && (int) $message->sender_id === (int) $currentUser->id,
                'channel' => $message->channel,
                'content' => $message->recalled_at ? null : $message->content,
                'message_type' => $message->message_type,
                'attachments' => $message->recalled_at ? [] : $this->normalizedAttachments($message->attachments ?? []),
                'is_recalled' => (bool) $message->recalled_at,
                'recalled_at' => $message->recalled_at,
                'created_at' => $message->created_at,
            ];
        });
    }

    private function normalizedAttachments(array $attachments): array
    {
        return collect($attachments)
            ->reject(fn (array $attachment): bool => ($attachment['type'] ?? '') === 'quick_reply')
            ->map(function (array $attachment): array {
                $mimeType = (string) data_get($attachment, 'mime_type', '');
                $type = (string) data_get($attachment, 'type', '');
                $url = (string) (
                    data_get($attachment, 'payload.image_data.url')
                    ?: data_get($attachment, 'payload.video_data.url')
                    ?: data_get($attachment, 'payload.audio_data.url')
                    ?: data_get($attachment, 'url')
                    ?: data_get($attachment, 'payload.url')
                    ?: data_get($attachment, 'payload.file_url')
                );
                $type = $this->attachmentType($mimeType, $type, $attachment);
                $name = (string) data_get($attachment, 'name', '');

                if ($name === '' && $url !== '') {
                    $name = basename((string) parse_url($url, PHP_URL_PATH));
                }

                return array_merge($attachment, [
                    'name' => $name ?: ucfirst($type),
                    'url' => $url,
                    'type' => $type,
                ]);
            })
            ->filter(fn (array $attachment): bool => $attachment['url'] !== '')
            ->unique('url')
            ->values()
            ->all();
    }

    private function attachmentType(string $mimeType, string $type = '', array $attachment = []): string
    {
        return match (true) {
            data_get($attachment, 'payload.image_data.url') !== null => 'image',
            data_get($attachment, 'payload.video_data.url') !== null => 'video',
            data_get($attachment, 'payload.audio_data.url') !== null => 'audio',
            str_starts_with($mimeType, 'image/') => 'image',
            str_starts_with($mimeType, 'video/') => 'video',
            str_starts_with($mimeType, 'audio/') => 'audio',
            $type !== '' => $type,
            default => 'file',
        };
    }
}
