<?php

namespace Modules\Message\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\Conversation\Models\Conversation;
use Modules\Conversation\Support\ConversationStatus;
use Modules\Message\Events\NewMessageEvent;
use Modules\Message\Jobs\SendOutboundMessageJob;
use Modules\Message\Models\Message;

class CustomerIdleFollowUpService
{
    private const CONTENT = 'Nếu anh/chị có cần giải đáp thắc mắc thì cứ liên hệ em hoặc để lại số điện thoại nhé anh/chị.';

    /** Tạo và xếp hàng tin follow-up khi tin nguồn vẫn là tin cuối và khách chưa phản hồi. */
    public function send(int $conversationId, int $sourceMessageId): void
    {
        $conversation = Conversation::query()->with(['customer.channels'])->find($conversationId);
        $source = Message::query()->find($sourceMessageId);

        if (! $conversation || ! $source || ! $this->canFollowUp($conversation, $source, $sourceMessageId)) {
            return;
        }

        $clientMessageId = "customer_idle_follow_up_{$conversation->id}_after_{$source->id}";

        if ($this->alreadyExists($source, $clientMessageId)) {
            return;
        }

        $message = $this->createMessage($conversation, $source, $clientMessageId);
        $this->broadcast($message);
        SendOutboundMessageJob::dispatch($message->id);

        Log::info('Customer idle follow-up queued', [
            'conversation_id' => $conversation->id,
            'source_message_id' => $source->id,
            'follow_up_message_id' => $message->id,
            'channel' => $message->channel,
        ]);
    }

    /** Kiểm tra tin gửi ra có hiển thị cho khách qua Facebook/Zalo và không phải whisper. */
    public static function isCustomerVisibleOutbound(Message $message): bool
    {
        return $message->sender_type !== 'customer'
            && $message->message_type !== 'whisper'
            && $message->channel !== 'internal'
            && in_array((string) $message->channel, ['facebook', 'zalo'], true);
    }

    /** Chỉ cho follow-up khi tin nguồn đã gửi thành công và vẫn là tin mới nhất của hội thoại. */
    private function canFollowUp(Conversation $conversation, Message $source, int $sourceMessageId): bool
    {
        $latestMessageId = $conversation->messages()
            ->latest('created_at')
            ->latest('id')
            ->value('id');

        return (int) $latestMessageId === $sourceMessageId
            && self::isCustomerVisibleOutbound($source)
            && $source->outbound_status === 'sent';
    }

    /** Kiểm tra tin follow-up tương ứng đã tồn tại để tránh gửi trùng. */
    private function alreadyExists(Message $source, string $clientMessageId): bool
    {
        return Message::query()
            ->where('channel', $source->channel)
            ->where('client_message_id', $clientMessageId)
            ->exists();
    }

    /** Lưu tin follow-up dạng system, chuyển hội thoại sang chờ khách và đánh dấu đã đọc. */
    private function createMessage(Conversation $conversation, Message $source, string $clientMessageId): Message
    {
        return DB::transaction(function () use ($conversation, $source, $clientMessageId): Message {
            $message = Message::query()->create([
                'conversation_id' => $conversation->id,
                'sender_type' => 'system',
                'sender_id' => null,
                'channel' => $source->channel,
                'content' => self::CONTENT,
                'message_type' => 'text',
                'attachments' => [],
                'client_message_id' => $clientMessageId,
                'outbound_status' => 'queued',
            ]);

            $conversation->forceFill([
                'last_message_at' => $message->created_at,
                'status' => ConversationStatus::WAITING_CUSTOMER,
                'unread_messages_count' => 0,
                'last_read_at' => now(),
            ])->save();
            $conversation->markAsRead();

            return $message->load(['conversation.customer.channels', 'sender']);
        });
    }

    /** Phát NewMessageEvent cho tin follow-up nhưng không làm job thất bại nếu broadcast lỗi. */
    private function broadcast(Message $message): void
    {
        try {
            event(new NewMessageEvent($message));
        } catch (\Throwable) {
        }
    }
}
