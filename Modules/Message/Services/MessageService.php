<?php

namespace Modules\Message\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\Chatbot\DTO\ChatbotReply;
use Modules\Chatbot\Jobs\SendChatbotReplyJob;
use Modules\Chatbot\Services\SalesChatbotService;
use Modules\Conversation\Models\Conversation;
use Modules\Conversation\Models\Tag;
use Modules\Customer\Models\Customer;
use Modules\Customer\Models\CustomerChannel;
use Modules\Message\DTO\InboundMessageData;
use Modules\Message\Events\NewMessageEvent;
use Modules\Message\Events\MessageUpdatedEvent;
use Modules\Message\Models\Message;
use Modules\Message\Repositories\MessageRepository;
use Modules\Conversation\Services\WorkShiftService;

class MessageService
{
    public function __construct(
        private readonly MessageRepository $repository,
        private readonly OutboundMessageService $outbound,
        private readonly WorkShiftService $shifts,
        private readonly SalesChatbotService $chatbot,
    ) {
    }

    public function storeInbound(InboundMessageData $data): Message
    {
        if ($data->externalMessageId) {
            $existing = Message::query()
                ->where('channel', $data->channel)
                ->where('external_message_id', $data->externalMessageId)
                ->with(['conversation.customer.channels', 'sender'])
                ->first();

            if ($existing) {
                return $existing;
            }
        }

        [$message, $conversation, $customer] = DB::transaction(function () use ($data): array {
            $facebookPageId = (string) data_get($data->metadata, 'facebook_page_id', '');
            $channel = CustomerChannel::query()->where('channel', $data->channel)->where('external_id', $data->externalCustomerId)->first();
            $customer = $channel?->customer ?? Customer::query()->create(['name' => $data->customerName, 'avatar' => $data->customerAvatar]);
            $this->refreshCustomerProfile($customer, $data);
            $customer->channels()->updateOrCreate(['channel' => $data->channel, 'external_id' => $data->externalCustomerId], ['metadata' => $data->metadata]);
            $conversation = Conversation::query()->firstOrCreate(
                ['customer_id' => $customer->id, 'status' => 'open', 'facebook_page_id' => $facebookPageId !== '' ? $facebookPageId : null],
                [
                    'last_message_at' => now(),
                    'work_shift_id' => $this->shifts->currentShift()?->id,
                ],
            );

            if (! $conversation->assigned_to && ! $conversation->work_shift_id) {
                $conversation->forceFill(['work_shift_id' => $this->shifts->currentShift()?->id])->save();
            }
            $message = $this->repository->create(['conversation_id' => $conversation->id, 'sender_type' => 'customer', 'sender_id' => $customer->id, 'channel' => $data->channel, 'content' => $data->content, 'message_type' => $data->messageType, 'attachments' => $data->attachments, 'external_message_id' => $data->externalMessageId]);
            $conversation->forceFill(['last_message_at' => $message->created_at])->save();
            $conversation->incrementUnreadMessages();
            $this->markConversationAsWaitingForConsulting($conversation);

            return [$message, $conversation, $customer];
        });

        $this->broadcastNewMessage($message);
        Log::info('Chatbot inbound message stored', [
            'message_id' => $message->id,
            'conversation_id' => $conversation->id,
            'channel' => $data->channel,
            'chatbot_enabled' => (bool) config('chatbot.enabled', true),
            'chatbot_async' => (bool) config('chatbot.async', false),
            'content_blank' => blank($data->content),
            'customer_has_phone' => filled($customer->phone),
            'unread_messages_count' => (int) $conversation->unread_messages_count,
        ]);

        if ($this->shouldSkipChatbotReply($conversation, $message)) {
            Log::info('Chatbot auto reply skipped because human is handling conversation', [
                'message_id' => $message->id,
                'conversation_id' => $conversation->id,
                'automation_state' => $conversation->automation_state,
                'last_read_at' => $conversation->last_read_at?->toISOString(),
            ]);

            return $message;
        }

        if (config('chatbot.async', false)) {
            Log::info('Chatbot auto reply dispatched async', [
                'message_id' => $message->id,
                'conversation_id' => $conversation->id,
            ]);
            SendChatbotReplyJob::dispatch($message->id);

            return $message;
        }

        $reply = $this->chatbot->replyFor($conversation, $customer, $data);

        if (! $reply || $reply->content === '') {
            Log::warning('Chatbot auto reply skipped', [
                'message_id' => $message->id,
                'conversation_id' => $conversation->id,
                'channel' => $data->channel,
                'reason' => ! $reply ? 'reply_null' : 'content_blank',
                'automation_state' => $conversation->automation_state,
                'customer_has_phone' => filled($customer->phone),
            ]);

            return $message;
        }

        try {
            $autoReply = $this->createChatbotReply($conversation, $data->channel, $reply);
        } catch (\Throwable $exception) {
            Log::error('Chatbot auto reply create failed', [
                'message_id' => $message->id,
                'conversation_id' => $conversation->id,
                'channel' => $data->channel,
                'client_message_key' => $reply->clientMessageKey,
                'error' => $exception->getMessage(),
                'exception' => $exception::class,
            ]);

            return $message;
        }

        if ($autoReply) {
            Log::info('Chatbot auto reply created', [
                'message_id' => $message->id,
                'auto_message_id' => $autoReply->id,
                'conversation_id' => $conversation->id,
                'channel' => $data->channel,
                'client_message_id' => $autoReply->client_message_id,
            ]);
            $this->broadcastNewMessage($autoReply);
            $this->sendAutoReplyAfterResponse($autoReply);
        } else {
            Log::warning('Chatbot auto reply duplicate skipped', [
                'message_id' => $message->id,
                'conversation_id' => $conversation->id,
                'channel' => $data->channel,
                'client_message_key' => $reply->clientMessageKey,
            ]);
        }

        return $message;
    }

