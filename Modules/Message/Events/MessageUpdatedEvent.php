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

    /** Nạp người gửi và khách hàng để chuẩn bị broadcast phiên bản tin nhắn mới nhất. */
    public function __construct(public Message $message)
    {
        $this->message->loadMissing(['sender', 'conversation.customer']);
    }

    /** Phát thay đổi tin nhắn tới channel hội thoại và inbox người dùng liên quan. */
    public function broadcastOn(): array
    {
        $channels = [new PrivateChannel('crm.conversation.'.$this->message->conversation_id)];

        foreach (app(RealtimeConversationRecipientService::class)->channelsFor($this->message->conversation) as $channel) {
            $channels[] = new PrivateChannel($channel);
        }

        return $channels;
    }

    /** Đặt tên sự kiện realtime là message.updated để client cập nhật tin hiện có. */
    public function broadcastAs(): string
    {
        return 'message.updated';
    }

    /** Chuyển trạng thái tin nhắn mới nhất thành payload realtime cho client. */
    public function broadcastWith(): array
    {
        return ['message' => (new MessageResource($this->message))->resolve()];
    }
}
