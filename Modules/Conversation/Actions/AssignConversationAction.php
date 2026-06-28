<?php

namespace Modules\Conversation\Actions;

use App\Models\User;
use Modules\Conversation\Models\Conversation;
use Modules\Conversation\Services\ConversationService;

class AssignConversationAction
{
    public function __construct(private readonly ConversationService $service)
    {
    }

    public function execute(Conversation $conversation, int $userId, User $actor): Conversation
    {
        return $this->service->assign($conversation, $userId, $actor);
    }

    public function transfer(Conversation $conversation, int $userId, User $actor): Conversation
    {
        return $this->service->transfer($conversation, $userId, $actor);
    }

    public function release(Conversation $conversation, User $actor): Conversation
    {
        return $this->service->release($conversation, $actor);
    }
}
