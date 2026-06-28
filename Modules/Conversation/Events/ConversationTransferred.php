<?php

namespace Modules\Conversation\Events;

class ConversationTransferred extends ConversationLifecycleEvent
{
    public function broadcastAs(): string
    {
        return 'conversation.transferred';
    }
}
