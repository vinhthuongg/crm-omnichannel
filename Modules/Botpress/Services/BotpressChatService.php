<?php

namespace Modules\Botpress\Services;

use App\Services\GroqQuickReplySuggestionService;
use Illuminate\Http\Client\Factory as Http;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Modules\Botpress\Models\BotpressConversationLink;
use Modules\Conversation\Models\Conversation;
use Modules\Conversation\Support\ConversationStatus;
use Modules\Message\Events\NewMessageEvent;
use Modules\Message\Jobs\SendCustomerIdleFollowUpJob;
use Modules\Message\Jobs\SendOutboundMessageJob;
use Modules\Message\Models\Message;

class BotpressChatService
{
    public function __construct(
        private readonly Http $http,
        private readonly GroqQuickReplySuggestionService $groqQuickReplies,
    )
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
            $knownMessageIds = (bool) config('services.botpress.snapshot_known_messages', false)
                ? $this->knownBotpressMessageIds($link)
                : [];

            Log::info('Botpress relay link ready', [
                'conversation_id' => $conversation->id,
                'message_id' => $inbound->id,
                'botpress_conversation_id' => $link->botpress_conversation_id,
                'botpress_user_id' => $link->botpress_user_id,
                'last_botpress_message_id' => $beforeMessageId,
                'known_botpress_message_count' => count($knownMessageIds),
                'prefer_callback' => $this->preferCallback(),
            ]);

            $outboundText = $this->messageText($inbound);
            $sendPayload = [
                'conversationId' => $link->botpress_conversation_id,
                'payload' => [
                    'type' => 'text',
                    'text' => $outboundText,
                ],
            ];

            $sendResponse = $this->client($link->botpress_user_key)->post('/messages', $sendPayload)->throw();
            $sentBotpressMessage = (array) data_get($sendResponse->json(), 'message', []);
            $sentBotpressMessageId = (string) data_get($sentBotpressMessage, 'id', '');
            $sentBotpressCreatedAt = (string) data_get($sentBotpressMessage, 'createdAt', '');

            Log::info('Botpress customer message sent', [
                'conversation_id' => $conversation->id,
                'message_id' => $inbound->id,
                'botpress_conversation_id' => $link->botpress_conversation_id,
                'status' => $sendResponse->status(),
                'botpress_message_id' => $sentBotpressMessageId,
                'botpress_created_at' => $sentBotpressCreatedAt,
                'text_preview' => mb_substr($outboundText, 0, 240),
            ]);

            if ($this->preferCallback()) {
                Log::info('Botpress relay sent and waiting for callback', [
                    'conversation_id' => $conversation->id,
                    'message_id' => $inbound->id,
                    'botpress_conversation_id' => $link->botpress_conversation_id,
                ]);

                return;
            }

            $replies = $this->waitForBotReplies($link, $beforeMessageId, $knownMessageIds, $sentBotpressCreatedAt, $sentBotpressMessageId);

            if ($replies === []) {
                Log::warning('Botpress relay finished without bot reply', [
                    'conversation_id' => $conversation->id,
                    'message_id' => $inbound->id,
                    'botpress_conversation_id' => $link->botpress_conversation_id,
                    'last_botpress_message_id' => $link->last_botpress_message_id,
                ]);

                return;
            }

            $replies = $this->filterBotRepliesForCustomer($conversation, $replies);

            $sessionQuickReplyContent = $this->botReplySessionContent($replies);
            $lastReplyIndex = count($replies) - 1;

            foreach ($replies as $index => $reply) {
                $message = $this->storeBotReply(
                    $conversation,
                    $link,
                    $reply,
                    includeQuickReplies: $index === $lastReplyIndex,
                    quickReplySourceContent: $sessionQuickReplyContent,
                );

                if (! $message) {
                    Log::info('Botpress relay reply skipped because it was already stored', [
                        'conversation_id' => $conversation->id,
                        'message_id' => $inbound->id,
                        'botpress_reply_id' => (string) data_get($reply, 'id', ''),
                    ]);

                    continue;
                }

                Log::info('Botpress relay stored bot reply', [
                    'conversation_id' => $conversation->id,
                    'message_id' => $inbound->id,
                    'stored_message_id' => $message->id,
                    'botpress_reply_id' => (string) data_get($reply, 'id', ''),
                    'reply_preview' => mb_substr((string) data_get($reply, 'payload.text', ''), 0, 240),
                ]);
            }
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
        $attachments = $this->botpressAttachments($payload);

