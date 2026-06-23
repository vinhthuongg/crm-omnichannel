<?php

namespace Modules\Conversation\Actions;

use App\Models\User;
use Modules\Conversation\Models\Conversation;
use Modules\Conversation\Services\ConversationService;

class CloseConversationAction
{
    public function __construct(private readonly ConversationService $service)
    {
    }

    public function execute(Conversation $conversation, User $actor): Conversation
    {
        return $this->service->close($conversation, $actor);
    }
}