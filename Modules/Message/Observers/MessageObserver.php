<?php

namespace Modules\Message\Observers;

use Modules\Message\Models\Message;

class MessageObserver
{
    public function creating(Message $message): void
    {
        $message->message_type = $message->message_type ?: 'text';
    }
}