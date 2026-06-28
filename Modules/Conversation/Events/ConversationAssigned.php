<?php

namespace Modules\Conversation\Events;

class ConversationAssigned extends ConversationLifecycleEvent
{
    public function broadcastAs(): string
    {
        return 'conversation.assigned';
    }
}
