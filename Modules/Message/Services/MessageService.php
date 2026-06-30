<?php

namespace Modules\Message\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\Conversation\Models\Conversation;
use Modules\Conversation\Models\Tag;
use Modules\Conversation\Services\ConversationService;
use Modules\Customer\Models\Customer;
use Modules\Customer\Models\CustomerChannel;
use Modules\Conversation\Support\ConversationStatus;
use Modules\Message\DTO\InboundMessageData;
use Modules\Message\Events\NewMessageEvent;
use Modules\Message\Models\Message;
use Modules\Message\Repositories\MessageRepository;
use Modules\Conversation\Services\WorkShiftService;
use Modules\Search\Services\VectorSearchService;

class MessageService
{
    public function __construct(
        private readonly MessageRepository $repository,
        private readonly OutboundMessageService $outbound,
        private readonly WorkShiftService $shifts,
        private readonly ConversationService $conversations,
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
            $currentShift = $this->shifts->currentShift();
            $conversation = Conversation::query()
                ->where('customer_id', $customer->id)
                ->where('facebook_page_id', $facebookPageId !== '' ? $facebookPageId : null)
                ->whereIn('status', ConversationStatus::ACTIVE)
                ->latest('last_message_at')
                ->first();

            if (! $conversation) {
                $conversation = Conversation::query()->create([
                    'customer_id' => $customer->id,
                    'facebook_page_id' => $facebookPageId !== '' ? $facebookPageId : null,
                    'status' => ConversationStatus::WAITING,
                    'last_message_at' => now(),
                    'work_shift_id' => $currentShift?->id,
                    'owner_shift_id' => $currentShift?->id,
                    'queue_shift_id' => $currentShift?->id,
                ]);
            }
            $message = $this->repository->create(['conversation_id' => $conversation->id, 'sender_type' => 'customer', 'sender_id' => $customer->id, 'channel' => $data->channel, 'content' => $data->content, 'message_type' => $data->messageType, 'attachments' => $data->attachments, 'external_message_id' => $data->externalMessageId]);
            $conversation->forceFill(['last_message_at' => $message->created_at])->save();
            $conversation->incrementUnreadMessages();
            $this->markConversationAsWaitingForConsulting($conversation);

            return [$message, $conversation, $customer];
        });

        $this->broadcastNewMessage($message);
        $this->queueCustomerVectorRefresh($customer);

        return $message;
    }

    public function storeFacebookEcho(array $event): ?Message
    {
        $message = (array) data_get($event, 'message', []);
        $externalMessageId = (string) data_get($message, 'mid', '');

        if ($externalMessageId !== '') {
            $existing = Message::query()
                ->where('channel', 'facebook')
                ->where('external_message_id', $externalMessageId)
                ->with(['conversation.customer.channels', 'sender'])
                ->first();

            if ($existing) {
                return $existing;
            }
        }

        $pageId = (string) data_get($event, 'sender.id');
        $customerExternalId = (string) data_get($event, 'recipient.id');

        if ($pageId === '' || $customerExternalId === '') {
            return null;
        }

        $customer = CustomerChannel::query()
            ->where('channel', 'facebook')
            ->where('external_id', $customerExternalId)
            ->value('customer_id');

        if (! $customer) {
            return null;
        }

        $conversation = Conversation::query()
            ->where('customer_id', $customer)
            ->where('facebook_page_id', $pageId)
            ->whereIn('status', ConversationStatus::ACTIVE)
            ->latest('last_message_at')
            ->first();

        if (! $conversation) {
            return null;
        }

        $attachments = $this->normalizeFacebookEchoAttachments((array) data_get($message, 'attachments', []));
        $metadataAttachment = [
            'type' => 'metadata',
            'name' => 'facebook_echo',
            'payload' => [
                'is_echo' => true,
                'app_id' => data_get($message, 'app_id'),
                'raw' => $event,
            ],
        ];

        $stored = DB::transaction(function () use ($conversation, $message, $externalMessageId, $attachments, $metadataAttachment): Message {
            $stored = $this->repository->create([
                'conversation_id' => $conversation->id,
                'sender_type' => 'system',
                'sender_id' => null,
                'channel' => 'facebook',
                'content' => data_get($message, 'text'),
                'message_type' => $attachments ? 'attachment' : 'text',
                'attachments' => [...$attachments, $metadataAttachment],
                'external_message_id' => $externalMessageId !== '' ? $externalMessageId : null,
                'outbound_status' => 'sent',
                'sent_at' => now(),
            ]);

            $conversation->forceFill(['last_message_at' => $stored->created_at])->save();

            return $stored;
        });

        $this->broadcastNewMessage($stored);
        $this->queueCustomerVectorRefresh($conversation->customer);

        return $stored;
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

    private function normalizeFacebookEchoAttachments(array $attachments): array
    {
        return collect($attachments)
            ->map(function (array $attachment): array {
                $type = (string) data_get($attachment, 'type', 'file');
                $url = (string) (data_get($attachment, 'payload.url') ?: data_get($attachment, 'url', ''));
                $name = $url ? basename((string) parse_url($url, PHP_URL_PATH)) : ucfirst($type);

                return [
                    'name' => $name ?: ucfirst($type),
                    'url' => $url,
                    'type' => $type,
                    'mime_type' => (string) data_get($attachment, 'mime_type', ''),
                    'payload' => data_get($attachment, 'payload', []),
                ];
            })
            ->filter(fn (array $attachment): bool => $attachment['url'] !== '')
            ->unique('url')
            ->values()
            ->all();
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
            $this->conversations->recordOutboundMessage($conversation, $message);
            $this->markConversationAsConsulting($conversation);

            return $message;
        });

        $this->broadcastNewMessage($message);
        $this->queueCustomerVectorRefresh($conversation->customer);

        return $message;
    }

    private function broadcastNewMessage(Message $message): void
    {
        try {
            event(new NewMessageEvent($message));
        } catch (\Throwable) {
        }
    }

    private function queueCustomerVectorRefresh(?Customer $customer): void
    {
        if (! $customer || ! config('search.vector.enabled', true)) {
            return;
        }

        app()->terminating(function () use ($customer): void {
            try {
                app(VectorSearchService::class)->indexCustomer($customer->fresh() ?: $customer);
            } catch (\Throwable $exception) {
                Log::warning('Customer vector index refresh failed', [
                    'customer_id' => $customer->id,
                    'error' => $exception->getMessage(),
                ]);
            }
        });
    }

    private function markConversationAsWaitingForConsulting(Conversation $conversation): void
    {
        $this->syncConversationStatusTag(
            $conversation,
            Tag::DEFAULT_WAITING,
            Tag::DEFAULTS[Tag::DEFAULT_WAITING],
        );
    }

    private function markConversationAsConsulting(Conversation $conversation): void
    {
        $this->syncConversationStatusTag(
            $conversation,
            Tag::DEFAULT_CONSULTING,
            Tag::DEFAULTS[Tag::DEFAULT_CONSULTING],
        );
    }

    private function syncConversationStatusTag(Conversation $conversation, string $name, string $color): void
    {
        Tag::ensureDefaults();

        $tag = Tag::query()->firstOrCreate(
            ['name' => $name],
            ['color' => $color, 'is_default' => array_key_exists($name, Tag::DEFAULTS)],
        );

        $conversation->tags()->sync([$tag->id]);
        $conversation->load('tags');
    }
}
