<?php

namespace Modules\Botpress\Services;

use Illuminate\Http\Client\Factory as Http;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\Botpress\Models\BotpressConversationLink;
use Modules\Conversation\Models\Conversation;
use Modules\Message\Events\NewMessageEvent;
use Modules\Message\Jobs\SendOutboundMessageJob;
use Modules\Message\Models\Message;

class BotpressChatService
{
    public function __construct(private readonly Http $http)
    {
    }

    public function enabled(): bool
    {
        return (bool) config('services.botpress.enabled', false)
            && $this->webhookId() !== '';
    }

    public function relayCustomerMessage(Message $inbound): void
    {
        if (! $this->enabled()) {
            Log::warning('Botpress relay skipped because integration is disabled', [
                'message_id' => $inbound->id,
                'enabled' => config('services.botpress.enabled'),
                'webhook_id' => $this->webhookId(),
                'webhook_url_configured' => filled(config('services.botpress.webhook_url')),
            ]);

            return;
        }

        if ($inbound->sender_type !== 'customer' || $inbound->channel !== 'facebook') {
            return;
        }

        $inbound->loadMissing('conversation.customer.channels');
        $conversation = $inbound->conversation;

        if (! $conversation?->customer) {
            Log::warning('Botpress relay skipped because conversation or customer is missing', [
                'message_id' => $inbound->id,
                'conversation_id' => $inbound->conversation_id,
            ]);

            return;
        }

        if ($this->botIsPaused($conversation)) {
            Log::warning('Botpress relay skipped because automation is paused', [
                'message_id' => $inbound->id,
                'conversation_id' => $conversation->id,
                'automation_state' => $conversation->automation_state,
            ]);

            return;
        }

        $link = $this->ensureLink($conversation);
        $beforeMessageId = $link->last_botpress_message_id;

        try {
            $sendPayload = [
                'conversationId' => $link->botpress_conversation_id,
                'payload' => [
                    'type' => 'text',
                    'text' => $this->messageText($inbound),
                ],
            ];

            $this->client($link->botpress_user_key)->post('/messages', $sendPayload)->throw();
            $reply = $this->waitForBotReply($link, $beforeMessageId);

            if (! $reply) {
                Log::warning('Botpress relay finished without bot reply', [
                    'conversation_id' => $conversation->id,
                    'message_id' => $inbound->id,
                    'botpress_conversation_id' => $link->botpress_conversation_id,
                    'last_botpress_message_id' => $link->last_botpress_message_id,
                ]);

                return;
            }

            $this->storeBotReply($conversation, $link, $reply);
        } catch (\Throwable $exception) {
            Log::warning('Botpress relay failed', [
                'conversation_id' => $conversation->id,
                'message_id' => $inbound->id,
                ...$this->exceptionContext($exception),
            ]);
        }
    }

    private function ensureLink(Conversation $conversation): BotpressConversationLink
    {
        $link = BotpressConversationLink::query()->where('conversation_id', $conversation->id)->first();

        if ($link?->botpress_user_key && $link->botpress_conversation_id) {
            return $link;
        }

        $customer = $conversation->customer;
        $userId = 'crm_conversation_'.$conversation->id.'_customer_'.$customer->id;
        $userKey = $link?->botpress_user_key ?: $this->userKeyFor($userId);

        if ($this->usesManualAuth()) {
            $userResponse = $this->client($userKey)->post('/users/get-or-create', [
                'name' => (string) ($customer->name ?: $userId),
                'pictureUrl' => (string) ($customer->avatar ?: ''),
                'profile' => json_encode([
                    'crm_customer_id' => $customer->id,
                    'facebook_page_id' => $conversation->facebook_page_id,
                ], JSON_UNESCAPED_SLASHES),
            ])->throw()->json();
            $botpressUserId = (string) data_get($userResponse, 'user.id', $userId);
        } else {
            $userResponse = $this->client()->post('/users', [
                'id' => $userId,
                'name' => (string) ($customer->name ?: $userId),
                'pictureUrl' => (string) ($customer->avatar ?: ''),
                'profile' => json_encode([
                    'crm_customer_id' => $customer->id,
                    'facebook_page_id' => $conversation->facebook_page_id,
                ], JSON_UNESCAPED_SLASHES),
            ])->throw()->json();
            $botpressUserId = (string) data_get($userResponse, 'user.id', $userId);
            $userKey = (string) data_get($userResponse, 'key', $userKey);
        }

        if ($userKey === '') {
            throw new \RuntimeException('Botpress user response did not include key: '.json_encode($userResponse));
        }

        $conversationResponse = $this->client($userKey)->post('/conversations/get-or-create', [
            'id' => 'crm_conversation_'.$conversation->id,
        ])->throw()->json();
        $botpressConversationId = (string) data_get($conversationResponse, 'conversation.id', '');

        if ($botpressConversationId === '') {
            throw new \RuntimeException('Botpress conversation response did not include conversation.id: '.json_encode($conversationResponse));
        }

        return BotpressConversationLink::query()->updateOrCreate(
            ['conversation_id' => $conversation->id],
            [
                'botpress_user_id' => $botpressUserId,
                'botpress_user_key' => $userKey,
                'botpress_conversation_id' => $botpressConversationId,
                'last_payload' => [
                    'source' => 'ensure_link',
                    'user' => data_get($userResponse, 'user'),
                    'conversation' => data_get($conversationResponse, 'conversation'),
                ],
            ],
        );
    }

