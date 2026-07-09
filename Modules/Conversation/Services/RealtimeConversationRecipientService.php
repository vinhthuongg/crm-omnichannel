<?php

namespace Modules\Conversation\Services;

use App\Models\User;
use Illuminate\Support\Collection;
use Modules\Conversation\Models\Conversation;

class RealtimeConversationRecipientService
{
    public function __construct(private readonly ConversationVisibilityService $visibility)
    {
    }

    public function recipientUserIds(Conversation $conversation): Collection
    {
        return User::query()
            ->where('is_active', true)
            ->get()
            ->filter(fn (User $user): bool => $this->visibility->canView($user, $conversation))
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values();
    }

    public function channelsFor(Conversation $conversation): array
    {
        return $this->recipientUserIds($conversation)
            ->map(fn (int $userId): string => 'crm.user.'.$userId.'.conversations')
            ->all();
    }
}
