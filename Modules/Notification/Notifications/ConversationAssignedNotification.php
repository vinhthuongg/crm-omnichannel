<?php

namespace Modules\Notification\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use Modules\Conversation\Models\Conversation;

class ConversationAssignedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(private readonly Conversation $conversation)
    {
    }

    public function via(object $notifiable): array
    {
        return ['database', 'broadcast'];
    }

    public function toArray(object $notifiable): array
    {
        return ['type' => 'conversation_assigned', 'conversation_id' => $this->conversation->id, 'customer_id' => $this->conversation->customer_id];
    }
}