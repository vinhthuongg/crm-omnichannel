<?php

namespace Modules\Message\Listeners;

use Modules\ActivityLog\Services\ActivityLogService;
use Modules\Message\Events\NewMessageEvent;

class LogNewMessageActivity
{
    public function __construct(private readonly ActivityLogService $activityLog)
    {
    }

    public function handle(NewMessageEvent $event): void
    {
        $actor = $event->message->sender_type === 'user' ? $event->message->sender : null;
        $this->activityLog->record($actor, 'message.sent', $event->message, ['conversation_id' => $event->message->conversation_id]);
    }
}