    private function waitForBotReply(BotpressConversationLink $link, ?string $beforeMessageId): ?array
    {
        $attempts = max(1, (int) config('services.botpress.response_poll_attempts', 8));
        $delayMs = max(100, (int) config('services.botpress.response_poll_delay_ms', 700));

        for ($attempt = 0; $attempt < $attempts; $attempt++) {
            if ($attempt > 0) {
                usleep($delayMs * 1000);
            }

            $messages = $this->client((string) $link->botpress_user_key)
                ->get('/conversations/'.$link->botpress_conversation_id.'/messages')
                ->throw()
                ->json('messages', []);

            $reply = collect($messages)
                ->filter(fn (array $message): bool => $this->isNewBotMessage($message, $link, $beforeMessageId))
                ->sortBy('createdAt')
                ->first();

            if (is_array($reply)) {
                return $reply;
            }
        }

        return null;
    }

    private function isNewBotMessage(array $message, BotpressConversationLink $link, ?string $beforeMessageId): bool
    {
        $messageId = (string) data_get($message, 'id', '');

        if ($messageId === '' || $messageId === $beforeMessageId || $messageId === (string) $link->last_botpress_message_id) {
            return false;
        }

        if ((string) data_get($message, 'userId', '') === (string) $link->botpress_user_id) {
            return false;
        }

        return trim((string) data_get($message, 'payload.text', '')) !== '';
    }

    private function storeBotReply(Conversation $conversation, BotpressConversationLink $link, array $reply): void
    {
        $content = trim((string) data_get($reply, 'payload.text', ''));

        if ($content === '') {
            return;
        }

        $clientMessageId = 'botpress_'.$reply['id'];

        if (Message::query()->where('client_message_id', $clientMessageId)->exists()) {
            return;
        }

        $message = DB::transaction(function () use ($conversation, $link, $reply, $content, $clientMessageId): Message {
            $message = Message::query()->create([
                'conversation_id' => $conversation->id,
                'sender_type' => 'system',
                'sender_id' => null,
                'channel' => 'facebook',
                'content' => $content,
                'message_type' => 'text',
                'attachments' => [[
                    'type' => 'metadata',
                    'name' => 'botpress',
                    'payload' => ['message' => $reply],
                ]],
                'client_message_id' => $clientMessageId,
                'outbound_status' => 'queued',
            ]);

            $conversation->forceFill([
                'last_message_at' => $message->created_at,
                'first_response_at' => $conversation->first_response_at ?: now(),
            ])->save();

            $link->forceFill([
                'last_botpress_message_id' => (string) data_get($reply, 'id', ''),
                'last_payload' => ['last_reply' => $reply],
            ])->save();

            return $message->load(['conversation.customer.channels', 'sender']);
        });

        try {
            event(new NewMessageEvent($message));
        } catch (\Throwable) {
        }

        SendOutboundMessageJob::dispatch($message->id);
    }

    private function botIsPaused(Conversation $conversation): bool
    {
        return filled(data_get($conversation->automation_state ?? [], 'paused_by_user_at'));
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

    private function client(?string $userKey = null): \Illuminate\Http\Client\PendingRequest
    {
        $request = $this->http
            ->baseUrl($this->baseUrl())
            ->acceptJson()
            ->asJson()
            ->timeout(20);

        $apiKey = trim((string) config('services.botpress.api_key', ''));

        if ($apiKey !== '') {
            $request = $request->withToken($apiKey);
        }

        if ($userKey) {
            $request = $request->withHeaders(['x-user-key' => $userKey]);
        }

        return $request;
    }

    private function baseUrl(): string
    {
        return rtrim((string) config('services.botpress.base_url', 'https://chat.botpress.cloud'), '/')
            .'/'.$this->webhookId();
    }

    private function webhookId(): string
    {
        $id = trim((string) config('services.botpress.webhook_id', ''));

        if ($id !== '') {
            return trim($id, '/');
        }

        $url = trim((string) config('services.botpress.webhook_url', ''));

        if ($url === '') {
            return '';
        }

        $path = trim((string) parse_url($url, PHP_URL_PATH), '/');

        return $path !== '' ? basename($path) : '';
    }

    private function userKeyFor(string $userId): string
    {
        if (! $this->usesManualAuth()) {
            return '';
        }

        return $this->jwt(['id' => $userId], (string) config('services.botpress.encryption_key'));
    }

    private function usesManualAuth(): bool
    {
        return trim((string) config('services.botpress.encryption_key', '')) !== '';
    }

    private function jwt(array $payload, string $secret): string
    {
        $header = ['alg' => 'HS256', 'typ' => 'JWT'];
        $segments = [
            $this->base64Url(json_encode($header, JSON_UNESCAPED_SLASHES) ?: '{}'),
            $this->base64Url(json_encode($payload, JSON_UNESCAPED_SLASHES) ?: '{}'),
        ];

        $signature = hash_hmac('sha256', implode('.', $segments), $secret, true);
        $segments[] = $this->base64Url($signature);

        return implode('.', $segments);
    }

    private function base64Url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
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
