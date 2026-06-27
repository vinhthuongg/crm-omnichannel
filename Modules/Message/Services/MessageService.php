<?php

namespace Modules\Message\Services;

use App\Models\User;
use App\Support\InitialMessageTemplate;
use Illuminate\Support\Facades\DB;
use Modules\Conversation\Models\Conversation;
use Modules\Conversation\Models\Tag;
use Modules\Customer\Models\Customer;
use Modules\Customer\Models\CustomerChannel;
use Modules\Message\DTO\InboundMessageData;
use Modules\Message\Events\NewMessageEvent;
use Modules\Message\Jobs\SendOutboundMessageJob;
use Modules\Message\Models\Message;
use Modules\Message\Repositories\MessageRepository;
use Modules\Conversation\Services\WorkShiftService;

class MessageService
{
    public function __construct(
        private readonly MessageRepository $repository,
        private readonly OutboundMessageService $outbound,
        private readonly WorkShiftService $shifts,
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

        [$message, $autoReply] = DB::transaction(function () use ($data): array {
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
            $autoReply = $this->createPhoneCaptureAutoReply($conversation, $data->channel);

            return [$message, $autoReply];
        });

        $this->broadcastNewMessage($message);

        if ($autoReply) {
            $this->broadcastNewMessage($autoReply);
            SendOutboundMessageJob::dispatch($autoReply->id);
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

    private function createPhoneCaptureAutoReply(Conversation $conversation, string $channel): ?Message
    {
        $content = InitialMessageTemplate::phoneCaptureFor($conversation);

        if ($content === '') {
            return null;
        }

        $clientMessageId = 'auto-phone-capture-'.$conversation->id;
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
            'sender_id' => $conversation->assigned_to ?: User::role('Admin')->value('id') ?: User::query()->value('id'),
            'channel' => $channel,
            'content' => $content,
            'message_type' => 'text',
            'attachments' => $channel === 'facebook'
                ? [[
                    'type' => 'quick_reply',
                    'quick_replies' => InitialMessageTemplate::messengerQuickReplies(),
                ]]
                : [],
            'client_message_id' => $clientMessageId,
            'outbound_status' => 'queued',
        ]);

        $conversation->forceFill([
            'last_message_at' => $message->created_at,
        ])->save();

        return $message;
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
