<?php

namespace Modules\Conversation\Events;

class ConversationClaimed extends ConversationLifecycleEvent
{
    public function broadcastAs(): string
    {
        return 'conversation.claimed';
    }
}
