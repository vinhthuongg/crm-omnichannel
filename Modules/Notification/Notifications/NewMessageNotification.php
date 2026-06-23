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

    public function __construct(private readonly Message $message)
    {
    }

    public function via(object $notifiable): array
    {
        return ['database', 'broadcast'];
    }

    public function toArray(object $notifiable): array
    {
        return ['type' => 'new_message', 'message_id' => $this->message->id, 'conversation_id' => $this->message->conversation_id, 'sender_name' => $this->message->senderName()];
    }

    public function toBroadcast(object $notifiable): BroadcastMessage
    {
        return new BroadcastMessage($this->toArray($notifiable));
    }
}