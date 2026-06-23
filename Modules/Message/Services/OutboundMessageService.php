<?php

namespace Modules\Message\Services;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Modules\Conversation\Models\Conversation;
use Modules\Facebook\Services\FacebookMessengerService;
use Modules\Zalo\Services\ZaloOaService;
use RuntimeException;

class OutboundMessageService
{
    private array $lastResponse = [];

    public function __construct(
        private readonly FacebookMessengerService $facebook,
        private readonly ZaloOaService $zalo,
    ) {
    }

    public function send(Conversation $conversation, string $channel, string $content, array $attachments = []): ?string
    {
        $customerChannel = $conversation->customer?->channels()
            ->where('channel', $channel)
            ->first();

        if (! $customerChannel?->external_id) {
            throw new RuntimeException("Customer does not have a {$channel} external id.");
        }

        $response = match ($channel) {
            'facebook' => $this->sendFacebook($customerChannel->external_id, $content, $attachments),
            'zalo' => $this->sendZalo($customerChannel->external_id, $content, $attachments),
            default => throw new RuntimeException("Unsupported message channel [{$channel}]."),
        };
        $this->lastResponse = $response;

        Log::info('Outbound message sent', [
            'conversation_id' => $conversation->id,
            'channel' => $channel,
            'external_id' => $customerChannel->external_id,
            'response' => $response,
        ]);

        return $this->externalMessageId($channel, $response);
    }

    public function lastResponse(): array
    {
        return $this->lastResponse;
    }

    public function sendText(Conversation $conversation, string $channel, string $content): ?string
    {
        return $this->send($conversation, $channel, $content);
    }

    private function sendFacebook(string $recipientId, string $content, array $attachments): array
    {
        $response = $content !== ''
            ? $this->facebook->sendText($recipientId, $content)
            : [];
        $sentAttachments = [];

        foreach ($attachments as $index => $attachment) {
            $type = (string) ($attachment['type'] ?? $this->facebookAttachmentType((string) ($attachment['mime_type'] ?? '')));

            if (! empty($attachment['facebook_attachment_id'])) {
                $response = $this->facebook->sendAttachmentId(
                    $recipientId,
                    (string) $attachment['facebook_attachment_id'],
                    $type,
                );

                continue;
            }

            $localPath = $this->publicAttachmentPath($attachment);

            if ($localPath) {
                $response = $this->facebook->sendLocalAttachment(
                    $recipientId,
                    $localPath,
                    $type,
                    (string) ($attachment['mime_type'] ?? ''),
                    (string) ($attachment['name'] ?? basename($localPath)),
                );

                if (! empty($response['facebook_attachment_id'])) {
                    $sentAttachments[$index] = [
                        'facebook_attachment_id' => (string) $response['facebook_attachment_id'],
                    ];
                }

                continue;
            }

            $response = $this->facebook->sendAttachment(
                $recipientId,
                (string) $attachment['url'],
                $type,
            );
        }

        if ($sentAttachments) {
            $response['_sent_attachments'] = $sentAttachments;
        }

        return $response;
    }

    private function sendZalo(string $userId, string $content, array $attachments): array
    {
        $links = collect($attachments)
            ->map(fn (array $attachment): string => ($attachment['name'] ?? 'File').': '.$attachment['url'])
            ->implode("\n");

        return $this->zalo->sendText($userId, trim($content."\n".$links));
    }

    private function facebookAttachmentType(string $mimeType): string
    {
        return match (true) {
            str_starts_with($mimeType, 'image/') => 'image',
            str_starts_with($mimeType, 'video/') => 'video',
            str_starts_with($mimeType, 'audio/') => 'audio',
            default => 'file',
        };
    }

    private function publicAttachmentPath(array $attachment): ?string
    {
        $path = (string) ($attachment['path'] ?? '');

        if ($path === '') {
            return null;
        }

        $localPath = Storage::disk('public')->path($path);

        return is_file($localPath) ? $localPath : null;
    }

    private function externalMessageId(string $channel, array $response): ?string
    {
        $id = match ($channel) {
            'facebook' => Arr::get($response, 'message_id'),
            'zalo' => Arr::get($response, 'data.message_id')
                ?? Arr::get($response, 'data.msg_id')
                ?? Arr::get($response, 'message_id'),
            default => null,
        };

        return $id ? (string) $id : null;
    }
}