        if (! $conversationId || (! $content && $attachments === [])) {
            Log::warning('Botpress callback ignored because payload is missing conversation or text', [
                'conversation_id' => $conversationId,
                'has_text' => filled($content),
                'attachments_count' => count($attachments),
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

        if ($this->recentBotCallbackExists($conversation)) {
            Log::info('Botpress callback ignored because another bot reply was just queued', [
                'conversation_id' => $conversationId,
                'content' => mb_substr($content, 0, 160),
                'attachments_count' => count($attachments),
            ]);

            return null;
        }

        $sourceId = (string) (
            data_get($payload, 'id')
            ?: data_get($payload, 'data.id')
            ?: data_get($payload, 'message.id')
            ?: data_get($payload, 'event.id')
            ?: data_get($payload, 'metadata.message_id')
            ?: sha1($conversationId.'|'.$content.'|'.json_encode($payload))
        );

        return $this->storeExternalBotReply($conversation, (string) $content, 'botpress_callback_'.$sourceId, [
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

    private function waitForBotReplies(
        BotpressConversationLink $link,
        ?string $beforeMessageId,
        array $knownMessageIds = [],
        string $afterCreatedAt = '',
        string $afterMessageId = '',
    ): array
    {
        $attempts = max(1, (int) config('services.botpress.response_poll_attempts', 18));
        $delayMs = max(100, (int) config('services.botpress.response_poll_delay_ms', 300));
        $stableThreshold = max(2, (int) config('services.botpress.response_poll_stable_attempts', 1));
        $foundReplies = [];
        $stableAttempts = 0;

        for ($attempt = 0; $attempt < $attempts; $attempt++) {
            if ($attempt > 0) {
                usleep($delayMs * 1000);
            }

            $messages = $this->client((string) $link->botpress_user_key)
                ->get('/conversations/'.$link->botpress_conversation_id.'/messages')
                ->throw()
                ->json('messages', []);

            $replies = collect($messages)
                ->filter(fn (array $message): bool => $this->isNewBotMessage($message, $link, $beforeMessageId, $knownMessageIds, $afterCreatedAt, $afterMessageId))
                ->sortBy('createdAt')
                ->values()
                ->all();

            $previousCount = count($foundReplies);

            foreach ($replies as $reply) {
                $replyId = (string) data_get($reply, 'id', '');

                if ($replyId !== '') {
                    $foundReplies[$replyId] = $reply;
                }
            }

            $stableAttempts = count($foundReplies) > $previousCount ? 0 : $stableAttempts + 1;
            $hasMediaReplies = collect($foundReplies)
                ->contains(fn (array $reply): bool => $this->botpressAttachments($reply) !== []);
            $effectiveStableThreshold = $hasMediaReplies ? max($stableThreshold, 3) : $stableThreshold;

            Log::info('Botpress reply poll attempt', [
                'botpress_conversation_id' => $link->botpress_conversation_id,
                'attempt' => $attempt + 1,
                'attempts' => $attempts,
                'messages_count' => is_array($messages) ? count($messages) : 0,
                'before_message_id' => $beforeMessageId,
                'known_botpress_message_count' => count($knownMessageIds),
                'after_message_id' => $afterMessageId,
                'after_created_at' => $afterCreatedAt,
                'last_botpress_message_id' => $link->last_botpress_message_id,
                'reply_found' => $foundReplies !== [],
                'reply_count' => count($foundReplies),
                'stable_attempts' => $stableAttempts,
                'stable_threshold' => $effectiveStableThreshold,
                'has_media_replies' => $hasMediaReplies,
                'latest_messages' => collect($messages)
                    ->take(5)
                    ->map(fn (array $message): array => [
                        'id' => (string) data_get($message, 'id', ''),
                        'userId' => (string) data_get($message, 'userId', ''),
                        'text' => mb_substr(trim((string) data_get($message, 'payload.text', '')), 0, 120),
                        'payload_type' => (string) data_get($message, 'payload.type', ''),
                        'attachments_count' => count($this->botpressAttachments($message)),
                        'attachment_urls' => collect($this->botpressAttachments($message))
                            ->pluck('url')
                            ->values()
                            ->all(),
                        'payload_keys' => array_keys((array) data_get($message, 'payload', [])),
                    ])
                    ->values()
                    ->all(),
            ]);

            if ($foundReplies !== [] && $stableAttempts >= $effectiveStableThreshold) {
                return array_values($foundReplies);
            }
        }

        return array_values($foundReplies);
    }

    private function isNewBotMessage(
        array $message,
        BotpressConversationLink $link,
        ?string $beforeMessageId,
        array $knownMessageIds = [],
        string $afterCreatedAt = '',
        string $afterMessageId = '',
    ): bool
    {
        $messageId = (string) data_get($message, 'id', '');

        if ($messageId === ''
            || $messageId === $beforeMessageId
            || $messageId === $afterMessageId
            || $messageId === (string) $link->last_botpress_message_id
            || in_array($messageId, $knownMessageIds, true)) {
            return false;
        }

        if ((string) data_get($message, 'userId', '') === (string) $link->botpress_user_id) {
            return false;
        }

        if (trim((string) data_get($message, 'payload.text', '')) === ''
            && $this->botpressAttachments($message) === []) {
            return false;
        }

        $createdAt = (string) data_get($message, 'createdAt', '');

        if ($afterCreatedAt !== '' && $createdAt !== '' && strcmp($createdAt, $afterCreatedAt) <= 0) {
            return false;
        }

        return true;
    }

    private function knownBotpressMessageIds(BotpressConversationLink $link): array
    {
        try {
            $messages = $this->client((string) $link->botpress_user_key)
                ->get('/conversations/'.$link->botpress_conversation_id.'/messages')
                ->throw()
                ->json('messages', []);

            return collect($messages)
                ->map(fn (array $message): string => (string) data_get($message, 'id', ''))
                ->filter()
                ->unique()
                ->values()
                ->all();
        } catch (\Throwable $exception) {
            Log::warning('Botpress known message snapshot failed', [
                'botpress_conversation_id' => $link->botpress_conversation_id,
                'error' => $exception->getMessage(),
            ]);

            return [];
        }
    }

    private function filterBotRepliesForCustomer(Conversation $conversation, array $replies): array
    {
        $accepted = [];
        $existingTexts = $conversation->messages()
            ->where('sender_type', 'system')
            ->latest('created_at')
            ->limit(8)
            ->pluck('content')
            ->map(fn (?string $content): string => $this->normalizeReplyText((string) $content))
            ->filter()
            ->values()
            ->all();

        foreach ($replies as $reply) {
            $content = trim((string) data_get($reply, 'payload.text', ''));
            $attachments = $this->botpressAttachments($reply);
            $normalized = $this->normalizeReplyText($content);

            if (($content === '' && $attachments === []) || ($content !== '' && $this->shouldSkipBotReply($content))) {
                Log::info('Botpress relay reply skipped by content filter', [
                    'conversation_id' => $conversation->id,
                    'botpress_reply_id' => (string) data_get($reply, 'id', ''),
                    'reason' => $content === '' ? 'empty' : 'unwanted_detail',
                    'reply_preview' => mb_substr($content, 0, 240),
                    'payload_type' => (string) data_get($reply, 'payload.type', ''),
                ]);

                continue;
            }

            if ($normalized !== '' && $this->isDuplicateBotReply($normalized, [...$existingTexts, ...array_keys($accepted)])) {
                Log::info('Botpress relay reply skipped by duplicate filter', [
                    'conversation_id' => $conversation->id,
                    'botpress_reply_id' => (string) data_get($reply, 'id', ''),
                    'reply_preview' => mb_substr($content, 0, 240),
                ]);

                continue;
            }

            $accepted[$normalized !== '' ? $normalized : (string) data_get($reply, 'id', spl_object_id((object) $reply))] = $reply;
        }

        return array_values($accepted);
    }

    private function shouldSkipBotReply(string $content): bool
    {
        $text = mb_strtolower($content);

        if (str_contains($text, 'cách tính')
            || str_contains($text, 'công thức')
            || str_contains($text, 'gồm:')
            || str_contains($text, 'bao gồm:')) {
            return str_contains($text, 'thuế')
                || str_contains($text, 'phí biển')
                || str_contains($text, 'đăng kiểm')
                || str_contains($text, 'bảo trì đường bộ')
                || str_contains($text, 'bảo hiểm bắt buộc');
        }

        return false;
    }

    private function isDuplicateBotReply(string $normalized, array $previousTexts): bool
    {
        if ($normalized === '') {
            return true;
        }

        foreach ($previousTexts as $previous) {
            $previous = $this->normalizeReplyText((string) $previous);

            if ($previous === '') {
                continue;
            }

            if ($normalized === $previous) {
                return true;
            }

            similar_text($normalized, $previous, $percent);

            if ($percent >= 94) {
                return true;
            }
        }

        return false;
    }

    private function normalizeReplyText(string $content): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', mb_strtolower($content)));
    }

    private function storeBotReply(
        Conversation $conversation,
        BotpressConversationLink $link,
        array $reply,
        bool $includeQuickReplies = true,
        ?string $quickReplySourceContent = null,
    ): ?Message
    {
        $content = trim((string) data_get($reply, 'payload.text', ''));
        $botpressAttachments = $this->botpressAttachments($reply);

        if ($content === '' && $botpressAttachments === []) {
            return null;
        }

        $clientMessageId = 'botpress_'.$reply['id'];

        if (Message::query()->where('client_message_id', $clientMessageId)->exists()) {
            $link->forceFill([
                'last_botpress_message_id' => (string) data_get($reply, 'id', ''),
                'last_payload' => ['duplicate_reply' => $reply],
            ])->save();

            return null;
        }

        $message = DB::transaction(function () use ($conversation, $link, $reply, $content, $clientMessageId, $includeQuickReplies, $quickReplySourceContent, $botpressAttachments): Message {
            $attachments = $this->botAttachments($conversation, $quickReplySourceContent ?: $content, [
                'type' => 'metadata',
                'name' => 'botpress',
                'payload' => ['message' => $reply],
            ], $reply, $includeQuickReplies);

            $message = Message::query()->create([
                'conversation_id' => $conversation->id,
                'sender_type' => 'system',
                'sender_id' => null,
                'channel' => 'facebook',
                'content' => $content,
                'message_type' => $botpressAttachments === [] ? 'text' : 'attachment',
                'attachments' => [...$attachments, ...$botpressAttachments],
                'client_message_id' => $clientMessageId,
                'outbound_status' => 'queued',
            ]);

            $conversation->forceFill([
                'last_message_at' => $message->created_at,
                'first_response_at' => $conversation->first_response_at ?: now(),
                'status' => ConversationStatus::BOT_CONSULTING,
            ])->save();
            $conversation->markAsRead();

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

        $this->sendOutbound($message);
        SendCustomerIdleFollowUpJob::dispatchFor($message);
        Log::info('Botpress outbound message queued', [
            'conversation_id' => $conversation->id,
            'stored_message_id' => $message->id,
            'botpress_reply_id' => (string) data_get($reply, 'id', ''),
            'queue' => $this->sendOutboundSync() ? 'sync' : 'outbound',
            'channel' => $message->channel,
            'content_preview' => mb_substr($content, 0, 240),
            'attachments_count' => count($botpressAttachments),
        ]);

        return $message;
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

        $inlineQuickReplies = $this->extractInlineQuickReplies($content);

        if ($inlineQuickReplies['quick_replies'] !== []) {
            $content = $inlineQuickReplies['content'];
            $metadata['payload']['ignored_inline_quick_replies'] = $inlineQuickReplies['quick_replies'];

            Log::info('Botpress inline quick replies stripped before CRM-generated suggestions', [
                'conversation_id' => $conversation->id,
                'client_message_id' => $clientMessageId,
                'count' => count($inlineQuickReplies['quick_replies']),
                'titles' => array_column($inlineQuickReplies['quick_replies'], 'title'),
            ]);
        }

        $botpressAttachments = $this->botpressAttachments($payload);

        $message = DB::transaction(function () use ($conversation, $content, $clientMessageId, $metadata, $payload, $botpressAttachments): Message {
            $attachments = $this->botAttachments($conversation, $content, $metadata, $payload);

            $message = Message::query()->create([
                'conversation_id' => $conversation->id,
                'sender_type' => 'system',
                'sender_id' => null,
                'channel' => 'facebook',
                'content' => $content,
                'message_type' => $botpressAttachments === [] ? 'text' : 'attachment',
                'attachments' => [...$attachments, ...$botpressAttachments],
                'client_message_id' => $clientMessageId,
                'outbound_status' => 'queued',
            ]);

            $conversation->forceFill([
                'last_message_at' => $message->created_at,
                'first_response_at' => $conversation->first_response_at ?: now(),
                'status' => ConversationStatus::BOT_CONSULTING,
            ])->save();
            $conversation->markAsRead();

            return $message->load(['conversation.customer.channels', 'sender']);
        });

        try {
            event(new NewMessageEvent($message));
        } catch (\Throwable) {
        }

        $this->sendOutbound($message);
        SendCustomerIdleFollowUpJob::dispatchFor($message);
        Log::info('Botpress outbound message queued', [
            'conversation_id' => $conversation->id,
            'stored_message_id' => $message->id,
            'client_message_id' => $clientMessageId,
            'queue' => $this->sendOutboundSync() ? 'sync' : 'outbound',
            'channel' => $message->channel,
            'content_preview' => mb_substr($content, 0, 240),
            'attachments_count' => count($botpressAttachments),
        ]);

        return $message;
    }

    private function extractInlineQuickReplies(string $content): array
    {
        if (! preg_match('/<\s*QUICK_REPLIES\s*>(.*?)<\s*\/\s*QUICK_REPLIES\s*>/is', $content, $matches)) {
            return [
                'content' => trim($content),
                'quick_replies' => [],
            ];
        }

        $replyBlock = trim((string) ($matches[1] ?? ''));
        $cleanContent = trim((string) preg_replace('/<\s*QUICK_REPLIES\s*>.*?<\s*\/\s*QUICK_REPLIES\s*>/is', '', $content));
        $quickReplies = collect(preg_split('/\R+/', $replyBlock) ?: [])
            ->map(fn (string $line): string => trim(preg_replace('/^\s*[-*•\d.)]+\s*/u', '', $line) ?: ''))
            ->filter()
            ->unique()
            ->take(11)
            ->map(fn (string $title): array => $this->quickReply($title, $title))
            ->values()
            ->all();

        return [
            'content' => $cleanContent,
            'quick_replies' => $quickReplies,
        ];
    }

    private function botAttachments(Conversation $conversation, string $content, array $metadata, array $payload = [], bool $includeQuickReplies = true): array
    {
        if (! $includeQuickReplies || ! (bool) config('services.botpress.generate_quick_replies', true)) {
            return [$metadata];
        }

        $quickReplies = $this->quickRepliesForBotMessage($conversation, $content, $payload);

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

    private function botpressAttachments(array $message): array
    {
        $attachments = [];
        $payload = data_get($message, 'payload')
            ?: data_get($message, 'data.payload')
            ?: $message;

        $this->collectBotpressAttachments((array) $payload, $attachments);

        return collect($attachments)
            ->filter(fn (array $attachment): bool => (string) ($attachment['url'] ?? '') !== '')
            ->unique('url')
            ->values()
            ->all();
    }

    private function collectBotpressAttachments(mixed $node, array &$attachments): void
    {
        if (! is_array($node)) {
            return;
        }

        $url = $this->botpressAttachmentUrl($node);

        if ($url !== '') {
            $attachments[] = $this->botpressAttachmentFromNode($node, $url);
        }

        foreach ($node as $value) {
            if (is_array($value)) {
                $this->collectBotpressAttachments($value, $attachments);
            }
        }
    }

    private function botpressAttachmentUrl(array $node): string
    {
        foreach ([
            'imageUrl',
            'image_url',
            'fileUrl',
            'file_url',
            'downloadUrl',
            'download_url',
            'mediaUrl',
            'media_url',
            'src',
            'url',
            'image.url',
            'file.url',
            'media.url',
        ] as $path) {
            $value = data_get($node, $path);

            if (is_string($value) && $this->isPublicAttachmentUrl($value)) {
                return trim($value);
            }
        }

        return '';
    }

    private function botpressAttachmentFromNode(array $node, string $url): array
    {
        $mimeType = (string) (data_get($node, 'mimeType') ?: data_get($node, 'mime_type') ?: '');
        $type = $this->botpressAttachmentType($node, $mimeType, $url);
        $name = (string) (
            data_get($node, 'name')
            ?: data_get($node, 'title')
            ?: data_get($node, 'filename')
            ?: data_get($node, 'fileName')
            ?: basename((string) parse_url($url, PHP_URL_PATH))
        );

        return [
            'name' => $name !== '' ? $name : ucfirst($type),
            'url' => $url,
            'type' => $type,
            'mime_type' => $mimeType,
            'payload' => [
                'source' => 'botpress',
                'raw' => $node,
            ],
        ];
    }

    private function botpressAttachmentType(array $node, string $mimeType, string $url): string
    {
        $payloadType = Str::of((string) data_get($node, 'type', ''))->lower()->toString();
        $extension = Str::of((string) pathinfo((string) parse_url($url, PHP_URL_PATH), PATHINFO_EXTENSION))->lower()->toString();

        return match (true) {
            in_array($payloadType, ['image', 'video', 'audio', 'file'], true) => $payloadType,
            str_starts_with($mimeType, 'image/') => 'image',
            str_starts_with($mimeType, 'video/') => 'video',
            str_starts_with($mimeType, 'audio/') => 'audio',
            in_array($extension, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp'], true) => 'image',
            in_array($extension, ['mp4', 'mov', 'webm', 'm4v'], true) => 'video',
            in_array($extension, ['mp3', 'wav', 'ogg', 'm4a'], true) => 'audio',
            default => 'file',
        };
    }

    private function isPublicAttachmentUrl(string $url): bool
    {
        $url = trim($url);

        return Str::startsWith($url, 'https://');
    }

    private function botReplySessionContent(array $replies): string
    {
        return collect($replies)
            ->map(fn (array $reply): string => trim((string) data_get($reply, 'payload.text', '')))
            ->filter()
            ->implode("\n\n");
    }

    private function quickRepliesForBotMessage(Conversation $conversation, string $content, array $payload = []): array
    {
        $items = $this->groqQuickReplies->forBotMessage($content, $this->quickReplyContext($conversation));
        $shouldAskForPhone = $this->shouldAskForPhone($conversation, $content) || $this->quickRepliesAskForPhone($items);

        if ($shouldAskForPhone) {
            $items = [$this->phoneShareQuickReply(), ...$this->withoutPhoneTextQuickReplies($items)];
        }

        if ($items !== []) {
            return array_values(array_slice($items, 0, 13));
        }

        if (! (bool) config('services.groq.fallback_enabled', false)) {
            Log::info('Groq quick replies empty and hard fallback disabled', [
                'conversation_id' => $conversation->id,
                'content_preview' => mb_substr($content, 0, 160),
                'groq_enabled' => config('services.groq.enabled'),
                'has_groq_api_key' => filled(config('services.groq.api_key')),
            ]);

            return [];
        }

        $defaults = $this->defaultQuickReplies($content);
        $shouldAskForPhone = $shouldAskForPhone || $this->quickRepliesAskForPhone($defaults);

        if ($shouldAskForPhone) {
            $defaults = [$this->phoneShareQuickReply(), ...$this->withoutPhoneTextQuickReplies($defaults)];
        }

        return array_values(array_slice($defaults, 0, 13));
    }

    private function sendOutbound(Message $message): void
    {
        if (Cache::get('crm_load_test_skip_botpress_outbound')) {
            $message->forceFill([
                'outbound_status' => 'load_test_skipped',
                'outbound_error' => null,
                'sent_at' => now(),
            ])->save();

            Log::info('Botpress outbound skipped for CRM load test', [
                'conversation_id' => $message->conversation_id,
                'message_id' => $message->id,
                'channel' => $message->channel,
            ]);

            return;
        }

        if (! $this->sendOutboundSync()) {
            SendOutboundMessageJob::dispatch($message->id);

            return;
        }

        try {
            SendOutboundMessageJob::dispatchSync($message->id);
        } catch (\Throwable $exception) {
            Log::warning('Botpress sync outbound failed', [
                'conversation_id' => $message->conversation_id,
                'message_id' => $message->id,
                ...$this->exceptionContext($exception),
            ]);
        }
    }

    private function sendOutboundSync(): bool
    {
        return (bool) config('services.botpress.send_outbound_sync', true);
    }

    private function quickReplyContext(Conversation $conversation): array
    {
        $messages = $conversation->messages()
            ->latest('created_at')
            ->latest('id')
            ->limit(12)
            ->get()
            ->reverse()
            ->map(function (Message $message): array {
                return [
                    'role' => match ($message->sender_type) {
                        'customer' => 'customer',
                        'system' => 'bot',
                        'user' => $message->message_type === 'whisper' || $message->channel === 'internal' ? 'internal_note' : 'agent',
                        default => (string) $message->sender_type,
                    },
                    'type' => (string) $message->message_type,
                    'channel' => (string) $message->channel,
                    'text' => mb_substr(trim((string) $message->content), 0, 700),
                    'created_at' => optional($message->created_at)->toISOString(),
                ];
            })
            ->values()
            ->all();

        return [
            'conversation_id' => $conversation->id,
            'customer_name' => $conversation->customer?->name,
            'status' => $conversation->status,
            'messages' => $messages,
        ];
    }

    private function defaultQuickReplies(string $content): array
    {
        return $this->fastDefaultQuickReplies($content);
    }

    private function fastDefaultQuickReplies(string $content): array
    {
        $lower = Str::of($content)->lower()->ascii()->toString();

        if ($this->containsAny($lower, ['tra gop', 'lai suat', 'vay', 'ngan hang', 'ho so', 'tra truoc'])) {
            return [
                $this->quickReply('Tính góp xe này?', 'Khách muốn tính phương án trả góp cho mẫu xe đang được tư vấn trong cuộc trò chuyện hiện tại.'),
                $this->quickReply('Hồ sơ cần gì?', 'Khách muốn biết hồ sơ và giấy tờ cần chuẩn bị để mua mẫu xe đang tư vấn theo hình thức trả góp.'),
                $this->quickReply('Trả trước bao nhiêu?', 'Khách muốn biết cần trả trước bao nhiêu tiền để mua mẫu xe đang quan tâm theo hình thức trả góp.'),
                $this->quickReply('Vay mấy năm được?', 'Khách muốn hỏi thời hạn vay phù hợp khi mua xe trả góp.'),
                $this->quickReply('Gửi số tư vấn', 'Khách muốn để lại số điện thoại để nhân viên Toyota Kiên Giang gọi tư vấn chi tiết.'),
            ];
        }

        if ($this->containsAny($lower, ['lai thu', 'dat lich', 'lich hen', 'showroom'])) {
            return [
                $this->quickReply('Đặt lịch lái thử?', 'Khách muốn đặt lịch lái thử mẫu xe đang được tư vấn trong cuộc trò chuyện hiện tại.'),
                $this->quickReply('Mai còn lịch không?', 'Khách muốn hỏi ngày mai còn lịch lái thử mẫu xe đang quan tâm không.'),
                $this->quickReply('Lái thử cần gì?', 'Khách muốn biết khi đi lái thử cần chuẩn bị giấy tờ gì.'),
                $this->quickReply('Gửi số giữ lịch', 'Khách muốn để lại số điện thoại để nhân viên giữ lịch lái thử.'),
            ];
        }

        if ($this->containsAny($lower, ['gia', 'khuyen mai', 'uu dai', 'lan banh', 'phien ban', 'mau xe'])) {
            return [
                $this->quickReply('Giá lăn bánh xe?', 'Khách muốn hỏi giá lăn bánh cho mẫu xe đang được tư vấn trong cuộc trò chuyện hiện tại.'),
                $this->quickReply('Ưu đãi xe này?', 'Khách muốn hỏi ưu đãi và khuyến mãi hiện tại cho mẫu xe đang được tư vấn.'),
                $this->quickReply('Trả góp xe này?', 'Khách muốn hỏi phương án trả góp cho mẫu xe đang được tư vấn.'),
                $this->quickReply('Còn màu nào không?', 'Khách muốn hỏi mẫu xe đang được tư vấn còn những màu nào tại Toyota Kiên Giang.'),
                $this->quickReply('Gửi số nhận giá', 'Khách muốn để lại số điện thoại để nhận báo giá chi tiết từ Toyota Kiên Giang.'),
            ];
        }

        return [
            $this->quickReply('Tư vấn xe hợp?', 'Khách muốn được tư vấn mẫu Toyota phù hợp với nhu cầu sử dụng và ngân sách.'),
            $this->quickReply('Xin giá lăn bánh?', 'Khách muốn xin giá lăn bánh chi tiết cho mẫu xe đang quan tâm trong cuộc trò chuyện.'),
            $this->quickReply('Xem ưu đãi xe?', 'Khách muốn xem ưu đãi và khuyến mãi hiện tại của mẫu xe đang được tư vấn.'),
            $this->quickReply('Đặt lịch lái thử?', 'Khách muốn đặt lịch lái thử mẫu xe đang quan tâm.'),
        ];
    }

    private function legacyDefaultQuickRepliesWithBrokenEncoding(string $content): array
    {
        $lower = mb_strtolower($content);

        if ($this->containsAny($lower, ['tra gop', 'trả góp', 'lai suat', 'lãi suất', 'vay'])) {
            return [
                $this->quickReply('Tính góp xe này?', 'Khách muốn tính phương án trả góp cho mẫu xe đang được tư vấn trong cuộc trò chuyện hiện tại.'),
                $this->quickReply('Hồ sơ cần gì?', 'Khách muốn biết hồ sơ và giấy tờ cần chuẩn bị để mua mẫu xe đang tư vấn theo hình thức trả góp.'),
                $this->quickReply('Lãi suất hiện tại?', 'Khách muốn hỏi lãi suất trả góp hiện tại cho mẫu xe đang được tư vấn.'),
                $this->quickReply('Trả trước bao nhiêu?', 'Khách muốn biết cần trả trước bao nhiêu tiền để mua mẫu xe đang quan tâm theo hình thức trả góp.'),
                $this->quickReply('Gửi số tư vấn', 'Khách muốn để lại số điện thoại để nhân viên Toyota Kiên Giang gọi tư vấn chi tiết.'),
            ];
        }

        if ($this->containsAny($lower, ['lai thu', 'lái thử', 'dat lich', 'đặt lịch'])) {
            return [
                $this->quickReply('Đặt lịch lái thử?', 'Khách muốn đặt lịch lái thử mẫu xe đang được tư vấn trong cuộc trò chuyện hiện tại.'),
                $this->quickReply('Mai còn lịch không?', 'Khách muốn hỏi ngày mai còn lịch lái thử mẫu xe đang quan tâm không.'),
                $this->quickReply('Lái thử cần gì?', 'Khách muốn biết khi đi lái thử cần chuẩn bị giấy tờ gì.'),
                $this->quickReply('Gửi số giữ lịch', 'Khách muốn để lại số điện thoại để nhân viên giữ lịch lái thử.'),
            ];
        }

        if ($this->containsAny($lower, ['gia', 'giá', 'khuyen mai', 'khuyến mãi', 'uu dai', 'ưu đãi'])) {
            return [
                $this->quickReply('Giá lăn bánh xe?', 'Khách muốn hỏi giá lăn bánh cho mẫu xe đang được tư vấn trong cuộc trò chuyện hiện tại.'),
                $this->quickReply('Ưu đãi xe này?', 'Khách muốn hỏi ưu đãi và khuyến mãi hiện tại cho mẫu xe đang được tư vấn.'),
                $this->quickReply('Trả góp xe này?', 'Khách muốn hỏi phương án trả góp cho mẫu xe đang được tư vấn.'),
                $this->quickReply('Còn màu nào không?', 'Khách muốn hỏi mẫu xe đang được tư vấn còn những màu nào tại Toyota Kiên Giang.'),
                $this->quickReply('Gửi số nhận giá', 'Khách muốn để lại số điện thoại để nhận báo giá chi tiết từ Toyota Kiên Giang.'),
            ];
        }

        return [
            $this->quickReply('Tư vấn xe hợp?', 'Khách muốn được tư vấn mẫu Toyota phù hợp với nhu cầu sử dụng và ngân sách.'),
            $this->quickReply('Xin giá lăn bánh?', 'Khách muốn xin giá lăn bánh chi tiết cho mẫu xe đang quan tâm trong cuộc trò chuyện.'),
            $this->quickReply('Xem ưu đãi xe?', 'Khách muốn xem ưu đãi và khuyến mãi hiện tại của mẫu xe đang được tư vấn.'),
            $this->quickReply('Đặt lịch lái thử?', 'Khách muốn đặt lịch lái thử mẫu xe đang quan tâm.'),
        ];
    }

    private function legacyDefaultQuickReplies(string $content): array
    {
        $lower = mb_strtolower($content);

        if ($this->containsAny($lower, ['tra gop', 'trả góp', 'lai suat', 'lãi suất', 'vay'])) {
            return [
                $this->quickReply('Tính góp xe này', 'Khách muốn tính phương án trả góp cho mẫu xe đang được tư vấn trong cuộc trò chuyện hiện tại.'),
                $this->quickReply('Hồ sơ mua góp?', 'Khách muốn biết hồ sơ và giấy tờ cần chuẩn bị để mua mẫu xe đang tư vấn theo hình thức trả góp.'),
                $this->quickReply('Lãi suất hiện tại?', 'Khách muốn hỏi lãi suất trả góp hiện tại cho mẫu xe đang được tư vấn.'),
                $this->quickReply('Gửi số để tư vấn', 'Khách muốn để lại số điện thoại để nhân viên Toyota Kiên Giang gọi tư vấn chi tiết.'),
            ];
        }

        if ($this->containsAny($lower, ['lai thu', 'lái thử', 'dat lich', 'đặt lịch'])) {
            return [
                $this->quickReply('Đặt lịch lái thử', 'Khách muốn đặt lịch lái thử mẫu xe đang được tư vấn trong cuộc trò chuyện hiện tại.'),
                $this->quickReply('Mai còn lịch không?', 'Khách muốn hỏi ngày mai còn lịch lái thử mẫu xe đang quan tâm không.'),
                $this->quickReply('Lái thử cần gì?', 'Khách muốn biết khi đi lái thử cần chuẩn bị giấy tờ gì.'),
                $this->quickReply('Gửi số giữ lịch', 'Khách muốn để lại số điện thoại để nhân viên giữ lịch lái thử.'),
            ];
        }

        if ($this->containsAny($lower, ['gia', 'giá', 'khuyen mai', 'khuyến mãi', 'uu dai', 'ưu đãi'])) {
            return [
                $this->quickReply('Giá lăn bánh xe', 'Khách muốn hỏi giá lăn bánh cho mẫu xe đang được tư vấn trong cuộc trò chuyện hiện tại.'),
                $this->quickReply('Ưu đãi xe này?', 'Khách muốn hỏi ưu đãi và khuyến mãi hiện tại cho mẫu xe đang được tư vấn.'),
                $this->quickReply('Trả góp xe này?', 'Khách muốn hỏi phương án trả góp cho mẫu xe đang được tư vấn.'),
                $this->quickReply('Gửi số nhận giá', 'Khách muốn để lại số điện thoại để nhận báo giá chi tiết từ Toyota Kiên Giang.'),
            ];
        }

        return [
            $this->quickReply('Tư vấn xe phù hợp', 'Khách muốn được tư vấn mẫu Toyota phù hợp với nhu cầu sử dụng và ngân sách.'),
            $this->quickReply('Xin giá lăn bánh', 'Khách muốn xin giá lăn bánh chi tiết cho mẫu xe đang quan tâm trong cuộc trò chuyện.'),
            $this->quickReply('Xem ưu đãi xe', 'Khách muốn xem ưu đãi và khuyến mãi hiện tại của mẫu xe đang được tư vấn.'),
            $this->quickReply('Đặt lịch lái thử', 'Khách muốn đặt lịch lái thử mẫu xe đang quan tâm.'),
        ];
    }

    private function containsAny(string $haystack, array $needles): bool
    {
        foreach ($needles as $needle) {
            if ($needle !== '' && str_contains($haystack, $needle)) {
                return true;
            }
        }

        return false;
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

    private function phoneShareQuickReply(): array
    {
        return [
            'content_type' => 'user_phone_number',
        ];
    }

    private function quickRepliesAskForPhone(array $items): bool
    {
        foreach ($items as $item) {
            if ($this->isPhoneTextQuickReply($item)) {
                return true;
            }
        }

        return false;
    }

    private function withoutPhoneTextQuickReplies(array $items): array
    {
        return array_values(array_filter($items, fn (array $item): bool => ! $this->isPhoneTextQuickReply($item)));
    }

    private function isPhoneTextQuickReply(array $item): bool
    {
        if (($item['content_type'] ?? '') !== 'text') {
            return false;
        }

        $text = Str::of(trim(($item['title'] ?? '').' '.($item['payload'] ?? '')))
            ->lower()
            ->ascii()
            ->replaceMatches('/[^a-z0-9\s]+/', ' ')
            ->replaceMatches('/\s+/', ' ')
            ->trim()
            ->toString();

        return $this->containsAny($text, [
            'gui so',
            'de lai so',
            'cho so dien thoai',
            'so dien thoai',
            'sdt',
            'goi lai',
            'lien he',
        ]);
    }

    private function shouldAskForPhone(Conversation $conversation, string $content): bool
    {
        $conversation->loadMissing('customer');

        if (filled($conversation->customer?->phone)) {
            return false;
        }

        $normalized = Str::of($content)
            ->lower()
            ->ascii()
            ->replaceMatches('/[^a-z0-9\s]+/', ' ')
            ->replaceMatches('/\s+/', ' ')
            ->trim()
            ->toString();

        return $this->containsAny($normalized, [
            'so dien thoai',
            'sdt',
            'lien he',
            'goi lai',
            'goi dien',
            'ky thuat vien',
            'cuu ho',
            'ho tro ky thuat',
            'de lai so',
            'cho em xin so',
            'gui so',
            'xin so',
            'nhap so',
        ]);
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
        $quickReplyAttachment = collect($message->attachments ?? [])
            ->first(fn (array $attachment): bool => ($attachment['type'] ?? '') === 'quick_reply');
        $quickReplyPayload = trim((string) (
            data_get($quickReplyAttachment, 'payload.text')
            ?: data_get($quickReplyAttachment, 'payload.payload')
            ?: data_get($quickReplyAttachment, 'payload.raw.payload')
            ?: data_get($message->attachments, '0.payload.raw.message.quick_reply.payload', '')
        ));

        if ($quickReplyPayload !== '') {
            $title = trim((string) data_get($quickReplyAttachment, 'payload.title', ''));
            $title = $title !== '' && $title !== $quickReplyPayload ? $title : '';

            return trim(implode("\n", array_filter([
                'Khách vừa bấm quick reply trong Messenger.',
                $title !== '' ? 'Nút khách chọn: '.$title : null,
                'Ý định cần trả lời: '.$quickReplyPayload,
                'Hãy trả lời trực tiếp đúng ý định này, bám theo mẫu xe/chủ đề đang có trong cuộc trò chuyện. Không tự chuyển sang hỏi tỉnh/thành, giá lăn bánh hoặc chủ đề khác nếu khách chưa hỏi.',
            ])));
        }

        $content = trim((string) $message->content);
        $attachments = collect($message->attachments ?? [])
            ->reject(fn (array $attachment): bool => ($attachment['type'] ?? '') === 'metadata')
            ->map(fn (array $attachment): string => trim((string) ($attachment['name'] ?? 'File')).': '.trim((string) ($attachment['url'] ?? $attachment['path'] ?? '')))
            ->filter()
            ->implode("\n");

        $text = trim($content."\n".$attachments) ?: '[Tin nhan khong co noi dung]';
        $context = $this->botpressMessageContext($message);

        if ($context === '') {
            return $text;
        }

        return trim(implode("\n\n", [
            'Tin nhan moi cua khach:',
            $text,
            'Ngu canh gan nhat:',
            $context,
            'Hay tra loi tu nhien, dung truc tiep tin nhan moi. Neu khach chi chao hoi hoac noi chuyen binh thuong thi dap lai ngan gon, vui ve. Chi hoi them thong tin khi can thiet.',
            'Khong lap lai thong tin da tu van trong ngu canh. Khong gui cach tinh, cong thuc, hay danh sach thue phi chi tiet. Neu khach bo sung tinh/thanh thi chi tra loi ket qua moi can thiet va hoi buoc tiep theo.',
        ]));
    }

    private function botpressMessageContext(Message $message): string
    {
        $conversation = $message->conversation;

        if (! $conversation) {
            return '';
        }

        return $conversation->messages()
            ->where('id', '<', $message->id)
            ->latest('created_at')
            ->limit(8)
            ->get()
            ->reverse()
            ->map(function (Message $item): string {
                $role = match ($item->sender_type) {
                    'customer' => 'Khach',
                    'system' => 'Bot',
                    'user' => 'Nhan vien',
                    default => 'He thong',
                };

                $content = trim((string) $item->content);

                if ($content === '') {
                    $content = '[File hoac dinh kem]';
                }

                return $role.': '.mb_substr($content, 0, 240);
            })
            ->implode("\n");
    }

    private function recentBotCallbackExists(Conversation $conversation): bool
    {
        return Message::query()
            ->where('conversation_id', $conversation->id)
            ->where('sender_type', 'system')
            ->where('channel', 'facebook')
            ->where('client_message_id', 'like', 'botpress_callback_%')
            ->where('created_at', '>=', now()->subSeconds(8))
            ->exists();
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

    private function preferCallback(): bool
    {
        return (bool) config('services.botpress.prefer_callback', true);
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
