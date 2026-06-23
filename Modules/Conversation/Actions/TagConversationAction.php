<?php

namespace Modules\Conversation\Actions;

use App\Models\User;
use Modules\Conversation\Models\Conversation;
use Modules\Conversation\Services\ConversationService;

class TagConversationAction
{
    public function __construct(private readonly ConversationService $service)
    {
    }

    public function execute(Conversation $conversation, array $tags, User $actor): Conversation
    {
        return $this->service->syncTags($conversation, $tags, $actor);
    }
}