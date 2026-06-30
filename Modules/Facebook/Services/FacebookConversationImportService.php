<?php

namespace Modules\Facebook\Services;

use Carbon\Carbon;
use Illuminate\Http\Client\Factory as Http;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\Conversation\Models\Conversation;
use Modules\Conversation\Support\ConversationStatus;
use Modules\Customer\Models\Customer;
use Modules\Customer\Models\CustomerChannel;
use Modules\Facebook\Models\FacebookPage;
use Modules\Facebook\Repositories\FacebookPageRepository;
use Modules\Conversation\Services\WorkShiftService;
use Modules\Message\Models\Message;

class FacebookConversationImportService
{
    private const MAX_MESSAGE_PAGES_PER_CONVERSATION = 10;

    public function __construct(
        private readonly Http $http,
        private readonly FacebookTokenValidationService $tokens,
        private readonly FacebookPageRepository $pages,
        private readonly WorkShiftService $shifts,
    ) {
    }

    public function importPage(FacebookPage $page, int $conversationLimit = 100): array
    {
        $this->tokens->ensurePageBelongsToMessengerApp($page->messenger_app_id);
        $debugToken = $this->tokens->validatePageToken($page->page_access_token);
        $this->pages->markValid($page, $debugToken);

        $stats = ['conversations' => 0, 'messages' => 0];
        $url = $this->graphUrl("/{$page->page_id}/conversations");
        $params = [
            'fields' => 'id,participants,updated_time,messages.limit(100){id,message,from,to,created_time,attachments}',
            'limit' => min(max($conversationLimit, 1), 100),
            'access_token' => $page->page_access_token,
        ];

        $seenUrls = [];

        do {
            if (isset($seenUrls[$url])) {
                Log::warning('Facebook conversation sync stopped because paging URL repeated', [
                    'url' => $this->safeUrl($url),
                    'page_id' => $page->page_id,
                ]);

                break;
            }

            $seenUrls[$url] = true;
            $payload = $this->graphGet($url, $params, $page->page_access_token);

            foreach ((array) Arr::get($payload, 'data', []) as $conversation) {
                $stats['conversations']++;
                $stats['messages'] += $this->importConversation($page, $conversation);
            }

            $url = Arr::get($payload, 'paging.next');
            $params = [];
        } while ($url);

        return $stats;
    }

    private function importConversation(FacebookPage $page, array $remoteConversation): int
    {
        $imported = 0;
        $embeddedMessages = (array) Arr::get($remoteConversation, 'messages.data', []);
        $remoteConversationId = (string) Arr::get($remoteConversation, 'id');

        foreach ($embeddedMessages as $message) {
            if ($this->storeMessage($page, $message, $remoteConversation)) {
                $imported++;
            }
        }

        $url = Arr::get($remoteConversation, 'messages.paging.next');

        if (! $url && $embeddedMessages === []) {
            $url = $this->graphUrl('/'.Arr::get($remoteConversation, 'id').'/messages');
        }

        if (! $url) {
            return $imported;
        }

        $params = [
            'fields' => 'id,message,from,to,created_time,attachments',
            'limit' => 100,
            'access_token' => $page->page_access_token,
        ];

        $seenUrls = [];
        $pageCount = 0;

        do {
            if (isset($seenUrls[$url])) {
                Log::warning('Facebook message sync stopped because paging URL repeated', [
                    'url' => $this->safeUrl($url),
                    'facebook_page_id' => $page->page_id,
                    'conversation_id' => Arr::get($remoteConversation, 'id'),
                ]);

                break;
            }

            if ($pageCount >= self::MAX_MESSAGE_PAGES_PER_CONVERSATION) {
                Log::info('Facebook message sync stopped at per-conversation page limit', [
                    'limit' => self::MAX_MESSAGE_PAGES_PER_CONVERSATION,
                    'facebook_page_id' => $page->page_id,
                    'conversation_id' => Arr::get($remoteConversation, 'id'),
                ]);

                break;
            }

            $seenUrls[$url] = true;
            $pageCount++;
            $payload = $this->graphGet($url, $params, $page->page_access_token);

            foreach ((array) Arr::get($payload, 'data', []) as $message) {
                if ($this->storeMessage($page, $message, $remoteConversation)) {
                    $imported++;
                }
            }

            $url = Arr::get($payload, 'paging.next');
            $params = [];
        } while ($url);

        return $imported;
    }

