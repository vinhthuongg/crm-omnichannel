<?php

namespace Modules\Message\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\Conversation\Models\Conversation;
use Modules\Conversation\Support\ConversationStatus;
use Modules\Message\Events\NewMessageEvent;
use Modules\Message\Models\Message;

class SendCustomerIdleFollowUpJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    private const CONTENT = 'Nếu anh/chị có cần giải đáp thắc mắc thì cứ liên hệ em hoặc để lại số điện thoại nhé anh/chị.';

    public int $tries = 1;

    public int $timeout = 30;

    public function __construct(
        public readonly int $conversationId,
        public readonly int $sourceMessageId,
    ) {
        $this->onQueue('default');
    }

    public static function dispatchFor(Message $message): void
    {
        if (! self::isCustomerVisibleOutbound($message)) {
            return;
        }

        $minutes = max(1, (int) config('services.botpress.customer_idle_follow_up_minutes', 3));

        self::dispatch((int) $message->conversation_id, (int) $message->id)
            ->delay(now()->addMinutes($minutes));
    }

    public function handle(): void
    {
        $conversation = Conversation::query()
            ->with(['customer.channels'])
            ->find($this->conversationId);

        if (! $conversation) {
            return;
        }

        $source = Message::query()->find($this->sourceMessageId);
        $latest = $conversation->messages()
            ->latest('created_at')
            ->latest('id')
            ->first();

        if (! $source || ! $latest || (int) $latest->id !== $this->sourceMessageId) {
            return;
        }

        if (! self::isCustomerVisibleOutbound($source) || $source->outbound_status !== 'sent') {
            return;
        }

        $clientMessageId = 'customer_idle_follow_up_'.$conversation->id.'_after_'.$source->id;

        if (Message::query()->where('channel', $source->channel)->where('client_message_id', $clientMessageId)->exists()) {
            return;
        }

        $message = DB::transaction(function () use ($conversation, $source, $clientMessageId): Message {
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

        try {
            event(new NewMessageEvent($message));
        } catch (\Throwable) {
        }

        SendOutboundMessageJob::dispatch($message->id);

        Log::info('Customer idle follow-up queued', [
            'conversation_id' => $conversation->id,
            'source_message_id' => $source->id,
            'follow_up_message_id' => $message->id,
            'channel' => $message->channel,
        ]);
    }

    private static function isCustomerVisibleOutbound(Message $message): bool
    {
        if ($message->sender_type === 'customer') {
            return false;
        }

        if ($message->message_type === 'whisper' || $message->channel === 'internal') {
            return false;
        }

        return in_array((string) $message->channel, ['facebook', 'zalo'], true);
    }
}
