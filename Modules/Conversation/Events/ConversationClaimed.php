<?php

namespace Modules\Conversation\Events;

class ConversationClaimed extends ConversationLifecycleEvent
{
    /** Đặt tên realtime conversation.claimed khi nhân viên tự nhận hội thoại. */
    public function broadcastAs(): string
    {
        return 'conversation.claimed';
    }
}
