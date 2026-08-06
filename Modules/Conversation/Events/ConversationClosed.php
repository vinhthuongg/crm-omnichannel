<?php

namespace Modules\Conversation\Events;

class ConversationClosed extends ConversationLifecycleEvent
{
    /** Đặt tên realtime conversation.closed khi hội thoại bị đóng. */
    public function broadcastAs(): string
    {
        return 'conversation.closed';
    }
}
