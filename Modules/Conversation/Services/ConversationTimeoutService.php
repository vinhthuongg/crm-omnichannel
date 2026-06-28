<?php

namespace Modules\Conversation\Services;

use Illuminate\Support\Collection;
use Modules\Conversation\Models\Conversation;
use Modules\Conversation\Support\ConversationStatus;

class ConversationTimeoutService
{
    public function overdueQuery(int $minutes)
    {
        return Conversation::query()
            ->where('status', ConversationStatus::IN_PROGRESS)
            ->whereNotNull('assigned_to')
            ->where('last_message_at', '<=', now()->subMinutes($minutes));
    }

    public function overdue(int $minutes): Collection
    {
        return $this->overdueQuery($minutes)->get();
    }
}
