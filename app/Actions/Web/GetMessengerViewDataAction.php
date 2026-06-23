<?php

namespace App\Actions\Web;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Modules\Conversation\Models\Conversation;

class GetMessengerViewDataAction
{
    public function execute(User $user, ?Conversation $selectedConversation = null, array $filters = []): array
    {
        $search = trim((string) ($filters['search'] ?? ''));
        $conversationQuery = $this->visibleConversations($user)
            ->with(['customer.channels', 'assignee', 'messages' => fn ($query) => $query->latest()->limit(1)])
            ->when($search !== '', function (Builder $query) use ($search): void {
                $query->whereHas('customer', function (Builder $customerQuery) use ($search): void {
                    $customerQuery->where('name', 'like', "%{$search}%")
                        ->orWhere('phone', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%");
                });
            })
            ->latest('last_message_at');

        $conversations = $conversationQuery->limit(30)->get();
        $activeConversation = $this->resolveActiveConversation($user, $selectedConversation, $conversations);
        $messages = $activeConversation
            ? $this->messageTimeline($activeConversation, $user)
            : collect();

        return [
            'currentUser' => $user,
            'activeSection' => 'conversations',
            'navItems' => $this->navItems(),
            'sidebar' => [
                'team_name' => $user->hasRole('Admin') ? 'CRM Admin Desk' : 'Assigned Inbox',
            ],
            'filters' => ['search' => $search],
            'conversations' => $conversations,
            'activeConversation' => $activeConversation,
            'messages' => $messages,
            'activeChannel' => $activeConversation?->messages()->latest()->value('channel')
                ?? $activeConversation?->customer?->channels?->first()?->channel
                ?? 'facebook',
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

    private function visibleConversations(User $user): Builder
    {
        $query = Conversation::query();

        if (! $user->can('conversation.view_all')) {
            $query->where('assigned_to', $user->id);
        }

        return $query;
    }

    private function resolveActiveConversation(User $user, ?Conversation $selectedConversation, Collection $conversations): ?Conversation
    {
        if ($selectedConversation) {
            abort_unless($user->can('conversation.view_all') || $selectedConversation->assigned_to === $user->id, 403);

            return $selectedConversation->load(['customer.channels', 'assignee']);
        }

        return $conversations->first()?->load(['customer.channels', 'assignee']);
    }

    private function messageTimeline(Conversation $conversation, User $currentUser): Collection
    {
        $messages = $conversation->messages()->oldest()->get();
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
                'content' => $message->content,
                'message_type' => $message->message_type,
                'attachments' => $this->normalizedAttachments($message->attachments ?? []),
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
                $type = $type !== '' ? $type : $this->attachmentType($mimeType);
                $url = (string) (data_get($attachment, 'url') ?: data_get($attachment, 'payload.url', ''));
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

    private function attachmentType(string $mimeType): string
    {
        return match (true) {
            str_starts_with($mimeType, 'image/') => 'image',
            str_starts_with($mimeType, 'video/') => 'video',
            str_starts_with($mimeType, 'audio/') => 'audio',
            default => 'file',
        };
    }
}
