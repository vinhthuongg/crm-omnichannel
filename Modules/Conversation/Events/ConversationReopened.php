<?php

namespace Modules\Conversation\Events;

class ConversationReopened extends ConversationLifecycleEvent
{
    /** Đặt tên realtime conversation.reopened khi hội thoại được mở lại. */
    public function broadcastAs(): string
    {
        return 'conversation.reopened';
    }
}
