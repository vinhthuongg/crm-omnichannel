<?php

namespace Modules\Message\Listeners;

use Modules\Message\Events\NewMessageEvent;
use Modules\Notification\Jobs\NotifyNewMessageJob;

class QueueNewMessageNotification
{
    /** Xếp job notification khi có tin khách hàng mới cần báo cho nhân viên. */
    public function handle(NewMessageEvent $event): void
    {
        NotifyNewMessageJob::dispatch($event->message->id);
    }
}
