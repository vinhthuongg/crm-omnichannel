<?php

namespace Modules\Conversation\Events;

class ConversationReopened extends ConversationLifecycleEvent
{
    public function broadcastAs(): string
    {
        return 'conversation.reopened';
    }
}
