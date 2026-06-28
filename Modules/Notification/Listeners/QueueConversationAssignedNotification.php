<?php

namespace Modules\Notification\Listeners;

use Modules\Conversation\Events\ConversationLifecycleEvent;
use Modules\Notification\Notifications\ConversationAssignedNotification;

class QueueConversationAssignedNotification
{
    public function handle(ConversationLifecycleEvent $event): void
    {
        $event->conversation->assignee?->notify(new ConversationAssignedNotification($event->conversation));
    }
}
