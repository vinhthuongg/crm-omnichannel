<?php

namespace Modules\Message\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Modules\Conversation\Services\RealtimeConversationRecipientService;
use Modules\Message\Http\Resources\MessageResource;
use Modules\Message\Models\Message;

class NewMessageEvent implements ShouldBroadcastNow
{
    use Dispatchable;
    use SerializesModels;

    /** Nạp người gửi, khách hàng và nhãn để chuẩn bị broadcast tin nhắn vừa tạo. */
    public function __construct(public Message $message)
    {
        $this->message->unsetRelation('conversation');
        $this->message->loadMissing(['sender', 'conversation.customer', 'conversation.tags']);
    }

    /** Phát tin mới tới channel hội thoại và inbox của những người có quyền nhận. */
    public function broadcastOn(): array
    {
        $channels = [
            new PrivateChannel('crm.conversations'),
            new PrivateChannel('crm.conversation.' . $this->message->conversation_id),
        ];

        foreach (app(RealtimeConversationRecipientService::class)->channelsFor($this->message->conversation) as $channel) {
            $channels[] = new PrivateChannel($channel);
        }

        return $channels;
    }

    /** Đặt tên sự kiện realtime là message.created để client nhận biết tin mới. */
    public function broadcastAs(): string
    {
        return 'message.created';
    }

    /** Chuyển tin nhắn và thông tin hội thoại liên quan thành payload realtime. */
    public function broadcastWith(): array
    {
        return ['message' => (new MessageResource($this->message))->resolve()];
    }
}
