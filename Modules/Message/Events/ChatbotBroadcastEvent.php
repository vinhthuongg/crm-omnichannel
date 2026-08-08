<?php

namespace Modules\Message\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

abstract class ChatbotBroadcastEvent implements ShouldBroadcastNow
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly int $conversationId,
        public readonly int $responseId,
        public readonly array $payload = [],
    ) {}

    /** Chỉ phát trạng thái chatbot trên private channel của đúng hội thoại CRM. */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('crm.conversation.'.$this->conversationId)];
    }

    /** Chỉ đưa dữ liệu hiển thị an toàn lên trình duyệt, không chứa API key hoặc metadata thô. */
    public function broadcastWith(): array
    {
        return array_merge([
            'conversation_id' => $this->conversationId,
            'response_id' => $this->responseId,
        ], $this->payload);
    }
}
