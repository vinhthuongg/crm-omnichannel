<?php

namespace Modules\Message\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Modules\Conversation\Models\Conversation;
use Modules\Conversation\Services\RealtimeConversationRecipientService;

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
        $channels = [
            new PrivateChannel('crm.conversation.'.$this->conversationId),
            new PrivateChannel('crm.conversations'),
        ];

        $conversation = Conversation::query()->find($this->conversationId);

        if ($conversation) {
            foreach (app(RealtimeConversationRecipientService::class)->channelsFor($conversation) as $channel) {
                $channels[] = new PrivateChannel($channel);
            }
        }

        return $channels;
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
