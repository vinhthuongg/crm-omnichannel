<?php

namespace Modules\Notification\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use Modules\Conversation\Models\Conversation;

class ConversationAssignedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /** Giữ hội thoại vừa được phân công để tạo nội dung notification cho nhân viên nhận việc. */
    public function __construct(private readonly Conversation $conversation)
    {
    }

    /** Khai báo các kênh được dùng để gửi notification. */
    public function via(object $notifiable): array
    {
        return ['database', 'broadcast'];
    }

    /** Tạo payload báo hội thoại và khách hàng vừa được phân công cho người nhận. */
    public function toArray(object $notifiable): array
    {
        return ['type' => 'conversation_assigned', 'conversation_id' => $this->conversation->id, 'customer_id' => $this->conversation->customer_id];
    }
}
