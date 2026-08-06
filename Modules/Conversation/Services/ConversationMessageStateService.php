<?php

namespace Modules\Conversation\Services;

use Modules\Conversation\Models\Conversation;
use Modules\Conversation\Support\ConversationStatus;
use Modules\Message\Jobs\SendCustomerIdleFollowUpJob;
use Modules\Message\Models\Message;

class ConversationMessageStateService
{
    /** Cập nhật trạng thái sau khi tạo tin nhắn gửi ra. */
    public function outbound(Conversation $conversation, Message $message, bool $whisper = false): void
    {
        $conversation->forceFill(['last_message_at' => $message->created_at, 'last_read_at' => now(),
            'status' => ConversationStatus::normalize($conversation->status) === ConversationStatus::CLOSED ? ConversationStatus::CLOSED : ConversationStatus::WAITING_CUSTOMER,
            'first_response_at' => $whisper ? $conversation->first_response_at : ($conversation->first_response_at ?: now()), 'unread_messages_count' => 0])->save();
        if (! $whisper) SendCustomerIdleFollowUpJob::dispatchFor($message);
    }

    /** Đồng bộ trạng thái từ message echo của Facebook. */
    public function facebookEcho(Conversation $conversation, Message $message): void
    {
        $conversation->forceFill(['last_message_at' => $message->created_at, 'last_read_at' => now(),
            'status' => ConversationStatus::normalize($conversation->status) === ConversationStatus::CLOSED ? ConversationStatus::CLOSED : ConversationStatus::WAITING_CUSTOMER,
            'unread_messages_count' => 0])->save();
    }
}
