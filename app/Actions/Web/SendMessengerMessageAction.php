<?php

namespace App\Actions\Web;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\Conversation\Models\Tag;
use Modules\Conversation\Models\Conversation;
use Modules\Conversation\Services\ConversationService;
use Modules\Message\Events\NewMessageEvent;
use Modules\Message\Events\MessageUpdatedEvent;
use Modules\Message\Jobs\SendOutboundMessageJob;
use Modules\Message\Models\Message;
use Modules\Message\Services\MessengerAttachmentStorage;
use Modules\Message\Services\OutboundMessageService;
use Modules\Search\Services\VectorSearchService;
use Modules\Text\Services\TextConversationBridge;

class SendMessengerMessageAction
{
    public function __construct(
        private readonly MessengerAttachmentStorage $attachmentStorage,
        private readonly ConversationService $conversations,
        private readonly TextConversationBridge $textBridge,
    ) {
    }

    public function execute(Conversation $conversation, User $user, array $data): Message
    {
        $attachments = $this->normalizeUploadedAttachments($data['uploaded_attachments'] ?? []);
        $attachments = array_merge($attachments, $this->storeAttachments($data['attachments'] ?? []));
        $content = (string) ($data['content'] ?? '');
        $clientMessageId = (string) ($data['client_message_id'] ?? '');
        $isWhisper = ($data['message_mode'] ?? 'message') === 'whisper';
        $channel = $isWhisper ? 'internal' : $data['channel'];

        if ($clientMessageId !== '') {
            $existing = Message::query()
                ->where('channel', $channel)
                ->where('client_message_id', $clientMessageId)
                ->with('sender')
                ->first();

            if ($existing) {
                return $existing;
            }
        }

        $message = DB::transaction(function () use ($conversation, $user, $content, $attachments, $clientMessageId, $isWhisper, $channel): Message {
            $message = Message::query()->create([
                'conversation_id' => $conversation->id,
                'sender_type' => 'user',
                'sender_id' => $user->id,
                'channel' => $channel,
                'content' => $content !== '' ? $content : null,
                'message_type' => $isWhisper ? 'whisper' : ($attachments ? 'attachment' : 'text'),
                'attachments' => $attachments,
                'external_message_id' => null,
                'client_message_id' => $clientMessageId !== '' ? $clientMessageId : null,
                'outbound_status' => $isWhisper ? null : 'queued',
            ]);

            $this->conversations->recordOutboundMessage($conversation, $message, $isWhisper);

            if (! $isWhisper) {
                $this->markConversationAsConsulting($conversation);
            }

            return $message;
        });

        try {
            event(new NewMessageEvent($message));
        } catch (\Throwable) {
        }

        if ($isWhisper) {
            return $message;
        }

        $this->queueCustomerVectorRefresh($message);
        $this->textBridge->pauseBotForConversation($conversation);

        if ($attachments) {
            SendOutboundMessageJob::dispatch($message->id);
            $this->kickLocalQueueWorker();
        } else {
            $this->sendTextAfterResponse($message);
        }

        return $message;
    }

    private function queueCustomerVectorRefresh(Message $message): void
    {
        if (! config('search.vector.enabled', true)) {
            return;
        }

        app()->terminating(function () use ($message): void {
            try {
                $message = $message->fresh(['conversation.customer']);
                $customer = $message?->conversation?->customer;

                if ($customer) {
                    app(VectorSearchService::class)->indexCustomer($customer);
                }
            } catch (\Throwable $exception) {
                Log::warning('Customer vector index refresh failed', [
                    'message_id' => $message->id,
                    'error' => $exception->getMessage(),
                ]);
            }
        });
    }

    private function sendTextAfterResponse(Message $message): void
    {
        app()->terminating(function () use ($message): void {
            $message = $message->fresh(['conversation.customer.channels']);

            if (! $message?->conversation) {
                return;
            }

            try {
                $message->forceFill([
                    'outbound_status' => 'sending',
                    'outbound_error' => null,
                ])->save();
                event(new MessageUpdatedEvent($message));

                $externalMessageId = app(OutboundMessageService::class)->send(
                    $message->conversation,
                    $message->channel,
                    (string) $message->content,
                    [],
                );

                $message->forceFill([
                    'external_message_id' => $externalMessageId,
                    'outbound_status' => 'sent',
                    'outbound_error' => null,
                    'sent_at' => now(),
                ])->save();
                event(new MessageUpdatedEvent($message));
            } catch (\Throwable $exception) {
                $message->forceFill([
                    'outbound_status' => 'failed',
                    'outbound_error' => $exception->getMessage(),
                ])->save();
                event(new MessageUpdatedEvent($message));

                report($exception);
            }
        });
    }

    private function kickLocalQueueWorker(): void
    {
        if (! app()->environment('local') || config('queue.default') !== 'database') {
            return;
        }

        app()->terminating(function (): void {
            try {
                app(\Illuminate\Contracts\Console\Kernel::class)->call('queue:work', [
                    'connection' => 'database',
                    '--queue' => 'outbound,default',
                    '--once' => true,
                    '--sleep' => 0,
                    '--tries' => 2,
                    '--timeout' => 120,
                ]);
            } catch (\Throwable $exception) {
                Log::warning('Local queue kick failed', [
                    'error' => $exception->getMessage(),
                ]);
            }
        });
    }

    private function markConversationAsConsulting(Conversation $conversation): void
    {
        Tag::ensureDefaults();

        $tag = Tag::query()->firstOrCreate(
            ['name' => Tag::DEFAULT_CONSULTING],
            ['color' => Tag::DEFAULTS[Tag::DEFAULT_CONSULTING], 'is_default' => true],
        );

        $conversation->tags()->sync([$tag->id]);
        $conversation->load('tags');
    }

    private function storeAttachments(array $files): array
    {
        return collect($files)
            ->filter()
            ->map(fn ($file): array => $this->attachmentStorage->store($file))
            ->values()
            ->all();
    }

    private function normalizeUploadedAttachments(array $attachments): array
    {
        return collect($attachments)
            ->filter(fn (array $attachment): bool => str_starts_with((string) ($attachment['path'] ?? ''), 'messages/'))
            ->map(fn (array $attachment): array => [
                'name' => (string) ($attachment['name'] ?? 'Attachment'),
                'path' => (string) ($attachment['path'] ?? ''),
                'url' => (string) ($attachment['url'] ?? ''),
                'mime_type' => (string) ($attachment['mime_type'] ?? ''),
                'type' => (string) ($attachment['type'] ?? 'file'),
                'size' => (int) ($attachment['size'] ?? 0),
                ...(! empty($attachment['facebook_attachment_id']) ? ['facebook_attachment_id' => (string) $attachment['facebook_attachment_id']] : []),
            ])
            ->values()
            ->all();
    }
}
