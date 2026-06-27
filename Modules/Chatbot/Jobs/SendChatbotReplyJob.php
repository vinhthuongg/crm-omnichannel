<?php

namespace Modules\Chatbot\Jobs;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Modules\Chatbot\Services\SalesChatbotService;
use Modules\Message\Events\NewMessageEvent;
use Modules\Message\Jobs\SendOutboundMessageJob;
use Modules\Message\Models\Message;

class SendChatbotReplyJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(
        private readonly int $messageId,
    ) {
    }

    public function handle(SalesChatbotService $chatbot): void
    {
        $inbound = Message::query()
            ->with(['conversation.customer'])
            ->find($this->messageId);

        if (! $inbound || $inbound->sender_type !== 'customer') {
            return;
        }

        $conversation = $inbound->conversation?->fresh(['customer']);
        $customer = $conversation?->customer;

        if (! $conversation || ! $customer) {
            return;
        }

        if ((int) $conversation->unread_messages_count === 0) {
            return;
        }

        if ($conversation->last_read_at && $conversation->last_read_at->gte($inbound->created_at)) {
            return;
        }

        $reply = $chatbot->replyForMessage($conversation, $customer, $inbound);

        if (! $reply || $reply->content === '') {
            return;
        }

        $clientMessageId = $reply->clientMessageKey ?: 'auto-chatbot-'.$conversation->id.'-'.md5($reply->content);
        $existing = Message::query()
            ->where('channel', $inbound->channel)
            ->where('client_message_id', $clientMessageId)
            ->first();

        if ($existing) {
            return;
        }

        $outbound = Message::query()->create([
            'conversation_id' => $conversation->id,
            'sender_type' => 'user',
            'sender_id' => $conversation->assigned_to ?: User::role('Admin')->value('id') ?: User::query()->value('id'),
            'channel' => $inbound->channel,
            'content' => $reply->content,
            'message_type' => 'text',
            'attachments' => $inbound->channel === 'facebook' && $reply->quickReplies
                ? [['type' => 'quick_reply', 'quick_replies' => $reply->quickReplies]]
                : [],
            'client_message_id' => $clientMessageId,
            'outbound_status' => 'queued',
        ])->load(['conversation.customer.channels', 'sender']);

        $conversation->forceFill([
            'last_message_at' => $outbound->created_at,
        ])->save();

        try {
            event(new NewMessageEvent($outbound));
        } catch (\Throwable) {
        }

        SendOutboundMessageJob::dispatch($outbound->id);
    }
}
