<?php

namespace Modules\Message\Events;

class ChatbotResponseDelta extends ChatbotBroadcastEvent
{
    /** Đặt tên event để frontend nối thêm phần văn bản AI vừa sinh. */
    public function broadcastAs(): string
    {
        return 'chatbot.response.delta';
    }
}
