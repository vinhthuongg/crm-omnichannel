<?php

namespace Modules\Message\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Modules\Conversation\Models\Conversation;
use Modules\Customer\Models\Customer;
use Modules\Customer\Models\CustomerChannel;
use Modules\Message\DTO\InboundMessageData;
use Modules\Message\Events\NewMessageEvent;
use Modules\Message\Models\Message;
use Modules\Message\Repositories\MessageRepository;

class MessageService
{
    public function __construct(
        private readonly MessageRepository $repository,
        private readonly OutboundMessageService $outbound,
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

        $message = DB::transaction(function () use ($data): Message {
            $channel = CustomerChannel::query()->where('channel', $data->channel)->where('external_id', $data->externalCustomerId)->first();
            $customer = $channel?->customer ?? Customer::query()->create(['name' => $data->customerName, 'avatar' => $data->customerAvatar]);
            $this->refreshCustomerProfile($customer, $data);
            $customer->channels()->updateOrCreate(['channel' => $data->channel, 'external_id' => $data->externalCustomerId], ['metadata' => $data->metadata]);
            $conversation = Conversation::query()->firstOrCreate(['customer_id' => $customer->id, 'status' => 'open'], ['last_message_at' => now()]);
            $message = $this->repository->create(['conversation_id' => $conversation->id, 'sender_type' => 'customer', 'sender_id' => $customer->id, 'channel' => $data->channel, 'content' => $data->content, 'message_type' => $data->messageType, 'attachments' => $data->attachments, 'external_message_id' => $data->externalMessageId]);
            $conversation->forceFill(['last_message_at' => $message->created_at])->save();
            return $message;
        });

        $this->broadcastNewMessage($message);

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

        if ($updates) {
            $customer->forceFill($updates)->save();
        }
    }

    public function sendFromUser(Conversation $conversation, User $user, array $data): Message
    {
        $externalMessageId = $this->outbound->sendText($conversation, $data['channel'], (string) ($data['content'] ?? ''));

        $message = DB::transaction(function () use ($conversation, $user, $data, $externalMessageId): Message {
            $message = $this->repository->create(['conversation_id' => $conversation->id, 'sender_type' => 'user', 'sender_id' => $user->id, 'channel' => $data['channel'], 'content' => $data['content'] ?? null, 'message_type' => $data['message_type'] ?? 'text', 'attachments' => $data['attachments'] ?? null, 'external_message_id' => $externalMessageId]);
            $conversation->forceFill(['last_message_at' => $message->created_at, 'status' => 'open'])->save();
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
}
