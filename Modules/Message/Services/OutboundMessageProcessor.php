<?php

namespace Modules\Message\Services;

use Illuminate\Support\Facades\Log;
use Modules\Message\Events\MessageUpdatedEvent;
use Modules\Message\Models\Message;

class OutboundMessageProcessor
{
    /** Nhận OutboundMessageService để ghi nhận và gửi tin nhắn trong hội thoại; OutboundErrorFormatter để chuẩn hóa lỗi gửi tin trước khi lưu và ghi log. */
    public function __construct(private readonly OutboundMessageService $outbound, private readonly OutboundErrorFormatter $errors) {}

    /** Nạp message queued, gửi qua đúng kênh, rồi cập nhật sent/failed và external ID. */
    public function process(int $messageId, int $attempt, int $maxAttempts): void
    {
        $message = Message::query()->with('conversation.customer.channels')->find($messageId);
        if (! $message) { Log::warning('Outbound job skipped because message was not found', ['message_id' => $messageId]); return; }
        if (! $message->conversation) { $this->logSkip($message, 'conversation was not found'); return; }
        if ($message->recalled_at || $message->trashed()) { $this->logSkip($message, 'message is no longer sendable'); return; }
        $retrying = $message->outbound_status === 'sending' && $attempt > 1;
        if ($message->outbound_status !== 'queued' && ! $retrying) { $this->logSkip($message, 'message is not queued'); return; }

        try {
            Log::info('Outbound job sending message', ['message_id' => $message->id, 'conversation_id' => $message->conversation_id,
                'channel' => $message->channel, 'sender_type' => $message->sender_type,
                'attachments_count' => count($message->attachments ?? []), 'content_preview' => mb_substr((string) $message->content, 0, 240)]);
            $this->update($message, ['outbound_status' => 'sending', 'outbound_error' => null]);
            $message->refresh();
            if ($message->recalled_at || $message->trashed()) {
                $this->update($message, ['outbound_status' => 'cancelled', 'outbound_error' => 'Tin nhan da duoc thu hoi truoc khi gui sang Facebook.']);
                return;
            }
            $externalId = $this->outbound->send($message->conversation, $message->channel, (string) $message->content, $message->attachments ?? []);
            $response = $this->outbound->lastResponse();
            $failed = $response['_failed_attachments'] ?? [];
            $this->update($message, ['external_message_id' => $externalId,
                'attachments' => $this->mergeAttachments($message->attachments ?? [], $response['_sent_attachments'] ?? []),
                'outbound_status' => $failed !== [] ? 'sent_partial' : 'sent',
                'outbound_error' => $failed !== [] ? 'Mot so tep dinh kem khong gui duoc: '.collect($failed)->pluck('error')->filter()->implode(' | ') : null,
                'sent_at' => now()]);
            Log::info('Outbound job marked message as sent', ['message_id' => $message->id, 'conversation_id' => $message->conversation_id,
                'channel' => $message->channel, 'sender_type' => $message->sender_type, 'external_message_id' => $externalId,
                'failed_attachments_count' => count($failed)]);
            if ($message->sender_type === 'system') $this->outbound->stopTyping($message->conversation, $message->channel);
        } catch (\Throwable $e) {
            $error = $this->errors->format($e);
            $this->update($message, ['outbound_status' => $attempt >= $maxAttempts ? 'failed' : 'queued', 'outbound_error' => $error]);
            Log::warning('Queued outbound message failed', ['message_id' => $message->id, 'conversation_id' => $message->conversation_id,
                'channel' => $message->channel, 'attempt' => $attempt, 'max_tries' => $maxAttempts, 'error' => $error]);
            throw $e;
        }
    }

    /** Lưu trạng thái outbound, lỗi, thời điểm gửi và phát MessageUpdatedEvent. */
    private function update(Message $message, array $attributes): void
    {
        $message->forceFill($attributes)->save();
        event(new MessageUpdatedEvent($message));
    }

    /** Chuẩn hóa hoặc ghi nhận kết quả xử lý outbound tại bước logSkip. */
    private function logSkip(Message $message, string $reason): void
    {
        Log::info('Outbound job skipped because '.$reason, ['message_id' => $message->id, 'conversation_id' => $message->conversation_id,
            'channel' => $message->channel, 'sender_type' => $message->sender_type, 'outbound_status' => $message->outbound_status]);
    }

    /** Chuẩn hóa hoặc ghi nhận kết quả xử lý outbound tại bước mergeAttachments. */
    private function mergeAttachments(array $attachments, array $sent): array
    {
        foreach ($sent as $index => $updates) if (isset($attachments[$index]) && is_array($attachments[$index]))
            $attachments[$index] = array_merge($attachments[$index], $updates);
        return $attachments;
    }
}
