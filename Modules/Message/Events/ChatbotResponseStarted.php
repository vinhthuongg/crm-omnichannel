<?php

namespace Modules\Message\Events;

class ChatbotResponseStarted extends ChatbotBroadcastEvent
{
    /** Đặt tên event để frontend tạo bubble AI đang nhập. */
    public function broadcastAs(): string
    {
        return 'chatbot.response.started';
    }
}
