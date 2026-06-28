<?php

namespace Modules\Notification\Jobs;

use App\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Modules\Conversation\Models\Conversation;
use Modules\Conversation\Support\ConversationStatus;

class SendUnansweredConversationReminderJob implements ShouldQueue
{
    use Queueable;

    public function handle(): void
    {
        Conversation::query()
            ->whereIn('status', ConversationStatus::ACTIVE)
            ->whereNotNull('assigned_to')
            ->where('last_message_at', '<=', now()->subMinutes(15))
            ->with('assignee')
            ->each(function (Conversation $conversation): void {
                $conversation->assignee?->notify(new \Modules\Notification\Notifications\ConversationAssignedNotification($conversation));
            });
    }
}
