<?php

namespace Modules\Notification\Jobs;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable as DispatchableQueueable;
use Modules\Message\Models\Message;
use Modules\Notification\Notifications\NewMessageNotification;
use Modules\Notification\Jobs\SendFcmPushNotificationJob;

class NotifyNewMessageJob implements ShouldQueue
{
    use DispatchableQueueable;
    use Queueable;

    /** Lưu ID tin nhắn mới để job nạp quan hệ và gửi notification cho người nhận. */
    public function __construct(private readonly int $messageId)
    {
    }

    /** Nạp tin mới, xác định người nhận và gửi database/push notification. */
    public function handle(): void
    {
        $message = Message::query()->with(['conversation.customer', 'sender'])->findOrFail($this->messageId);
        $query = User::permission('conversation.view_all');
        if ($message->conversation->assigned_to) {
            $query->orWhereKey($message->conversation->assigned_to);
        }
        $recipients = $query->get();
        $recipients->each->notify(new NewMessageNotification($message));

        if ($message->sender_type === 'customer') {
            SendFcmPushNotificationJob::dispatch(
                $recipients->pluck('id')->unique()->values()->all(),
                (string) ($message->conversation?->customer?->name ?: 'Tin nhắn mới'),
                $this->pushBody($message),
                [
                    'type' => 'new_message',
                    'message_id' => $message->id,
                    'conversation_id' => $message->conversation_id,
                    'sender_type' => $message->sender_type,
                    'channel' => $message->channel,
                ],
            );
        }
    }

    /** Tạo nội dung ngắn dùng trong push notification tin nhắn mới. */
    private function pushBody(Message $message): string
    {
        $content = trim((string) $message->content);

        if ($content !== '') {
            return mb_strimwidth($content, 0, 140, '...');
        }

        $attachment = collect($message->attachments ?? [])->first();

        return match (strtolower((string) data_get($attachment, 'type', ''))) {
            'image' => '[Hình ảnh]',
            'video' => '[Video]',
            'audio' => '[Audio]',
            default => '[Tệp đính kèm]',
        };
    }
}
