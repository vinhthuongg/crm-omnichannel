<?php

namespace Modules\Message\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class MessageDeletedEvent implements ShouldBroadcastNow
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public int $conversationId,
        public array $messageIds,
        public bool $clearAll = false,
        public bool $deleteConversation = false,
    ) {
    }

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('crm.conversation.'.$this->conversationId),
            new PrivateChannel('crm.conversations'),
        ];
    }

    public function broadcastAs(): string
    {
        return 'message.deleted';
    }

    public function broadcastWith(): array
    {
        return [
            'conversation_id' => $this->conversationId,
            'message_ids' => array_values($this->messageIds),
            'clear_all' => $this->clearAll,
            'delete_conversation' => $this->deleteConversation,
        ];
    }
}
