<?php

namespace Modules\Message\Events;

class ChatbotMessageBreak extends ChatbotBroadcastEvent
{
    /** Đặt tên event để frontend chuyển sang bubble AI kế tiếp. */
    public function broadcastAs(): string
    {
        return 'chatbot.message.break';
    }
}
