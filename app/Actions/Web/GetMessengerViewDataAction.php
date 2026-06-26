<?php

namespace App\Actions\Web;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Modules\Conversation\Models\Conversation;
use Modules\Conversation\Models\Tag;
use Modules\Conversation\Services\WorkShiftService;

class GetMessengerViewDataAction
{
    private const INITIAL_MESSAGE_LIMIT = 10;

    public function __construct(private readonly WorkShiftService $shifts)
    {
    }

    public function execute(User $user, ?Conversation $selectedConversation = null, array $filters = []): array
    {
        $search = trim((string) ($filters['search'] ?? ''));
        $tag = trim((string) ($filters['tag'] ?? ''));
        $conversationQuery = $this->visibleConversations($user)
            ->with(['customer.channels', 'assignee', 'tags', 'messages' => fn ($query) => $query->latest()->limit(1)])
            ->when($search !== '', function (Builder $query) use ($search): void {
                $query->whereHas('customer', function (Builder $customerQuery) use ($search): void {
                    $customerQuery->where('name', 'like', "%{$search}%")
                        ->orWhere('phone', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%");
                });
            })
            ->when($tag !== '', function (Builder $query) use ($tag): void {
                $query->whereHas('tags', fn (Builder $tagQuery) => $tagQuery->where('name', $tag));
            })
            ->latest('last_message_at');

        $conversations = $conversationQuery->limit(30)->get();
        $activeConversation = $this->resolveActiveConversation($user, $selectedConversation, $conversations);
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
            'filters' => ['search' => $search, 'tag' => $tag],
            'tagPresets' => $this->tagPresets(),
            'allTags' => Tag::query()->orderBy('name')->get(),
            'allCustomerTags' => Tag::query()->orderBy('name')->get(),
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

        if ($user->can('user.manage')) {
            array_splice($items, 4, 0, [[
                'section' => 'work_shifts',
                'label' => 'Shifts',
                'route' => 'work-shifts.index',
                'icon' => 'T',
            ]]);
        }

        return $items;
    }

    private function visibleConversations(User $user): Builder
    {
        $query = Conversation::query();

        if (! $user->can('conversation.view_all')) {
            $currentShift = $this->shifts->currentShiftFor($user);

            $query->where(function (Builder $query) use ($user, $currentShift): void {
                $query->where('assigned_to', $user->id);

                if ($currentShift) {
                    $query->orWhere(function (Builder $query) use ($currentShift): void {
                        $query->whereNull('assigned_to')
                            ->where('work_shift_id', $currentShift->id);
                    });
                }
            });
        }

        return $query;
    }

    private function resolveActiveConversation(User $user, ?Conversation $selectedConversation, Collection $conversations): ?Conversation
    {
        if ($selectedConversation) {
            abort_unless($this->canViewConversation($user, $selectedConversation), 403);

            return $selectedConversation->load(['customer.channels', 'customer.notes.user', 'customer.tags', 'assignee', 'tags']);
        }

        return $conversations->first()?->load(['customer.channels', 'customer.notes.user', 'customer.tags', 'assignee', 'tags']);
    }

    private function tagPresets(): Collection
    {
        $defaults = collect([
            ['name' => 'Dang tu van', 'color' => '#e11d48'],
            ['name' => 'Goi lan 1', 'color' => '#16a34a'],
            ['name' => 'Goi lan 2', 'color' => '#2563eb'],
            ['name' => 'Huy', 'color' => '#64748b'],
            ['name' => 'Spam', 'color' => '#6b7280'],
            ['name' => 'Da mua', 'color' => '#059669'],
        ]);

        $saved = Tag::query()
            ->orderBy('name')
            ->get(['name', 'color'])
            ->map(fn (Tag $tag): array => ['name' => $tag->name, 'color' => $tag->color]);

        return $defaults
            ->merge($saved)
            ->unique('name')
            ->values();
    }

    private function profilePanel(?Conversation $conversation): array
    {
        if (! $conversation?->customer) {
            return [
                'facebook_profile_url' => '#',
                'details' => [],
                'notes' => [],
                'tags' => [],
            ];
        }

        $customer = $conversation->customer;
        $facebookChannel = $customer->channels?->firstWhere('channel', 'facebook');
        $facebookProfileUrl = $facebookChannel?->external_id ? 'https://www.facebook.com/'.$facebookChannel->external_id : '#';

        return [
            'facebook_profile_url' => $facebookProfileUrl,
            'details' => collect([
                ['label' => 'Ten cong khai', 'value' => $customer->name],
                ['label' => 'So dien thoai', 'value' => $customer->phone],
                ['label' => 'Email', 'value' => $customer->email],
                ['label' => 'Facebook PSID', 'value' => $facebookChannel?->external_id],
                ['label' => 'Kenh', 'value' => $facebookChannel ? ucfirst($facebookChannel->channel) : null],
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
            'tags' => $customer->tags
                ?->map(fn ($tag): array => [
                    'name' => $tag->name,
                    'color' => $tag->color ?: '#2563eb',
                ])
                ->values()
                ->all() ?? [],
        ];
    }

    private function canViewConversation(User $user, Conversation $conversation): bool
    {
        if ($user->can('conversation.view_all') || $conversation->assigned_to === $user->id) {
            return true;
        }

        return ! $conversation->assigned_to
            && $this->shifts->userIsInCurrentShift($user, $conversation->work_shift_id);
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
