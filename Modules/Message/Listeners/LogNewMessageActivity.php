<?php

namespace Modules\Message\Listeners;

use Modules\ActivityLog\Services\ActivityLogService;
use Modules\Message\Events\NewMessageEvent;

class LogNewMessageActivity
{
    /** Nhận ActivityLogService để ghi lịch sử thao tác của người dùng. */
    public function __construct(private readonly ActivityLogService $activityLog)
    {
    }

    /** Ghi activity log khi một message mới được tạo trong hội thoại. */
    public function handle(NewMessageEvent $event): void
    {
        $actor = $event->message->sender_type === 'user' ? $event->message->sender : null;
        $this->activityLog->record($actor, 'message.sent', $event->message, ['conversation_id' => $event->message->conversation_id]);
    }
}
