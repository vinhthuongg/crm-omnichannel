<?php

namespace Modules\Notification\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Notification;
use Modules\Message\Models\Message;

class NewMessageNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /** Giữ tin nhắn mới để tạo notification chứa người gửi, nội dung xem trước và hội thoại. */
    public function __construct(private readonly Message $message)
    {
    }

    /** Khai báo các kênh được dùng để gửi notification. */
    public function via(object $notifiable): array
    {
        return ['database', 'broadcast'];
    }

    /** Tạo payload notification nhận diện tin nhắn mới, hội thoại và tên người gửi. */
    public function toArray(object $notifiable): array
    {
        return ['type' => 'new_message', 'message_id' => $this->message->id, 'conversation_id' => $this->message->conversation_id, 'sender_name' => $this->message->senderName()];
    }

    /** Chuyển notification thành payload phát qua kênh realtime. */
    public function toBroadcast(object $notifiable): BroadcastMessage
    {
        return new BroadcastMessage($this->toArray($notifiable));
    }
}
