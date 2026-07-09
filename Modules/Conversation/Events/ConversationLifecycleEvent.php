<?php

namespace Modules\Conversation\Events;

use App\Models\User;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Modules\Conversation\Models\Conversation;
use Modules\Conversation\Services\RealtimeConversationRecipientService;

abstract class ConversationLifecycleEvent implements ShouldBroadcastNow
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(public Conversation $conversation, public ?User $actor = null)
    {
        $this->conversation->loadMissing(['customer.channels', 'assignee', 'tags']);
    }

    public function broadcastOn(): array
    {
        $channels = [
            new PrivateChannel('crm.conversations'),
            new PrivateChannel('crm.conversation.'.$this->conversation->id),
        ];

        foreach (app(RealtimeConversationRecipientService::class)->channelsFor($this->conversation) as $channel) {
            $channels[] = new PrivateChannel($channel);
        }

        return $channels;
    }

    abstract public function broadcastAs(): string;

    public function broadcastWith(): array
    {
        return [
            'conversation' => [
                'id' => (int) $this->conversation->id,
                'status' => $this->conversation->status,
                'assigned_to' => $this->conversation->assigned_to ? (int) $this->conversation->assigned_to : null,
                'assigned_by' => $this->conversation->assigned_by ? (int) $this->conversation->assigned_by : null,
                'assigned_type' => $this->conversation->assigned_type,
                'assignee_name' => $this->conversation->assignee?->name,
                'claimed_at' => $this->conversation->claimed_at?->toISOString(),
                'resolved_at' => $this->conversation->resolved_at?->toISOString(),
                'closed_at' => $this->conversation->closed_at?->toISOString(),
                'unread_messages_count' => (int) $this->conversation->unread_messages_count,
                'customer_name' => $this->conversation->customer?->name ?? 'Customer',
                'customer_avatar' => $this->conversation->customer?->avatar,
                'customer_phone' => $this->conversation->customer?->phone,
                'conversation_url' => route('crm.conversations.show', $this->conversation),
            ],
            'actor' => $this->actor ? [
                'id' => (int) $this->actor->id,
                'name' => $this->actor->name,
            ] : null,
        ];
    }
}
