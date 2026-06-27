<?php

namespace Modules\Chatbot\Jobs;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
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

        if ($this->humanIsHandlingConversation($conversation, $inbound)) {
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
            'sender_id' => $this->autoReplySenderId($conversation),
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

    private function autoReplySenderId($conversation): ?int
    {
        if ($conversation->assigned_to) {
            return (int) $conversation->assigned_to;
        }

        try {
            $adminId = User::role('Admin')->value('id');

            if ($adminId) {
                return (int) $adminId;
            }
        } catch (\Throwable $exception) {
            Log::warning('Chatbot async reply admin sender lookup failed', [
                'conversation_id' => $conversation->id,
                'error' => $exception->getMessage(),
            ]);
        }

        $fallbackId = User::query()->value('id');

        return $fallbackId ? (int) $fallbackId : null;
    }

    private function humanIsHandlingConversation($conversation, Message $inbound): bool
    {
        $state = (array) ($conversation->automation_state ?? []);

        if (filled($state['paused_by_user_at'] ?? null)) {
            return $this->hasHumanUserMessageSincePause($conversation, (string) $state['paused_by_user_at']);
        }

        return $conversation->messages()
            ->where('sender_type', 'user')
            ->where('created_at', '>=', $inbound->created_at)
            ->where(function ($query): void {
                $query->whereNull('client_message_id')
                    ->orWhere('client_message_id', 'not like', 'auto-chatbot-%');
            })
            ->exists();
    }

    private function hasHumanUserMessageSincePause($conversation, string $pausedAt): bool
    {
        return $conversation->messages()
            ->where('sender_type', 'user')
            ->where('created_at', '>=', $pausedAt)
            ->where(function ($query): void {
                $query->whereNull('client_message_id')
                    ->orWhere('client_message_id', 'not like', 'auto-chatbot-%');
            })
            ->exists();
    }
}
