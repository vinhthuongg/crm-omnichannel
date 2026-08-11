<?php

namespace Modules\Message\Services;

use Illuminate\Support\Facades\DB;
use Modules\Conversation\Models\Conversation;
use Modules\Conversation\Services\ConversationIntentService;
use Modules\Conversation\Services\ConversationService;
use Modules\Customer\Models\CustomerChannel;
use Modules\Message\Models\Message;
use Modules\Message\Repositories\MessageRepository;

class FacebookEchoMessageService
{
    /** Nhận MessageRepository để đọc và lưu dữ liệu; ConversationService để phân công, đổi trạng thái và đồng bộ nhãn hội thoại; ConversationIntentService để phân loại ý định từ nội dung tin nhắn; MessagePostProcessor để cập nhật vector tìm kiếm và tạo gợi ý trả lời. */
    public function __construct(private readonly MessageRepository $repository, private readonly ConversationService $conversations,
        private readonly ConversationIntentService $intents, private readonly MessagePostProcessor $post,
        private readonly ChatbotInterventionService $interventions) {}

    /** Tìm tin outbound tương ứng hoặc tạo tin echo mới rồi đồng bộ trạng thái hội thoại. */
    public function store(array $event): ?Message
    {
        $payload = (array) data_get($event, 'message', []);
        $externalId = (string) data_get($payload, 'mid', '');
        if ($externalId !== '' && ($existing = Message::query()->where('channel', 'facebook')->where('external_message_id', $externalId)
            ->with(['conversation.customer.channels', 'sender'])->first())) {
            return $existing;
        }
        $pageId = (string) data_get($event, 'sender.id');
        $customerExternalId = (string) data_get($event, 'recipient.id');
        if ($pageId === '' || $customerExternalId === '') {
            return null;
        }
        $customerId = CustomerChannel::query()->where('channel', 'facebook')->where('external_id', $customerExternalId)->value('customer_id');
        if (! $customerId) {
            return null;
        }
        $conversation = Conversation::query()->where('customer_id', $customerId)->where('facebook_page_id', $pageId)
            ->latest('last_message_at')->first();
        if (! $conversation) {
            return null;
        }
        $attachments = $this->attachments((array) data_get($payload, 'attachments', []));
        $metadata = ['type' => 'metadata', 'name' => 'facebook_echo', 'payload' => ['is_echo' => true, 'app_id' => data_get($payload, 'app_id'), 'raw' => $event]];
        $stored = DB::transaction(function () use ($conversation, $payload, $externalId, $attachments, $metadata): Message {
            $message = $this->repository->create(['conversation_id' => $conversation->id, 'sender_type' => 'user', 'sender_id' => null,
                'channel' => 'facebook', 'content' => data_get($payload, 'text'), 'message_type' => $attachments ? 'attachment' : 'text',
                'attachments' => [...$attachments, $metadata], 'external_message_id' => $externalId !== '' ? $externalId : null,
                'outbound_status' => 'sent', 'sent_at' => now()]);
            $conversation->forceFill(['last_message_at' => $message->created_at])->save();
            $conversation->markAsRead();

            return $message;
        });
        $this->interventions->cancelPendingFor($conversation);
        $this->conversations->recordFacebookEcho($conversation, $stored);
        $this->intents->classifyMessage($stored);
        $this->post->broadcast($stored);
        $this->post->refreshVector($conversation->customer);

        return $stored;
    }

    /** Chuyển attachment Facebook echo thành cấu trúc URL, loại và metadata lưu trong message. */
    private function attachments(array $attachments): array
    {
        return collect($attachments)->map(function (array $item): array {
            $type = (string) data_get($item, 'type', 'file');
            $url = (string) (data_get($item, 'payload.url') ?: data_get($item, 'url', ''));

            return ['name' => ($url ? basename((string) parse_url($url, PHP_URL_PATH)) : ucfirst($type)) ?: ucfirst($type),
                'url' => $url, 'type' => $type, 'mime_type' => (string) data_get($item, 'mime_type', ''), 'payload' => data_get($item, 'payload', [])];
        })->filter(fn (array $item): bool => $item['url'] !== '')->unique('url')->values()->all();
    }
}
