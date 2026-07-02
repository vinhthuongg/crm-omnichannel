<?php

namespace Modules\Text\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\Conversation\Models\Conversation;
use Modules\Message\Events\NewMessageEvent;
use Modules\Message\Jobs\SendOutboundMessageJob;
use Modules\Message\Models\Message;
use Modules\Text\Models\TextConversationLink;

class TextGatewayService
{
    public function __construct(
        private readonly TextAgentChatService $text,
        private readonly TextConversationBridge $bridge,
    ) {
    }

    public function enabled(): bool
    {
        return $this->text->bridgeEnabled();
    }

    public function relayCustomerMessage(Message $message): void
    {
        if (! $this->enabled() || $message->sender_type !== 'customer' || $message->channel !== 'facebook') {
            return;
        }

        $message->loadMissing('conversation.customer.channels');
        $conversation = $message->conversation;

        if (! $conversation?->customer) {
            return;
        }

        $link = $conversation->textConversationLink;
        $customerId = $link?->text_customer_id ?: $this->customerUserId($conversation);
        $event = $this->messageEvent($message, 'crm_in_'.$message->id, $customerId);

        try {
            if (! $link?->text_chat_id) {
                $response = $this->text->startChat($this->customerPayload($conversation, $customerId), $event);
                $this->storeLink($conversation, $response, $customerId, [
                    'source' => 'crm_gateway_start',
                    'message_id' => $message->id,
                    'text_event' => $event,
                ]);

                return;
            }

            try {
                $this->text->sendEvent($link->text_chat_id, $event);
            } catch (\Throwable $exception) {
                Log::warning('Text.com send_event failed, trying resume_chat', [
                    'conversation_id' => $conversation->id,
                    'message_id' => $message->id,
                    'text_chat_id' => $link->text_chat_id,
                    'error' => $exception->getMessage(),
                ]);

                $response = $this->text->resumeChat($link->text_chat_id, $event, $customerId);
                $this->storeLink($conversation, $response + ['chat_id' => $link->text_chat_id], $customerId, [
                    'source' => 'crm_gateway_resume',
                    'message_id' => $message->id,
                    'text_event' => $event,
                ]);
            }
        } catch (\Throwable $exception) {
            Log::warning('Text.com customer relay failed', [
                'conversation_id' => $conversation->id,
                'message_id' => $message->id,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    public function relayAgentMessage(Message $message): void
    {
        if (! $this->enabled() || $message->sender_type !== 'user' || $message->channel !== 'facebook') {
            return;
        }

        $message->loadMissing('conversation.textConversationLink');
        $link = $message->conversation?->textConversationLink;

        if (! $link?->text_chat_id) {
            return;
        }

        try {
            $this->text->sendEvent($link->text_chat_id, $this->messageEvent($message, 'crm_out_'.$message->id));
        } catch (\Throwable $exception) {
            Log::warning('Text.com agent relay failed', [
                'conversation_id' => $message->conversation_id,
                'message_id' => $message->id,
                'text_chat_id' => $link->text_chat_id,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    public function handleWebhook(array $payload): ?Message
    {
        $link = $this->bridge->upsertFromWebhook($payload);
        $chatId = $this->firstString($payload, ['payload.chat_id', 'chat_id', 'chat.id', 'payload.chat.id']);
        $event = $this->eventPayload($payload);

        if (! $event || $this->isCrmEcho($event)) {
            return null;
        }

        $link = $link ?: TextConversationLink::query()->where('text_chat_id', $chatId)->first();

        if (! $link?->conversation_id) {
            Log::info('Text.com webhook event skipped because conversation is not mapped', [
                'text_chat_id' => $chatId,
                'event_id' => data_get($event, 'id'),
            ]);

            return null;
        }

        if ($this->isCustomerEcho($link, $event)) {
            return null;
        }

        return $this->storeTextReply($link, $event, $payload);
    }

    private function storeTextReply(TextConversationLink $link, array $event, array $payload): ?Message
    {
        $eventId = (string) data_get($event, 'id', '');
        $clientId = $eventId !== '' ? 'text_evt_'.$eventId : null;

        if ($clientId) {
            $existing = Message::query()
                ->where('channel', 'facebook')
                ->where('client_message_id', $clientId)
                ->first();

            if ($existing) {
                return $existing;
            }
        }

        $conversation = Conversation::query()
            ->with('customer.channels')
            ->find($link->conversation_id);

        if (! $conversation) {
            return null;
        }

        $content = trim((string) data_get($event, 'text', ''));

        if ($content === '') {
            return null;
        }

        $message = DB::transaction(function () use ($conversation, $content, $clientId, $event, $payload): Message {
            $message = Message::query()->create([
                'conversation_id' => $conversation->id,
                'sender_type' => 'system',
                'sender_id' => null,
                'channel' => 'facebook',
                'content' => $content,
                'message_type' => 'text',
                'attachments' => [[
                    'type' => 'metadata',
                    'name' => 'text_gateway',
                    'payload' => [
                        'text_event' => $event,
                        'raw' => $payload,
                    ],
                ]],
                'client_message_id' => $clientId,
                'outbound_status' => 'queued',
            ]);

            $conversation->forceFill([
                'last_message_at' => $message->created_at,
                'first_response_at' => $conversation->first_response_at ?: now(),
            ])->save();

            return $message->load(['conversation.customer.channels', 'sender']);
        });

        try {
            event(new NewMessageEvent($message));
        } catch (\Throwable) {
        }

        SendOutboundMessageJob::dispatch($message->id);

        return $message;
    }

    private function storeLink(Conversation $conversation, array $response, string $customerId, array $payload): TextConversationLink
    {
        $facebookChannel = $conversation->customer?->channels()
            ->where('channel', 'facebook')
            ->first();

        return TextConversationLink::query()->updateOrCreate(
            ['conversation_id' => $conversation->id],
            [
                'text_chat_id' => (string) data_get($response, 'chat_id', $conversation->textConversationLink?->text_chat_id),
                'text_thread_id' => (string) data_get($response, 'thread_id', $conversation->textConversationLink?->text_thread_id),
                'text_customer_id' => $customerId,
                'facebook_page_id' => $conversation->facebook_page_id,
                'facebook_psid' => $facebookChannel?->external_id,
                'last_payload' => $payload + ['response' => $response],
            ],
        );
    }

    private function customerPayload(Conversation $conversation, string $customerId): array
    {
        $customer = $conversation->customer;

        return [
            'id' => $customerId,
            'name' => $customer?->name ?: 'Facebook customer',
            'email' => $customer?->email,
            'avatar' => $customer?->avatar,
        ];
    }

    private function customerUserId(Conversation $conversation): string
    {
        return 'crm_customer_'.$conversation->customer_id;
    }

    private function messageEvent(Message $message, string $customId, ?string $authorId = null): array
    {
        $event = [
            'type' => 'message',
            'text' => $this->messageText($message),
            'visibility' => 'all',
            'custom_id' => $customId,
        ];

        if ($authorId) {
            $event['author_id'] = $authorId;
        }

        return $event;
    }

    private function messageText(Message $message): string
    {
        $content = trim((string) $message->content);
        $attachments = collect($message->attachments ?? [])
            ->reject(fn (array $attachment): bool => ($attachment['type'] ?? '') === 'metadata')
            ->map(fn (array $attachment): string => trim((string) ($attachment['name'] ?? 'File')).': '.trim((string) ($attachment['url'] ?? $attachment['path'] ?? '')))
            ->filter()
            ->implode("\n");

        return trim($content."\n".$attachments) ?: '[Tin nhan khong co noi dung]';
    }

    private function eventPayload(array $payload): ?array
    {
        $event = data_get($payload, 'payload.event')
            ?: data_get($payload, 'event')
            ?: data_get($payload, 'payload.chat.thread.events.0')
            ?: data_get($payload, 'chat.thread.events.0');

        return is_array($event) && data_get($event, 'type') === 'message' ? $event : null;
    }

    private function isCrmEcho(array $event): bool
    {
        $customId = (string) data_get($event, 'custom_id', '');

        return str_starts_with($customId, 'crm_');
    }

    private function isCustomerEcho(TextConversationLink $link, array $event): bool
    {
        $authorId = (string) data_get($event, 'author_id', '');

        return $authorId !== '' && $authorId === (string) $link->text_customer_id;
    }

    private function firstString(array $payload, array $paths): string
    {
        foreach ($paths as $path) {
            $value = data_get($payload, $path);

            if (is_scalar($value) && trim((string) $value) !== '') {
                return trim((string) $value);
            }
        }

        return '';
    }
}