    private function refreshCustomerProfile(Customer $customer, InboundMessageData $data): void
    {
        $updates = [];

        if ($data->customerName && ($customer->name === $data->externalCustomerId || blank($customer->name))) {
            $updates['name'] = $data->customerName;
        }

        if ($data->customerAvatar && $customer->avatar !== $data->customerAvatar) {
            $updates['avatar'] = $data->customerAvatar;
        }

        if (blank($customer->phone) && $phone = $this->extractPhoneNumber((string) $data->content)) {
            $updates['phone'] = $phone;
        }

        if ($updates) {
            $customer->forceFill($updates)->save();
        }
    }

    private function extractPhoneNumber(string $content): ?string
    {
        if ($content === '') {
            return null;
        }

        preg_match_all('/(?:\+?84|0)(?:[\s.\-()]?\d){8,10}/', $content, $matches);

        foreach ($matches[0] ?? [] as $candidate) {
            $normalized = preg_replace('/\D+/', '', $candidate) ?: '';

            if (str_starts_with($normalized, '84')) {
                $normalized = '0'.substr($normalized, 2);
            }

            if (preg_match('/^0\d{8,10}$/', $normalized)) {
                return $normalized;
            }
        }

        return null;
    }

    public function sendFromUser(Conversation $conversation, User $user, array $data): Message
    {
        $externalMessageId = $this->outbound->sendText($conversation, $data['channel'], (string) ($data['content'] ?? ''));

        $message = DB::transaction(function () use ($conversation, $user, $data, $externalMessageId): Message {
            $message = $this->repository->create(['conversation_id' => $conversation->id, 'sender_type' => 'user', 'sender_id' => $user->id, 'channel' => $data['channel'], 'content' => $data['content'] ?? null, 'message_type' => $data['message_type'] ?? 'text', 'attachments' => $data['attachments'] ?? null, 'external_message_id' => $externalMessageId]);
            $conversation->forceFill([
                'last_message_at' => $message->created_at,
                'last_read_at' => now(),
                'status' => 'open',
                'unread_messages_count' => 0,
            ])->save();
            $this->markConversationAsConsulting($conversation);

            return $message;
        });

        $this->broadcastNewMessage($message);

        return $message;
    }

    private function broadcastNewMessage(Message $message): void
    {
        try {
            event(new NewMessageEvent($message));
        } catch (\Throwable) {
        }
    }

