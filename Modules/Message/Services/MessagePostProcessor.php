<?php

namespace Modules\Message\Services;

use App\Services\ConversationReplySuggestionService;
use Illuminate\Support\Facades\Log;
use Modules\Conversation\Models\Conversation;
use Modules\Customer\Models\Customer;
use Modules\Message\Events\NewMessageEvent;
use Modules\Message\Jobs\GenerateChatbotResponseJob;
use Modules\Message\Models\ChatbotResponse;
use Modules\Message\Models\Message;
use Modules\Search\Services\VectorSearchService;

class MessagePostProcessor
{
    /** Phát NewMessageEvent nhưng không làm luồng lưu tin thất bại nếu realtime gặp lỗi. */
    public function broadcast(Message $message): void
    {
        try {
            event(new NewMessageEvent($message));
        } catch (\Throwable) {
        }
    }

    /** Lập chỉ mục lại khách hàng để nội dung tin mới có thể được tìm bằng vector search. */
    public function refreshVector(?Customer $customer): void
    {
        if (! $customer || ! config('search.vector.enabled', true)) {
            return;
        }
        app()->terminating(function () use ($customer): void {
            try {
                app(VectorSearchService::class)->indexCustomer($customer->fresh() ?: $customer);
            } catch (\Throwable $e) {
                Log::warning('Customer vector index refresh failed', ['customer_id' => $customer->id, 'error' => $e->getMessage()]);
            }
        });
    }

    /** Xếp job tạo gợi ý trả lời khi tin mới đến từ khách hàng. */
    public function queueSuggestions(Conversation $conversation, Message $message): void
    {
        app()->terminating(function () use ($conversation, $message): void {
            try {
                app(ConversationReplySuggestionService::class)->queue($conversation, (int) $message->id);
            } catch (\Throwable $e) {
                Log::warning('Reply suggestion queue failed', ['conversation_id' => $conversation->id, 'message_id' => $message->id, 'error' => $e->getMessage()]);
            }
        });
    }

    /** Tạo bản ghi idempotency và xếp job chatbot đúng một lần cho mỗi tin nhắn khách. */
    public function queueChatbot(Conversation $conversation, Message $message): void
    {
        if (! config('chatbot.enabled') || $message->sender_type !== 'customer' || blank($message->content)) {
            return;
        }

        $response = ChatbotResponse::query()->firstOrCreate(
            ['source_message_id' => $message->id],
            [
                'conversation_id' => $conversation->id,
                'external_message_id' => 'crm-message-'.$message->id,
                'status' => 'pending',
            ],
        );

        if ($response->wasRecentlyCreated) {
            GenerateChatbotResponseJob::dispatch($response->id);
        }
    }
}
