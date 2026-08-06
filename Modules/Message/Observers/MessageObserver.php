<?php

namespace Modules\Message\Observers;

use Modules\Message\Models\Message;

class MessageObserver
{
    /** Xử lý lifecycle creating của model để đồng bộ dữ liệu liên quan. */
    public function creating(Message $message): void
    {
        $message->message_type = $message->message_type ?: 'text';
    }
}
