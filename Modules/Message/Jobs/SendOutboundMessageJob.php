<?php

namespace Modules\Message\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Http\Client\RequestException;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Arr;
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

    public int $tries = 1;

    public int $timeout = 120;

    public array $backoff = [];

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

        if ($message->recalled_at || $message->trashed()) {
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

            $message->refresh();

            if ($message->recalled_at || $message->trashed()) {
                $message->forceFill([
                    'outbound_status' => 'cancelled',
                    'outbound_error' => 'Tin nhan da duoc thu hoi truoc khi gui sang Facebook.',
                ])->save();
                event(new MessageUpdatedEvent($message));

                return;
            }

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
            $error = $this->errorMessage($exception);

            $message->forceFill($this->attempts() >= $this->tries
                ? [
                    'outbound_status' => 'failed',
                    'outbound_error' => $error,
                ]
                : [
                    'outbound_status' => 'queued',
                    'outbound_error' => $error,
                ])->save();
            event(new MessageUpdatedEvent($message));

            Log::warning('Queued outbound message failed', [
                'message_id' => $message->id,
                'conversation_id' => $message->conversation_id,
                'channel' => $message->channel,
                'attempt' => $this->attempts(),
                'max_tries' => $this->tries,
                'error' => $error,
            ]);

            throw $exception;
        }
    }

    private function errorMessage(\Throwable $exception): string
    {
        if (! $exception instanceof RequestException || ! $exception->response) {
            return $exception->getMessage();
        }

        $payload = $exception->response->json();
        $error = is_array($payload) ? (array) Arr::get($payload, 'error', []) : [];

        if ($error === []) {
            return $exception->response->body() ?: $exception->getMessage();
        }

        return trim(collect([
            Arr::get($error, 'message'),
            Arr::get($error, 'type') ? 'type='.Arr::get($error, 'type') : null,
            Arr::get($error, 'code') !== null ? 'code='.Arr::get($error, 'code') : null,
            Arr::get($error, 'error_subcode') !== null ? 'subcode='.Arr::get($error, 'error_subcode') : null,
            Arr::get($error, 'fbtrace_id') ? 'fbtrace_id='.Arr::get($error, 'fbtrace_id') : null,
        ])->filter()->implode(' | '));
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
