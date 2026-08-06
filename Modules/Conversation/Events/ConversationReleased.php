<?php

namespace Modules\Conversation\Events;

class ConversationReleased extends ConversationLifecycleEvent
{
    /** Đặt tên realtime conversation.released khi hội thoại trở lại hàng chờ. */
    public function broadcastAs(): string
    {
        return 'conversation.released';
    }
}
