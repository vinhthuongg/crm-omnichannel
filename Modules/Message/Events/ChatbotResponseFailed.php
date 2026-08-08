<?php

namespace Modules\Message\Events;

class ChatbotResponseFailed extends ChatbotBroadcastEvent
{
    /** Đặt tên event để frontend dừng typing và hiện thao tác thử lại. */
    public function broadcastAs(): string
    {
        return 'chatbot.response.failed';
    }
}
