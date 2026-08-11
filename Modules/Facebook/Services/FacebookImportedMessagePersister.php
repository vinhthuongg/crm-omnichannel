<?php

namespace Modules\Facebook\Services;

use Carbon\Carbon;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Modules\Conversation\Models\Conversation;
use Modules\Conversation\Services\WorkShiftService;
use Modules\Conversation\Support\ConversationStatus;
use Modules\Customer\Models\Customer;
use Modules\Customer\Models\CustomerChannel;
use Modules\Facebook\Models\FacebookPage;
use Modules\Message\Models\Message;

class FacebookImportedMessagePersister
{
    /** Nhận WorkShiftService để xác định ca trực và thành viên đang hoạt động; FacebookImportPayloadMapper để chuẩn hóa participant, attachment và thời gian từ Graph API. */
    public function __construct(
        private readonly WorkShiftService $shifts,
        private readonly FacebookImportPayloadMapper $mapper,
    ) {}

    /** Upsert customer/channel/conversation và lưu message Facebook nếu chưa tồn tại. */
    public function store(FacebookPage $page, array $remoteMessage, array $remoteConversation): bool
    {
        $messageId = (string) Arr::get($remoteMessage, 'id');
        $conversationId = (string) Arr::get($remoteConversation, 'id');
        if ($messageId === '') {
            return false;
        }

        return DB::transaction(function () use ($page, $remoteMessage, $remoteConversation, $messageId, $conversationId): bool {
            $fromId = (string) Arr::get($remoteMessage, 'from.id');
            $participant = $this->mapper->participant($page, $remoteMessage, $remoteConversation);
            $externalId = (string) Arr::get($participant, 'id', $fromId);
            $name = (string) Arr::get($participant, 'name', Arr::get($remoteMessage, 'from.name', 'Customer'));
            if ($externalId === '' || $externalId === $page->page_id) {
                return false;
            }

            $channel = CustomerChannel::query()->where('channel', 'facebook')->where('external_id', $externalId)->first();
            $customer = $channel?->customer ?? Customer::query()->create(['name' => $name ?: $externalId]);
            $content = Arr::get($remoteMessage, 'message');
            if (blank($customer->phone) && is_string($content) && $phone = $this->mapper->phone($content)) {
                $customer->forceFill(['phone' => $phone])->save();
            }
            $customer->channels()->updateOrCreate(['channel' => 'facebook', 'external_id' => $externalId], ['metadata' => ['facebook_page_id' => $page->page_id]]);

            $conversation = $conversationId !== '' ? Conversation::query()->where('external_conversation_id', $conversationId)->first() : null;
            $conversation ??= Conversation::query()->where('customer_id', $customer->id)->where('facebook_page_id', $page->page_id)
                ->whereIn('status', ConversationStatus::ACTIVE)->latest('last_message_at')->first();
            if (! $conversation) {
                $shift = $this->shifts->currentShift();
                $conversation = Conversation::query()->create(['customer_id' => $customer->id, 'status' => ConversationStatus::CUSTOMER_WAITING,
                    'facebook_page_id' => $page->page_id, 'external_conversation_id' => $conversationId,
                    'last_message_at' => $this->mapper->createdAt($remoteMessage, $remoteConversation),
                    'work_shift_id' => $shift?->id, 'owner_shift_id' => $shift?->id, 'queue_shift_id' => $shift?->id]);
            } elseif ($conversationId && ! $conversation->external_conversation_id) {
                $conversation->forceFill(['external_conversation_id' => $conversationId])->save();
            }

            $existing = Message::withTrashed()->where('channel', 'facebook')->where('external_message_id', $messageId)->first();
            $fromPage = $fromId === $page->page_id;
            $senderType = $fromPage ? ($existing?->sender_type ?: 'user') : 'customer';
            $attachments = $this->mapper->attachments($remoteMessage);
            if ($fromPage && ! $existing) {
                $attachments[] = ['type' => 'metadata', 'name' => 'facebook_echo', 'payload' => ['is_echo' => true, 'source' => 'meta_business_suite']];
            }
            $createdAt = $this->mapper->createdAt($remoteMessage, $remoteConversation);
            $message = Message::withTrashed()->firstOrNew(['channel' => 'facebook', 'external_message_id' => $messageId]);
            $message->forceFill(['conversation_id' => $conversation->id, 'sender_type' => $senderType,
                'sender_id' => match ($senderType) {
                    'user' => $existing?->sender_id, 'customer' => $customer->id, default => null
                },
                'content' => $content, 'message_type' => $attachments ? 'attachment' : 'text', 'attachments' => $attachments,
                'outbound_status' => $fromPage ? 'sent' : null, 'sent_at' => $fromPage ? $createdAt : null,
                'created_at' => $createdAt, 'updated_at' => now(), 'deleted_at' => null])->saveQuietly();

            return true;
        });
    }

    /** Nhập và đồng bộ dữ liệu hội thoại Facebook tại bước refreshTimestamp. */
    public function refreshTimestamp(string $remoteConversationId): void
    {
        if ($remoteConversationId === '') {
            return;
        }
        $conversation = Conversation::query()->where('external_conversation_id', $remoteConversationId)->first();
        $last = $conversation?->messages()->max('created_at');
        if ($conversation && $last) {
            $conversation->forceFill(['last_message_at' => Carbon::parse($last)])->save();
        }
    }
}
