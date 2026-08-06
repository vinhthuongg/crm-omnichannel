<?php

namespace Modules\Conversation\Events;

class ConversationTransferred extends ConversationLifecycleEvent
{
    /** Đặt tên realtime conversation.transferred khi đổi người phụ trách. */
    public function broadcastAs(): string
    {
        return 'conversation.transferred';
    }
}
