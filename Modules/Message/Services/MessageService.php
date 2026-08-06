<?php

namespace Modules\Message\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Modules\Conversation\Models\Conversation;
use Modules\Conversation\Services\ConversationIntentService;
use Modules\Conversation\Services\ConversationService;
use Modules\Conversation\Support\ConversationStatus;
use Modules\Message\DTO\InboundMessageData;
use Modules\Message\Jobs\SendOutboundMessageJob;
use Modules\Message\Models\Message;
use Modules\Message\Repositories\MessageRepository;

class MessageService
{
    /** Nhận MessageRepository để đọc và lưu dữ liệu; ConversationService để phân công, đổi trạng thái và đồng bộ nhãn hội thoại; ConversationIntentService để phân loại ý định từ nội dung tin nhắn; InboundMessageContextService để tìm khách hàng, hội thoại và ca trực cho tin đến; FacebookEchoMessageService để ghi nhận và gửi tin nhắn trong hội thoại; MessagePostProcessor để cập nhật vector tìm kiếm và tạo gợi ý trả lời. */
    public function __construct(private readonly MessageRepository $repository, private readonly ConversationService $conversations,
        private readonly ConversationIntentService $intents, private readonly InboundMessageContextService $contexts,
        private readonly FacebookEchoMessageService $echoes, private readonly MessagePostProcessor $post) {}

    /** Tìm hoặc tạo khách hàng/hội thoại, lưu tin đến và chạy hậu xử lý tìm kiếm/gợi ý. */
    public function storeInbound(InboundMessageData $data): Message
    {
        if ($data->externalMessageId && ($existing = Message::query()->where('channel', $data->channel)
            ->where('external_message_id', $data->externalMessageId)->with(['conversation.customer.channels', 'sender'])->first())) return $existing;
        [$message, $conversation, $customer] = DB::transaction(function () use ($data): array {
            [$customer, $conversation] = $this->contexts->resolve($data);
            $message = $this->repository->create(['conversation_id' => $conversation->id, 'sender_type' => 'customer', 'sender_id' => $customer->id,
                'channel' => $data->channel, 'content' => $data->content, 'message_type' => $data->messageType,
                'attachments' => $data->attachments, 'external_message_id' => $data->externalMessageId]);
            $conversation->forceFill(['last_message_at' => $message->created_at, 'status' => ConversationStatus::CUSTOMER_WAITING])->save();
            $conversation->incrementUnreadMessages();
            return [$message, $conversation, $customer];
        });
        $this->intents->classifyMessage($message);
        $this->post->broadcast($message);
        $this->post->refreshVector($customer);
        $this->post->queueSuggestions($conversation, $message);
        return $message;
    }

    /** Lưu hoặc đồng bộ tin echo do Facebook trả về mà không tạo bản ghi trùng. */
    public function storeFacebookEcho(array $event): ?Message
    {
        return $this->echoes->store($event);
    }

    /** Lưu tin do nhân viên gửi, cập nhật hội thoại và xếp hàng gửi sang kênh ngoài. */
    public function sendFromUser(Conversation $conversation, User $user, array $data): Message
    {
        $message = DB::transaction(function () use ($conversation, $user, $data): Message {
            $attachments = array_values((array) ($data['attachments'] ?? []));
            $message = $this->repository->create(['conversation_id' => $conversation->id, 'sender_type' => 'user', 'sender_id' => $user->id,
                'channel' => $data['channel'], 'content' => $data['content'] ?? null,
                'message_type' => $data['message_type'] ?? ($attachments !== [] ? 'attachment' : 'text'), 'attachments' => $attachments,
                'external_message_id' => null, 'outbound_status' => 'queued', 'outbound_error' => null]);
            $this->conversations->recordOutboundMessage($conversation, $message);
            $conversation->forceFill(['status' => ConversationStatus::WAITING_CUSTOMER])->save();
            return $message;
        });
        $this->post->broadcast($message);
        SendOutboundMessageJob::dispatch((int) $message->id);
        $this->post->refreshVector($conversation->customer);
        return $message;
    }
}
