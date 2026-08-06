<?php

namespace App\Actions\Web;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Modules\Conversation\Models\Conversation;
use Modules\Conversation\Services\ConversationService;
use Modules\Conversation\Support\ConversationStatus;
use Modules\Message\Jobs\SendOutboundMessageJob;
use Modules\Message\Models\Message;
use Modules\Message\Services\LocalOutboundQueueKicker;
use Modules\Message\Services\MessagePostProcessor;
use Modules\Message\Services\MessengerAttachmentResolver;

class SendMessengerMessageAction
{
    /** Nhận MessengerAttachmentResolver để chuẩn hóa tệp đính kèm trước khi gửi; ConversationService để phân công, đổi trạng thái và đồng bộ nhãn hội thoại; MessagePostProcessor để cập nhật vector tìm kiếm và tạo gợi ý trả lời; LocalOutboundQueueKicker để kích hoạt xử lý tin outbound đang chờ. */
    public function __construct(private readonly MessengerAttachmentResolver $attachments, private readonly ConversationService $conversations,
        private readonly MessagePostProcessor $post, private readonly LocalOutboundQueueKicker $queue) {}

    /** Chuẩn hóa attachment rồi gửi tin nhắn hoặc lời thì thầm vào hội thoại Messenger. */
    public function execute(Conversation $conversation, User $user, array $data): Message
    {
        $attachments = $this->attachments->resolve($data['uploaded_attachments'] ?? [], $data['attachments'] ?? []);
        $content = (string) ($data['content'] ?? '');
        $clientId = (string) ($data['client_message_id'] ?? '');
        $whisper = ($data['message_mode'] ?? 'message') === 'whisper';
        $channel = $whisper ? 'internal' : $data['channel'];
        if ($clientId !== '' && ($existing = Message::query()->where('channel', $channel)->where('client_message_id', $clientId)->with('sender')->first())) return $existing;

        $message = DB::transaction(function () use ($conversation, $user, $content, $attachments, $clientId, $whisper, $channel): Message {
            $message = Message::query()->create(['conversation_id' => $conversation->id, 'sender_type' => 'user', 'sender_id' => $user->id,
                'channel' => $channel, 'content' => $content !== '' ? $content : null,
                'message_type' => $whisper ? 'whisper' : ($attachments ? 'attachment' : 'text'), 'attachments' => $attachments,
                'external_message_id' => null, 'client_message_id' => $clientId !== '' ? $clientId : null,
                'outbound_status' => $whisper ? null : 'queued']);
            $this->conversations->recordOutboundMessage($conversation, $message, $whisper);
            if (! $whisper) $conversation->forceFill(['status' => ConversationStatus::WAITING_CUSTOMER])->save();
            return $message;
        });
        $this->post->broadcast($message);
        if ($whisper) return $message;
        $message->loadMissing('conversation.customer');
        $this->post->refreshVector($message->conversation?->customer);
        SendOutboundMessageJob::dispatch($message->id);
        $this->queue->kick();
        return $message;
    }
}
