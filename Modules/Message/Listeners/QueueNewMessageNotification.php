<?php

namespace Modules\Message\Listeners;

use Modules\Message\Events\NewMessageEvent;
use Modules\Notification\Jobs\NotifyNewMessageJob;

class QueueNewMessageNotification
{
    public function handle(NewMessageEvent $event): void
    {
        NotifyNewMessageJob::dispatch($event->message->id);
    }
}