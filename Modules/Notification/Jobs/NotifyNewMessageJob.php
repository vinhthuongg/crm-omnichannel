<?php

namespace Modules\Notification\Jobs;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable as DispatchableQueueable;
use Modules\Message\Models\Message;
use Modules\Notification\Notifications\NewMessageNotification;

class NotifyNewMessageJob implements ShouldQueue
{
    use DispatchableQueueable;
    use Queueable;

    public function __construct(private readonly int $messageId)
    {
    }

    public function handle(): void
    {
        $message = Message::query()->with(['conversation', 'sender'])->findOrFail($this->messageId);
        $query = User::permission('conversation.view_all');
        if ($message->conversation->assigned_to) {
            $query->orWhereKey($message->conversation->assigned_to);
        }
        $query->get()->each->notify(new NewMessageNotification($message));
    }
}