    private function storeMessage(FacebookPage $page, array $remoteMessage, array $remoteConversation): bool
    {
        $messageId = (string) Arr::get($remoteMessage, 'id');
        $remoteConversationId = (string) Arr::get($remoteConversation, 'id');

        if ($messageId === '') {
            return false;
        }

        return DB::transaction(function () use ($page, $remoteMessage, $messageId, $remoteConversationId, $remoteConversation): bool {
            $fromId = (string) Arr::get($remoteMessage, 'from.id');
            $fromName = (string) Arr::get($remoteMessage, 'from.name', 'Customer');
            $participant = $this->customerParticipant($page, $remoteMessage, $remoteConversation);
            $customerExternalId = (string) Arr::get($participant, 'id', $fromId);
            $customerName = (string) Arr::get($participant, 'name', $fromName ?: $customerExternalId);

            if ($customerExternalId === '' || $customerExternalId === $page->page_id) {
                return false;
            }

            $channel = CustomerChannel::query()
                ->where('channel', 'facebook')
                ->where('external_id', $customerExternalId)
                ->first();

            $customer = $channel?->customer ?? Customer::query()->create(['name' => $customerName]);
            $content = Arr::get($remoteMessage, 'message');

            if (blank($customer->phone) && is_string($content) && $phone = $this->extractPhoneNumber($content)) {
                $customer->forceFill(['phone' => $phone])->save();
            }

            $customer->channels()->updateOrCreate(
                ['channel' => 'facebook', 'external_id' => $customerExternalId],
                ['metadata' => ['facebook_page_id' => $page->page_id]],
            );

            $currentShift = $this->shifts->currentShift();
            $conversation = $remoteConversationId !== ''
                ? Conversation::query()
                    ->where('external_conversation_id', $remoteConversationId)
                    ->first()
                : null;

            $conversation ??= Conversation::query()
                ->where('customer_id', $customer->id)
                ->where('facebook_page_id', $page->page_id)
                ->whereIn('status', ConversationStatus::ACTIVE)
                ->latest('last_message_at')
                ->first();

            if (! $conversation) {
                $conversation = Conversation::query()->create([
                    'customer_id' => $customer->id,
                    'status' => ConversationStatus::WAITING,
                    'facebook_page_id' => $page->page_id,
                    'external_conversation_id' => $remoteConversationId,
                    'last_message_at' => $this->createdAt($remoteMessage),
                    'work_shift_id' => $currentShift?->id,
                    'owner_shift_id' => $currentShift?->id,
                    'queue_shift_id' => $currentShift?->id,
                ]);
            }

            if ($remoteConversationId && ! $conversation->external_conversation_id) {
                $conversation->forceFill([
                    'external_conversation_id' => $remoteConversationId,
                ])->save();
            }

            $existingMessage = Message::withTrashed()
                ->where('channel', 'facebook')
                ->where('external_message_id', $messageId)
                ->first();
            $isFromPage = $fromId === $page->page_id;
            $isCrmOutbound = $existingMessage?->sender_type === 'user' && filled($existingMessage?->client_message_id);
            $senderType = $isFromPage ? ($isCrmOutbound ? 'user' : 'system') : 'customer';
            $senderId = match ($senderType) {
                'user' => $existingMessage?->sender_id ?: $page->user_id,
                'customer' => $customer->id,
                default => null,
            };
            $attachments = $this->attachments($remoteMessage);

            $message = Message::withTrashed()->updateOrCreate(
                ['channel' => 'facebook', 'external_message_id' => $messageId],
                [
                    'conversation_id' => $conversation->id,
                    'sender_type' => $senderType,
                    'sender_id' => $senderId,
                    'content' => $content,
                    'message_type' => $attachments ? 'attachment' : 'text',
                    'attachments' => $attachments,
                    'outbound_status' => $isFromPage ? 'sent' : null,
                    'sent_at' => $isFromPage ? $this->createdAt($remoteMessage) : null,
                    'created_at' => $this->createdAt($remoteMessage),
                    'updated_at' => now(),
                    'deleted_at' => null,
                ],
            );

            if (! $conversation->last_message_at || $message->created_at->gt($conversation->last_message_at)) {
                $conversation->forceFill(['last_message_at' => $message->created_at])->save();
            }

            return true;
        });
    }

