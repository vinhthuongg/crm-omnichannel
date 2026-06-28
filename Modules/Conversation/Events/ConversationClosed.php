<?php

namespace Modules\Conversation\Events;

class ConversationClosed extends ConversationLifecycleEvent
{
    public function broadcastAs(): string
    {
        return 'conversation.closed';
    }
}
