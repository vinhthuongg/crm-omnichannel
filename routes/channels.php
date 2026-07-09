<?php

use App\Models\User;
use Illuminate\Support\Facades\Broadcast;
use Modules\Conversation\Models\Conversation;
use Modules\Conversation\Services\ConversationVisibilityService;

Broadcast::channel('crm.conversations', fn (User $user): bool => $user->can('conversation.view_all'));
Broadcast::channel('crm.user.{userId}.conversations', fn (User $user, int $userId): bool => (int) $user->id === $userId);
Broadcast::channel('crm.conversation.{conversationId}', function (User $user, int $conversationId): bool {
    $conversation = Conversation::query()->find($conversationId);

    return $conversation !== null
        && app(ConversationVisibilityService::class)->canView($user, $conversation);
});
