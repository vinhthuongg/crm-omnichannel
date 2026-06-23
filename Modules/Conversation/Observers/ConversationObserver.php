<?php

namespace Modules\Conversation\Observers;

use Modules\Conversation\Models\Conversation;

class ConversationObserver
{
    public function updating(Conversation $conversation): void
    {
        if ($conversation->isDirty('status') && $conversation->status === 'closed' && $conversation->closed_at === null) {
            $conversation->closed_at = now();
        }
    }
}