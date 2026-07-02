<?php

namespace Modules\Text\Services;

use Illuminate\Http\Client\RequestException;
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
        private readonly TextCustomerChatService $customerText,
        private readonly TextConversationBridge $bridge,
    ) {
    }

    public function enabled(): bool
    {
        return (bool) config('services.text.bridge_enabled', false)
            && $this->customerText->configured();
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

        $link = $this->ensureCustomerToken($conversation, $conversation->textConversationLink);
        $customerId = (string) $link->text_customer_id;
        $event = $this->customerMessageEvent($message, 'crm_in_'.$message->id);

        try {
            if (! $link?->text_chat_id) {
                $response = $this->customerText->startChat((string) $link->text_customer_access_token, $event);
                $this->storeLink($conversation, $response, $customerId, [
                    'source' => 'crm_gateway_start',
                    'message_id' => $message->id,
                    'text_event' => $event,
                ]);

                return;
            }

            try {
                $this->customerText->sendEvent((string) $link->text_customer_access_token, $link->text_chat_id, $event);
            } catch (\Throwable $exception) {
                Log::warning('Text.com send_event failed, trying resume_chat', [
                    'conversation_id' => $conversation->id,
                    'message_id' => $message->id,
                    'text_chat_id' => $link->text_chat_id,
                    'error' => $exception->getMessage(),
                ]);

                $response = $this->customerText->resumeChat((string) $link->text_customer_access_token, $link->text_chat_id, $event);
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
                ...$this->exceptionContext($exception),
            ]);
        }
    }

    public function relayAgentMessage(Message $message): void
    {
        if (! (bool) config('services.text.bridge_enabled', false)
            || ! $this->text->configured()
            || $message->sender_type !== 'user'
            || $message->channel !== 'facebook') {
            return;
        }

        $message->loadMissing('conversation.textConversationLink');
        $link = $message->conversation?->textConversationLink;

        if (! $link?->text_chat_id) {
            return;
        }

        try {
            $this->text->sendEvent($link->text_chat_id, $this->agentMessageEvent($message, 'crm_out_'.$message->id));
        } catch (\Throwable $exception) {
            Log::warning('Text.com agent relay failed', [
                'conversation_id' => $message->conversation_id,
                'message_id' => $message->id,
                'text_chat_id' => $link->text_chat_id,
                ...$this->exceptionContext($exception),
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
            $attachments = [[
                'type' => 'metadata',
                'name' => 'text_gateway',
                'payload' => [
                    'text_event' => $event,
                    'raw' => $payload,
                ],
            ]];

            $message = Message::query()->create([
                'conversation_id' => $conversation->id,
                'sender_type' => 'system',
                'sender_id' => null,
                'channel' => 'facebook',
                'content' => $content,
                'message_type' => 'text',
                'attachments' => $attachments,
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

    private function ensureCustomerToken(Conversation $conversation, ?TextConversationLink $link): TextConversationLink
    {
        $hasUsableToken = $link?->text_customer_access_token
            && (! $link->text_customer_token_expires_at || $link->text_customer_token_expires_at->isFuture());

        if ($hasUsableToken) {
            return $link;
        }

        $tokenPayload = $this->customerText->issueCustomerToken($link?->text_customer_id ?: null);
        $accessToken = (string) ($tokenPayload['access_token'] ?? '');

        if ($accessToken === '') {
            throw new \RuntimeException('Text.com customer token response did not include access_token.');
        }

        $customerId = (string) ($tokenPayload['entity_id'] ?? $link?->text_customer_id ?? $this->customerUserId($conversation));

        return $this->storeCustomerToken($conversation, $customerId, $accessToken, $tokenPayload);
    }

    private function storeCustomerToken(Conversation $conversation, string $customerId, string $accessToken, array $tokenPayload): TextConversationLink
    {
        $facebookChannel = $conversation->customer?->channels()
            ->where('channel', 'facebook')
            ->first();

        return TextConversationLink::query()->updateOrCreate(
            ['conversation_id' => $conversation->id],
            [
                'text_chat_id' => $conversation->textConversationLink?->text_chat_id,
                'text_thread_id' => $conversation->textConversationLink?->text_thread_id,
                'text_customer_id' => $customerId,
                'text_customer_access_token' => $accessToken,
                'text_customer_token_expires_at' => $this->customerText->tokenExpiresAt($tokenPayload),
                'facebook_page_id' => $conversation->facebook_page_id,
                'facebook_psid' => $facebookChannel?->external_id,
                'last_payload' => [
                    'source' => 'text_customer_token',
                    'token_payload' => array_diff_key($tokenPayload, ['access_token' => true]),
                ],
            ],
        );
    }

    private function storeLink(Conversation $conversation, array $response, string $customerId, array $payload): TextConversationLink
    {
        $facebookChannel = $conversation->customer?->channels()
            ->where('channel', 'facebook')
            ->first();
        $existingLink = TextConversationLink::query()
            ->where('conversation_id', $conversation->id)
            ->first();

        return TextConversationLink::query()->updateOrCreate(
            ['conversation_id' => $conversation->id],
            [
                'text_chat_id' => (string) data_get($response, 'chat_id', $existingLink?->text_chat_id),
                'text_thread_id' => (string) data_get($response, 'thread_id', $existingLink?->text_thread_id),
                'text_customer_id' => $customerId,
                'text_customer_access_token' => $existingLink?->text_customer_access_token,
                'text_customer_token_expires_at' => $existingLink?->text_customer_token_expires_at,
                'facebook_page_id' => $conversation->facebook_page_id,
                'facebook_psid' => $facebookChannel?->external_id,
                'last_payload' => $payload + ['response' => $response],
            ],
        );
    }

    private function customerUserId(Conversation $conversation): string
    {
        return 'crm_customer_'.$conversation->customer_id;
    }

    private function customerMessageEvent(Message $message, string $customId): array
    {
        return [
            'type' => 'message',
            'text' => $this->messageText($message),
            'recipients' => 'all',
            'custom_id' => $customId,
        ];
    }

    private function agentMessageEvent(Message $message, string $customId): array
    {
        return [
            'type' => 'message',
            'text' => $this->messageText($message),
            'custom_id' => $customId,
        ];
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

    private function exceptionContext(\Throwable $exception): array
    {
        if ($exception instanceof RequestException && $exception->response) {
            return [
                'error' => $exception->getMessage(),
                'status' => $exception->response->status(),
                'body' => mb_substr($exception->response->body(), 0, 2000),
            ];
        }

        return ['error' => $exception->getMessage()];
    }
}
