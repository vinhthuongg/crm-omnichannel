<?php

namespace Modules\Conversation\Events;

class ConversationReleased extends ConversationLifecycleEvent
{
    public function broadcastAs(): string
    {
        return 'conversation.released';
    }
}
