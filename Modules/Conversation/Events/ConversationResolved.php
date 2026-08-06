<?php

namespace Modules\Conversation\Events;

class ConversationResolved extends ConversationLifecycleEvent
{
    /** Đặt tên realtime conversation.resolved khi hội thoại được giải quyết. */
    public function broadcastAs(): string
    {
        return 'conversation.resolved';
    }
}
