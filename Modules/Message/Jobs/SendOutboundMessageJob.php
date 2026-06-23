<?php

namespace Modules\Message\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Modules\Message\Events\MessageUpdatedEvent;
use Modules\Message\Models\Message;
use Modules\Message\Services\OutboundMessageService;

class SendOutboundMessageJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 2;

    public int $timeout = 120;

    public array $backoff = [5, 30];

    public function __construct(public readonly int $messageId)
    {
        $this->onQueue('outbound');
    }

    public function handle(OutboundMessageService $outbound): void
    {
        $message = Message::query()
            ->with('conversation.customer.channels')
            ->find($this->messageId);

        if (! $message?->conversation) {
            return;
        }

        if ($message->outbound_status !== 'queued') {
            return;
        }

        try {
            $message->forceFill([
                'outbound_status' => 'sending',
                'outbound_error' => null,
            ])->save();
            event(new MessageUpdatedEvent($message));

            $externalMessageId = $outbound->send(
                $message->conversation,
                $message->channel,
                (string) $message->content,
                $message->attachments ?? [],
            );
            $sentAttachments = $outbound->lastResponse()['_sent_attachments'] ?? [];
            $attachments = $message->attachments ?? [];

            $message->forceFill([
                'external_message_id' => $externalMessageId,
                'attachments' => $this->mergeSentAttachments($attachments, $sentAttachments),
                'outbound_status' => 'sent',
                'outbound_error' => null,
                'sent_at' => now(),
            ])->save();
            event(new MessageUpdatedEvent($message));
        } catch (\Throwable $exception) {
            $message->forceFill($this->attempts() >= $this->tries
                ? [
                    'outbound_status' => 'failed',
                    'outbound_error' => $exception->getMessage(),
                ]
                : [
                    'outbound_status' => 'queued',
                    'outbound_error' => $exception->getMessage(),
                ])->save();
            event(new MessageUpdatedEvent($message));

            Log::warning('Queued outbound message failed', [
                'message_id' => $message->id,
                'conversation_id' => $message->conversation_id,
                'channel' => $message->channel,
                'attempt' => $this->attempts(),
                'max_tries' => $this->tries,
                'error' => $exception->getMessage(),
            ]);

            throw $exception;
        }
    }

    private function mergeSentAttachments(array $attachments, array $sentAttachments): array
    {
        foreach ($sentAttachments as $index => $updates) {
            if (isset($attachments[$index]) && is_array($attachments[$index])) {
                $attachments[$index] = array_merge($attachments[$index], $updates);
            }
        }

        return $attachments;
    }
}