    private function createChatbotReply(Conversation $conversation, string $channel, ?ChatbotReply $reply): ?Message
    {
        if (! $reply || $reply->content === '') {
            return null;
        }

        $clientMessageId = $reply->clientMessageKey ?: 'auto-chatbot-'.$conversation->id.'-'.md5($reply->content);
        if (strlen($clientMessageId) > 80) {
            $clientMessageId = 'auto-chatbot-'.$conversation->id.'-'.md5($clientMessageId);
        }
        $existing = Message::query()
            ->where('channel', $channel)
            ->where('client_message_id', $clientMessageId)
            ->first();

        if ($existing) {
            return null;
        }

        $message = $this->repository->create([
            'conversation_id' => $conversation->id,
            'sender_type' => 'user',
            'sender_id' => $this->autoReplySenderId($conversation),
            'channel' => $channel,
            'content' => $reply->content,
            'message_type' => 'text',
            'attachments' => $channel === 'facebook' && $reply->quickReplies
                ? [['type' => 'quick_reply', 'quick_replies' => $reply->quickReplies]]
                : [],
            'client_message_id' => $clientMessageId,
            'outbound_status' => 'queued',
        ]);

        $conversation->forceFill([
            'last_message_at' => $message->created_at,
        ])->save();

        return $message;
    }

    private function autoReplySenderId(Conversation $conversation): ?int
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
            Log::warning('Chatbot auto reply admin sender lookup failed', [
                'conversation_id' => $conversation->id,
                'error' => $exception->getMessage(),
            ]);
        }

        $fallbackId = User::query()->value('id');

        return $fallbackId ? (int) $fallbackId : null;
    }

    private function sendAutoReplyAfterResponse(Message $message): void
    {
        app()->terminating(function () use ($message): void {
            $message = $message->fresh(['conversation.customer.channels']);

            if (! $message?->conversation || $message->outbound_status !== 'queued') {
                Log::warning('Chatbot auto outbound skipped before send', [
                    'message_id' => $message?->id,
                    'conversation_id' => $message?->conversation_id,
                    'outbound_status' => $message?->outbound_status,
                    'has_conversation' => (bool) $message?->conversation,
                ]);

                return;
            }

            try {
                Log::info('Chatbot auto outbound sending', [
                    'message_id' => $message->id,
                    'conversation_id' => $message->conversation_id,
                    'channel' => $message->channel,
                ]);

                $message->forceFill([
                    'outbound_status' => 'sending',
                    'outbound_error' => null,
                ])->save();
                event(new MessageUpdatedEvent($message));

                $externalMessageId = $this->outbound->send(
                    $message->conversation,
                    $message->channel,
                    (string) $message->content,
                    $message->attachments ?? [],
                );

                $message->forceFill([
                    'external_message_id' => $externalMessageId,
                    'outbound_status' => 'sent',
                    'outbound_error' => null,
                    'sent_at' => now(),
                ])->save();
                event(new MessageUpdatedEvent($message));

                Log::info('Chatbot auto outbound sent', [
                    'message_id' => $message->id,
                    'conversation_id' => $message->conversation_id,
                    'channel' => $message->channel,
                    'external_message_id' => $externalMessageId,
                ]);
            } catch (\Throwable $exception) {
                $message->forceFill([
                    'outbound_status' => 'failed',
                    'outbound_error' => $exception->getMessage(),
                ])->save();
                event(new MessageUpdatedEvent($message));

                Log::warning('Auto chatbot outbound send failed', [
                    'message_id' => $message->id,
                    'conversation_id' => $message->conversation_id,
                    'channel' => $message->channel,
                    'error' => $exception->getMessage(),
                ]);
            }
        });
    }

    private function shouldSkipChatbotReply(Conversation $conversation, Message $inbound): bool
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

    private function hasHumanUserMessageSincePause(Conversation $conversation, string $pausedAt): bool
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

    private function markConversationAsWaitingForConsulting(Conversation $conversation): void
    {
        $this->syncConversationStatusTag($conversation, 'Khach dang doi tu van', '#f59e0b');
    }

    private function markConversationAsConsulting(Conversation $conversation): void
    {
        $this->syncConversationStatusTag($conversation, 'Dang tu van', '#e11d48');
    }

    private function syncConversationStatusTag(Conversation $conversation, string $name, string $color): void
    {
        $tag = Tag::query()->firstOrCreate(
            ['name' => $name],
            ['color' => $color],
        );

        $conversation->tags()->sync([$tag->id]);
        $conversation->load('tags');
    }
}
