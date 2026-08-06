<?php

namespace Modules\Notification\Listeners;

use Modules\Conversation\Events\ConversationLifecycleEvent;
use Modules\Notification\Jobs\SendFcmPushNotificationJob;
use Modules\Notification\Notifications\ConversationAssignedNotification;

class QueueConversationAssignedNotification
{
    /** Xếp notification cho nhân viên mới khi hội thoại được phân công. */
    public function handle(ConversationLifecycleEvent $event): void
    {
        $assignee = $event->conversation->assignee;

        if (! $assignee) {
            return;
        }

        $assignee->notify(new ConversationAssignedNotification($event->conversation));

        SendFcmPushNotificationJob::dispatch(
            [$assignee->id],
            'Hội thoại mới',
            'Bạn vừa được giao một hội thoại cần xử lý.',
            [
                'type' => 'conversation_assigned',
                'conversation_id' => $event->conversation->id,
                'customer_id' => $event->conversation->customer_id,
            ],
        );
    }
}
