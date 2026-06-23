<?php

namespace Modules\Notification\Listeners;

use Modules\Conversation\Events\ConversationAssignedEvent;
use Modules\Notification\Notifications\ConversationAssignedNotification;

class QueueConversationAssignedNotification
{
    public function handle(ConversationAssignedEvent $event): void
    {
        $event->conversation->assignee?->notify(new ConversationAssignedNotification($event->conversation));
    }
}