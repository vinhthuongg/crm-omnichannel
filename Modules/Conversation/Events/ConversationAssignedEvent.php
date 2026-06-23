<?php

namespace Modules\Conversation\Events;

use App\Models\User;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Modules\Conversation\Models\Conversation;

class ConversationAssignedEvent
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(public Conversation $conversation, public User $actor)
    {
    }
}