    private function customerParticipant(FacebookPage $page, array $message, array $remoteConversation = []): array
    {
        foreach ((array) Arr::get($remoteConversation, 'participants.data', []) as $participant) {
            if ((string) Arr::get($participant, 'id') !== $page->page_id) {
                return $participant;
            }
        }

        foreach ((array) Arr::get($message, 'to.data', []) as $participant) {
            if ((string) Arr::get($participant, 'id') !== $page->page_id) {
                return $participant;
            }
        }

        return (string) Arr::get($message, 'from.id') !== $page->page_id
            ? (array) Arr::get($message, 'from', [])
            : [];
    }

    private function attachments(array $message): array
    {
        return collect((array) Arr::get($message, 'attachments.data', []))
            ->map(function (array $attachment): array {
                $url = (string) (
                    Arr::get($attachment, 'image_data.url')
                    ?: Arr::get($attachment, 'video_data.url')
                    ?: Arr::get($attachment, 'file_url')
                );
                $type = match (true) {
                    Arr::has($attachment, 'image_data.url') => 'image',
                    Arr::has($attachment, 'video_data.url') => 'video',
                    str_starts_with((string) Arr::get($attachment, 'mime_type', ''), 'image/') => 'image',
                    str_starts_with((string) Arr::get($attachment, 'mime_type', ''), 'video/') => 'video',
                    str_starts_with((string) Arr::get($attachment, 'mime_type', ''), 'audio/') => 'audio',
                    default => (string) Arr::get($attachment, 'type', 'file'),
                };

                return [
                    'name' => (string) Arr::get($attachment, 'name', basename((string) parse_url($url, PHP_URL_PATH))),
                    'url' => $url,
                    'type' => $type,
                    'mime_type' => (string) Arr::get($attachment, 'mime_type', ''),
                    'payload' => $attachment,
                ];
            })
            ->filter(fn (array $attachment): bool => $attachment['url'] !== '')
            ->unique('url')
            ->values()
            ->all();
    }

    private function extractPhoneNumber(string $content): ?string
    {
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

    private function createdAt(array $message): Carbon
    {
        return Carbon::parse((string) Arr::get($message, 'created_time', now()->toISOString()));
    }

    private function graphGet(string $url, array $params, ?string $accessToken = null): array
    {
        if ($accessToken) {
            $params['access_token'] = $accessToken;
        }

        Log::debug('Facebook graph sync request', [
            'url' => $this->safeUrl($url),
            'has_access_token' => array_key_exists('access_token', $params) && (string) $params['access_token'] !== '',
            'fields' => $params['fields'] ?? null,
            'limit' => $params['limit'] ?? null,
        ]);

        $response = $this->http
            ->connectTimeout(5)
            ->timeout(15)
            ->get($url, $params);
        $response->throw();

        return $response->json();
    }

    private function safeUrl(string $url): string
    {
        return preg_replace('/([?&]access_token=)[^&]+/', '$1[redacted]', $url) ?: $url;
    }

    private function graphUrl(string $path): string
    {
        return 'https://graph.facebook.com/'.$this->graphVersion().$path;
    }

    private function graphVersion(): string
    {
        return (string) config('services.facebook.graph_version', 'v25.0');
    }
}
