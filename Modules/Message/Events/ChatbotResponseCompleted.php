<?php

namespace Modules\Message\Events;

class ChatbotResponseCompleted extends ChatbotBroadcastEvent
{
    /** Đặt tên event để frontend thay bubble tạm bằng các message đã lưu. */
    public function broadcastAs(): string
    {
        return 'chatbot.response.completed';
    }
}
