<?php

namespace Modules\Conversation\Observers;

use Modules\Conversation\Models\Conversation;
use Modules\Conversation\Support\ConversationStatus;

class ConversationObserver
{
    /** Xử lý lifecycle updating của model để đồng bộ dữ liệu liên quan. */
    public function updating(Conversation $conversation): void
    {
        if ($conversation->isDirty('status') && $conversation->status === ConversationStatus::CLOSED && $conversation->closed_at === null) {
            $conversation->closed_at = now();
        }
    }
}
