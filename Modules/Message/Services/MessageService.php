<?php

namespace Modules\Message\Services;

use App\Models\User;
use App\Support\InitialMessageTemplate;
use Illuminate\Support\Facades\DB;
use Modules\Conversation\Models\Conversation;
use Modules\Conversation\Models\Tag;
use Modules\Customer\Models\Customer;
use Modules\Customer\Models\CustomerChannel;
use Modules\Customer\Models\CustomerTag;
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
            $autoReply = $this->createAutomationReply($conversation, $customer, $data)
                ?? $this->createInitialAutoReply($conversation, $data->channel);

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

    private function createInitialAutoReply(Conversation $conversation, string $channel): ?Message
    {
        $content = InitialMessageTemplate::serviceMenuFor($conversation);

        if ($content === '') {
            return null;
        }

        $clientMessageId = 'auto-service-menu-'.$conversation->id;
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

    private function createAutomationReply(Conversation $conversation, Customer $customer, InboundMessageData $data): ?Message
    {
        $payload = $this->quickReplyPayload($data);
        $flow = $payload !== '' ? $this->serviceFlow($payload) : null;
        $state = (array) ($conversation->automation_state ?? []);
        $content = trim((string) $data->content);

        if ($flow) {
            $conversation->forceFill([
                'automation_state' => [
                    'topic' => $payload,
                    'label' => $flow['label'],
                    'step' => 'awaiting_detail',
                    'started_at' => now()->toISOString(),
                ],
            ])->save();

            $this->tagCustomer($customer, $flow['label'], '#2563eb');

            return $this->createQueuedAutoReply(
                $conversation,
                $data->channel,
                $flow['question'],
                'auto-flow-'.$conversation->id.'-'.$payload.'-question',
            );
        }

        if (($state['step'] ?? '') === 'awaiting_detail' && $content !== '') {
            $conversation->forceFill([
                'automation_state' => [
                    ...$state,
                    'detail' => $content,
                    'step' => 'awaiting_phone',
                    'detail_received_at' => now()->toISOString(),
                ],
            ])->save();

            return $this->createQueuedAutoReply(
                $conversation,
                $data->channel,
                'Dạ em đã ghi nhận nhu cầu của Anh/Chị. Anh/Chị cho em xin số điện thoại, bên em sẽ gọi ngay để tư vấn và gửi thông tin chính xác ạ.',
                'auto-flow-'.$conversation->id.'-ask-phone',
            );
        }

        if (filled($customer->phone)) {
            $this->tagCustomer($customer, 'Da co so dien thoai', '#16a34a');

            if (filled($state['label'] ?? null)) {
                $this->tagCustomer($customer, (string) $state['label'], '#2563eb');
            }

            if (($state['step'] ?? '') === 'awaiting_phone') {
                $conversation->forceFill([
                    'automation_state' => [
                        ...$state,
                        'step' => 'completed',
                        'phone' => $customer->phone,
                        'completed_at' => now()->toISOString(),
                    ],
                ])->save();

                return $this->createQueuedAutoReply(
                    $conversation,
                    $data->channel,
                    'Toyota Kiên Giang đã nhận số điện thoại của Anh/Chị. Bên em sẽ gọi lại ngay để hỗ trợ ạ.',
                    'auto-flow-'.$conversation->id.'-completed',
                );
            }
        }

        return null;
    }

    private function quickReplyPayload(InboundMessageData $data): string
    {
        return (string) data_get($data->metadata, 'raw.message.quick_reply.payload', '');
    }

    private function serviceFlow(string $payload): ?array
    {
        return [
            'PRICE_BY_AREA' => [
                'label' => 'Bao gia lan banh',
                'question' => 'Anh/Chị cần em báo giá lăn bánh mẫu xe gì ạ?',
            ],
            'PROMOTIONS' => [
                'label' => 'Uu dai hien hanh',
                'question' => 'Anh/Chị quan tâm mẫu xe nào để em kiểm tra chương trình ưu đãi hiện hành ạ?',
            ],
            'INSTALLMENT_LOAN' => [
                'label' => 'Vay tra gop',
                'question' => 'Anh/Chị muốn tư vấn trả góp mẫu xe nào và dự kiến trả trước khoảng bao nhiêu ạ?',
            ],
            'VEHICLE_AVAILABILITY' => [
                'label' => 'Tinh trang xe',
                'question' => 'Anh/Chị muốn kiểm tra tình trạng xe, màu xe và thời gian giao xe của mẫu nào ạ?',
            ],
            'VERSION_CONSULTING' => [
                'label' => 'Tu van phien ban',
                'question' => 'Anh/Chị đang quan tâm mẫu xe nào và nhu cầu sử dụng chính là gì ạ?',
            ],
        ][$payload] ?? null;
    }

    private function tagCustomer(Customer $customer, string $name, string $color): void
    {
        $tag = CustomerTag::query()->firstOrCreate(
            ['name' => $name],
            ['color' => $color],
        );

        $customer->tags()->syncWithoutDetaching([$tag->id]);
    }

    private function createQueuedAutoReply(Conversation $conversation, string $channel, string $content, string $clientMessageId, array $attachments = []): ?Message
    {
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
            'attachments' => $attachments,
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
