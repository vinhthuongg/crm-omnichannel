<?php

namespace Modules\Message\Events;

class ChatbotResponseMedia extends ChatbotBroadcastEvent
{
    /** Đặt tên event để frontend hiển thị ảnh, video, audio hoặc file khi stream đang chạy. */
    public function broadcastAs(): string
    {
        return 'chatbot.response.media';
    }
}
