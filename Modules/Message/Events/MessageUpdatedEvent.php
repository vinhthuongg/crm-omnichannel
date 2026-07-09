<?php

namespace Modules\Message\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Modules\Conversation\Services\RealtimeConversationRecipientService;
use Modules\Message\Http\Resources\MessageResource;
use Modules\Message\Models\Message;

class MessageUpdatedEvent implements ShouldBroadcastNow
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(public Message $message)
    {
        $this->message->loadMissing(['sender', 'conversation.customer']);
    }

    public function broadcastOn(): array
    {
        $channels = [new PrivateChannel('crm.conversation.'.$this->message->conversation_id)];

        foreach (app(RealtimeConversationRecipientService::class)->channelsFor($this->message->conversation) as $channel) {
            $channels[] = new PrivateChannel($channel);
        }

        return $channels;
    }

    public function broadcastAs(): string
    {
        return 'message.updated';
    }

    public function broadcastWith(): array
    {
        return ['message' => (new MessageResource($this->message))->resolve()];
    }
}
