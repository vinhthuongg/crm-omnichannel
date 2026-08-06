<?php

namespace Modules\Conversation\Events;

class ConversationAssigned extends ConversationLifecycleEvent
{
    /** Đặt tên realtime conversation.assigned khi hội thoại được giao thủ công. */
    public function broadcastAs(): string
    {
        return 'conversation.assigned';
    }
}
