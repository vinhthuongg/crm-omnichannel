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

        try {
            if ($this->usesDirectWebhook()) {
                $reply = $this->sendDirectWebhook($conversation, $inbound);

                if (! $reply) {
                    return;
                }

                $this->storeDirectWebhookReply($conversation, $reply);

                return;
            }

            $link = $this->ensureLink($conversation);
            $beforeMessageId = $link->last_botpress_message_id;

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

    public function receiveWebhookReply(array $payload): ?Message
    {
        if ($this->isCustomerEchoCallback($payload)) {
            Log::info('Botpress callback ignored because it is the relayed customer message', [
                'botpress_message_id' => data_get($payload, 'data.id'),
                'conversation_id' => data_get($payload, 'data.conversationId'),
            ]);

            return null;
        }

        $conversationId = $this->extractCrmConversationId($payload);
        $content = $this->extractReplyText($payload);

        if (! $conversationId || ! $content) {
            Log::warning('Botpress callback ignored because payload is missing conversation or text', [
                'conversation_id' => $conversationId,
                'has_text' => filled($content),
                'payload' => mb_substr(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}', 0, 2000),
            ]);

            return null;
        }

        $conversation = Conversation::query()
            ->with('customer.channels')
            ->find($conversationId);

        if (! $conversation) {
            Log::warning('Botpress callback ignored because CRM conversation was not found', [
                'conversation_id' => $conversationId,
            ]);

            return null;
        }

        if ($this->botIsPaused($conversation)) {
            Log::warning('Botpress callback ignored because automation is paused', [
                'conversation_id' => $conversationId,
                'automation_state' => $conversation->automation_state,
            ]);

            return null;
        }

        $sourceId = (string) (
            data_get($payload, 'id')
            ?: data_get($payload, 'message.id')
            ?: data_get($payload, 'event.id')
            ?: data_get($payload, 'metadata.message_id')
            ?: sha1($conversationId.'|'.$content.'|'.json_encode($payload))
        );

        return $this->storeExternalBotReply($conversation, $content, 'botpress_callback_'.$sourceId, [
            'type' => 'metadata',
            'name' => 'botpress_callback',
            'payload' => $payload,
        ], $payload);
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
            $response = $this->client($userKey)->post('/users/get-or-create', [
                'name' => (string) ($customer->name ?: $userId),
                'pictureUrl' => (string) ($customer->avatar ?: ''),
                'profile' => json_encode([
                    'crm_customer_id' => $customer->id,
                    'facebook_page_id' => $conversation->facebook_page_id,
                ], JSON_UNESCAPED_SLASHES),
            ])->throw();
            $userResponse = $response->json();
            $botpressUserId = (string) data_get($userResponse, 'user.id', $userId);
        } else {
            $response = $this->client()->post('/users', [
                'id' => $userId,
                'name' => (string) ($customer->name ?: $userId),
                'pictureUrl' => (string) ($customer->avatar ?: ''),
                'profile' => json_encode([
                    'crm_customer_id' => $customer->id,
                    'facebook_page_id' => $conversation->facebook_page_id,
                ], JSON_UNESCAPED_SLASHES),
            ])->throw();
            $userResponse = $response->json();
            $botpressUserId = (string) data_get($userResponse, 'user.id', $userId);
            $userKey = (string) data_get($userResponse, 'key', $userKey);
        }

        if ($userKey === '') {
            throw new \RuntimeException('Botpress user response did not include key: status='.$response->status().' body='.$response->body());
        }

        $conversationHttpResponse = $this->client($userKey)->post('/conversations/get-or-create', [
            'id' => 'crm_conversation_'.$conversation->id,
        ])->throw();
        $conversationResponse = $conversationHttpResponse->json();
        $botpressConversationId = (string) data_get($conversationResponse, 'conversation.id', '');

        if ($botpressConversationId === '') {
            throw new \RuntimeException('Botpress conversation response did not include conversation.id: status='.$conversationHttpResponse->status().' body='.$conversationHttpResponse->body());
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
                'attachments' => $this->botAttachments($content, [
                    'type' => 'metadata',
                    'name' => 'botpress',
                    'payload' => ['message' => $reply],
                ], $reply),
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

    private function sendDirectWebhook(Conversation $conversation, Message $message): ?string
    {
        $url = trim((string) config('services.botpress.webhook_url', ''));

        if ($url === '') {
            return null;
        }

        $response = $this->http
            ->acceptJson()
            ->asJson()
            ->withToken(trim((string) config('services.botpress.api_key', '')))
            ->timeout(30)
            ->post($url, [
                'type' => 'message',
                'source' => 'crm',
                'conversationId' => 'crm_conversation_'.$conversation->id,
                'userId' => 'crm_conversation_'.$conversation->id.'_customer_'.$conversation->customer_id,
                'text' => $this->messageText($message),
                'callbackUrl' => $this->callbackUrl(),
                'callbackSecret' => trim((string) config('services.botpress.callback_secret', '')),
                'message' => [
                    'id' => $message->id,
                    'text' => $this->messageText($message),
                    'created_at' => $message->created_at?->toISOString(),
                ],
                'customer' => [
                    'id' => $conversation->customer_id,
                    'name' => $conversation->customer?->name,
                    'avatar' => $conversation->customer?->avatar,
                ],
                'metadata' => [
                    'facebook_page_id' => $conversation->facebook_page_id,
                    'crm_conversation_id' => $conversation->id,
                    'callback_url' => $this->callbackUrl(),
                ],
            ]);

        if ($response->failed()) {
            throw new \RuntimeException('Botpress direct webhook failed: status='.$response->status().' body='.$response->body());
        }

        $reply = $this->extractReplyText($response->json());

        if ($reply === null) {
            Log::warning('Botpress direct webhook returned no reply text', [
                'conversation_id' => $conversation->id,
                'message_id' => $message->id,
                'status' => $response->status(),
                'body' => mb_substr($response->body(), 0, 2000),
            ]);
        }

        return $reply;
    }

    private function storeDirectWebhookReply(Conversation $conversation, string $content): void
    {
        $this->storeExternalBotReply($conversation, $content, 'botpress_direct_'.sha1($conversation->id.'|'.$content.'|'.now()->timestamp), [
            'type' => 'metadata',
            'name' => 'botpress_direct_webhook',
            'payload' => ['source' => 'direct_webhook'],
        ]);
    }

    private function storeExternalBotReply(Conversation $conversation, string $content, string $clientMessageId, array $metadata, array $payload = []): ?Message
    {
        if (Message::query()->where('client_message_id', $clientMessageId)->exists()) {
            return null;
        }

        $message = DB::transaction(function () use ($conversation, $content, $clientMessageId, $metadata, $payload): Message {
            $message = Message::query()->create([
                'conversation_id' => $conversation->id,
                'sender_type' => 'system',
                'sender_id' => null,
                'channel' => 'facebook',
                'content' => $content,
                'message_type' => 'text',
                'attachments' => $this->botAttachments($content, $metadata, $payload),
                'client_message_id' => $clientMessageId,
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

    private function botAttachments(string $content, array $metadata, array $payload = []): array
    {
        $quickReplies = $this->quickRepliesForBotMessage($content, $payload);

        if ($quickReplies === []) {
            return [$metadata];
        }

        return [
            $metadata,
            [
                'type' => 'quick_reply',
                'quick_replies' => $quickReplies,
            ],
        ];
    }

    private function quickRepliesForBotMessage(string $content, array $payload = []): array
    {
        $custom = data_get($payload, 'quick_replies')
            ?: data_get($payload, 'quickReplies')
            ?: data_get($payload, 'suggestions')
            ?: data_get($payload, 'buttons')
            ?: data_get($payload, 'payload.quick_replies')
            ?: data_get($payload, 'payload.quickReplies');

        $items = is_array($custom) && $custom !== []
            ? $this->normalizeQuickReplies($custom)
            : $this->defaultQuickReplies($content);

        return array_values(array_slice($items, 0, 11));
    }

    private function normalizeQuickReplies(array $items): array
    {
        return collect($items)
            ->map(function (mixed $item): ?array {
                $title = is_array($item)
                    ? (string) (data_get($item, 'title') ?: data_get($item, 'text') ?: data_get($item, 'label') ?: data_get($item, 'name'))
                    : (string) $item;

                $title = trim($title);

                if ($title === '') {
                    return null;
                }

                return $this->quickReply($title, is_array($item) ? (string) (data_get($item, 'payload') ?: $title) : $title);
            })
            ->filter()
            ->values()
            ->all();
    }

    private function defaultQuickReplies(string $content): array
    {
        $lower = mb_strtolower($content);

        if (str_contains($lower, 'trả góp') || str_contains($lower, 'lai suat') || str_contains($lower, 'lãi suất') || str_contains($lower, 'vay')) {
            return [
                $this->quickReply('Tính góp giúp anh'),
                $this->quickReply('Cần giấy tờ gì?'),
                $this->quickReply('Lãi suất sao em?'),
                $this->quickReply('Anh gửi SĐT nhé'),
            ];
        }

        if (str_contains($lower, 'lái thử') || str_contains($lower, 'dat lich') || str_contains($lower, 'đặt lịch')) {
            return [
                $this->quickReply('Lái thử hôm nay'),
                $this->quickReply('Mai còn lịch không?'),
                $this->quickReply('Cần mang gì em?'),
                $this->quickReply('Anh gửi SĐT nhé'),
            ];
        }

        if (str_contains($lower, 'giá') || str_contains($lower, 'khuyến mãi') || str_contains($lower, 'ưu đãi')) {
            return [
                $this->quickReply('Lăn bánh bao nhiêu?'),
                $this->quickReply('Có ưu đãi gì?'),
                $this->quickReply('Trả góp sao em?'),
                $this->quickReply('Anh gửi SĐT nhé'),
            ];
        }

        return [
            $this->quickReply('Tư vấn mẫu phù hợp'),
            $this->quickReply('Xin giá lăn bánh'),
            $this->quickReply('Xem ưu đãi'),
            $this->quickReply('Anh muốn lái thử'),
        ];
    }

    private function quickReply(string $title, ?string $payload = null): array
    {
        $title = mb_substr(trim($title), 0, 20);

        return [
            'content_type' => 'text',
            'title' => $title,
            'payload' => mb_substr(trim($payload ?: $title), 0, 1000),
        ];
    }

    private function extractReplyText(mixed $payload): ?string
    {
        if (is_string($payload) && trim($payload) !== '') {
            return trim($payload);
        }

        if (! is_array($payload)) {
            return null;
        }

        foreach (['text', 'reply', 'message', 'response.text', 'output.text', 'data.text', 'data.payload.text', 'payload.text'] as $path) {
            $value = data_get($payload, $path);

            if (is_scalar($value) && trim((string) $value) !== '') {
                return trim((string) $value);
            }
        }

        foreach (['messages', 'responses', 'output.messages'] as $path) {
            $items = data_get($payload, $path, []);

            if (! is_array($items)) {
                continue;
            }

            foreach ($items as $item) {
                $value = data_get($item, 'payload.text')
                    ?: data_get($item, 'text')
                    ?: data_get($item, 'message');

                if (is_scalar($value) && trim((string) $value) !== '') {
                    return trim((string) $value);
                }
            }
        }

        return null;
    }

    private function extractCrmConversationId(array $payload): ?int
    {
        $value = data_get($payload, 'metadata.crm_conversation_id')
            ?: data_get($payload, 'crm_conversation_id')
            ?: data_get($payload, 'conversation.crm_conversation_id')
            ?: data_get($payload, 'conversationId')
            ?: data_get($payload, 'conversation.id')
            ?: data_get($payload, 'conversation_id')
            ?: data_get($payload, 'data.conversationId')
            ?: data_get($payload, 'data.conversation.id');

        if (is_numeric($value)) {
            return (int) $value;
        }

        if (is_string($value) && preg_match('/crm_conversation_(\d+)/', $value, $matches)) {
            return (int) $matches[1];
        }

        return null;
    }

    private function isCustomerEchoCallback(array $payload): bool
    {
        if ((string) data_get($payload, 'type') !== 'message_created') {
            return false;
        }

        return data_get($payload, 'data.isBot') === false;
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

    private function usesDirectWebhook(): bool
    {
        $url = trim((string) config('services.botpress.webhook_url', ''));

        return str_contains($url, 'webhook.botpress.cloud');
    }

    private function callbackUrl(): string
    {
        $secret = trim((string) config('services.botpress.callback_secret', ''));
        $url = route('botpress.callback', [], true);

        return $secret === '' ? $url : $url.'?secret='.rawurlencode($secret);
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
