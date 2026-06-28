<?php

namespace Modules\Conversation\Events;

class ConversationResolved extends ConversationLifecycleEvent
{
    public function broadcastAs(): string
    {
        return 'conversation.resolved';
    }
}
