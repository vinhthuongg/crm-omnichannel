<?php

namespace Modules\Message\Services;

use Illuminate\Support\Arr;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Modules\Conversation\Models\Conversation;
use Modules\Facebook\Models\FacebookPage;
use Modules\Facebook\Repositories\FacebookPageRepository;
use Modules\Facebook\Services\FacebookMessengerService;
use Modules\Facebook\Services\FacebookTokenValidationService;
use Modules\Zalo\Services\ZaloOaService;
use RuntimeException;

class OutboundMessageService
{
    private array $lastResponse = [];

    public function __construct(
        private readonly FacebookMessengerService $facebook,
        private readonly ZaloOaService $zalo,
        private readonly FacebookTokenValidationService $tokens,
        private readonly FacebookPageRepository $facebookPages,
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
            'facebook' => $this->sendFacebook($customerChannel->external_id, $content, $attachments, $this->facebookPageToken($conversation)),
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

    public function stopTyping(Conversation $conversation, string $channel): void
    {
        if ($channel !== 'facebook') {
            return;
        }

        $customerChannel = $conversation->customer?->channels()
            ->where('channel', $channel)
            ->first();

        if (! $customerChannel?->external_id) {
            return;
        }

        $this->facebook->sendTypingOff(
            $customerChannel->external_id,
            $this->facebookPageToken($conversation),
        );
    }

    private function sendFacebook(string $recipientId, string $content, array $attachments, ?string $pageAccessToken = null): array
    {
        try {
            return $this->sendFacebookPayload($recipientId, $content, $attachments, $pageAccessToken);
        } catch (RequestException $exception) {
            if (! $this->isAnotherAppControlError($exception)) {
                throw $exception;
            }

            Log::warning('Facebook send blocked by another app, taking thread control and retrying once', [
                'recipient_id' => $recipientId,
                'status' => $exception->response->status(),
                'body' => $exception->response->body(),
            ]);

            if (! $this->facebook->takeThreadControl($recipientId, $pageAccessToken)) {
                throw $exception;
            }

            return $this->sendFacebookPayload($recipientId, $content, $attachments, $pageAccessToken);
        }
    }

    private function sendFacebookPayload(string $recipientId, string $content, array $attachments, ?string $pageAccessToken = null): array
    {
        $quickReplies = $this->quickReplies($attachments);
        $response = $content !== ''
            ? ($quickReplies
                ? $this->facebook->sendTextWithQuickReplies($recipientId, $content, $quickReplies, $pageAccessToken)
                : $this->facebook->sendText($recipientId, $content, $pageAccessToken))
            : [];
        $sentAttachments = [];
        $failedAttachments = [];

        foreach ($attachments as $index => $attachment) {
            if (($attachment['type'] ?? '') === 'quick_reply') {
                continue;
            }

            if (empty($attachment['facebook_attachment_id'])
                && empty($attachment['url'])
                && empty($attachment['path'])) {
                continue;
            }

            try {
                $type = (string) ($attachment['type'] ?? $this->facebookAttachmentType((string) ($attachment['mime_type'] ?? '')));

                if (! empty($attachment['facebook_attachment_id'])) {
                    $response = $this->facebook->sendAttachmentId(
                        $recipientId,
                        (string) $attachment['facebook_attachment_id'],
                        $type,
                        $pageAccessToken,
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
                        $pageAccessToken,
                    );

                    if (! empty($response['facebook_attachment_id'])) {
                        $sentAttachments[$index] = [
                            'facebook_attachment_id' => (string) $response['facebook_attachment_id'],
                        ];
                    }

                    continue;
                }

                try {
                    $response = $this->facebook->sendAttachment(
                        $recipientId,
                        (string) ($attachment['url'] ?? ''),
                        $type,
                        $pageAccessToken,
                    );
                } catch (RequestException $exception) {
                    if (! $this->isFacebookAttachmentUploadError($exception)) {
                        throw $exception;
                    }

                    Log::warning('Facebook could not fetch attachment URL, retrying with local upload', [
                        'recipient_id' => $recipientId,
                        'attachment_type' => $type,
                        'attachment_url' => (string) ($attachment['url'] ?? ''),
                        'status' => $exception->response->status(),
                        'body' => $exception->response->body(),
                    ]);

                    $tmpPath = $this->downloadRemoteAttachment($attachment);

                    try {
                        $response = $this->facebook->sendLocalAttachment(
                            $recipientId,
                            $tmpPath,
                            $type,
                            (string) ($attachment['mime_type'] ?? ''),
                            (string) ($attachment['name'] ?? basename($tmpPath)),
                            $pageAccessToken,
                        );
                    } finally {
                        @unlink($tmpPath);
                    }

                    if (! empty($response['facebook_attachment_id'])) {
                        $sentAttachments[$index] = [
                            'facebook_attachment_id' => (string) $response['facebook_attachment_id'],
                        ];
                    }
                }
            } catch (\Throwable $exception) {
                $failedAttachments[$index] = [
                    'name' => (string) ($attachment['name'] ?? ''),
                    'url' => (string) ($attachment['url'] ?? ''),
                    'error' => $exception->getMessage(),
                ];

                Log::warning('Facebook attachment send failed but text reply was preserved', [
                    'recipient_id' => $recipientId,
                    'attachment_type' => (string) ($attachment['type'] ?? ''),
                    'attachment_name' => (string) ($attachment['name'] ?? ''),
                    'attachment_url' => (string) ($attachment['url'] ?? ''),
                    ...$this->exceptionContext($exception),
                ]);
                continue;
            }
        }

        if ($response === [] && $sentAttachments === [] && $content !== '') {
            Log::warning('Facebook outbound sent text-only fallback after all attachments failed', [
                'recipient_id' => $recipientId,
                'content_preview' => mb_substr($content, 0, 200),
                'attachments_count' => count($attachments),
            ]);
                }

        if ($sentAttachments) {
            $response['_sent_attachments'] = $sentAttachments;
        }

        if ($failedAttachments) {
            $response['_failed_attachments'] = $failedAttachments;
        }

        return $response;
    }

    private function isAnotherAppControlError(RequestException $exception): bool
    {
        $payload = $exception->response->json();
        $error = is_array($payload) ? (array) Arr::get($payload, 'error', []) : [];
        $message = mb_strtolower((string) Arr::get($error, 'message', ''));

        return (int) Arr::get($error, 'code') === 10
            && (
                str_contains($message, 'another app')
                || str_contains($message, 'ứng dụng khác')
                || str_contains($message, 'ung dung khac')
            );
    }

    private function isFacebookAttachmentUploadError(RequestException $exception): bool
    {
        $payload = $exception->response->json();
        $error = is_array($payload) ? (array) Arr::get($payload, 'error', []) : [];
        $message = mb_strtolower((string) Arr::get($error, 'message', ''));

        return (int) Arr::get($error, 'code') === 100
            && (
                (int) Arr::get($error, 'error_subcode') === 2018047
                || str_contains($message, 'could not upload')
                || str_contains($message, 'không thể tải file')
                || str_contains($message, 'khong the tai file')
            );
    }

    private function downloadRemoteAttachment(array $attachment): string
    {
        $url = (string) ($attachment['url'] ?? '');

        if ($url === '') {
            throw new RuntimeException('Attachment URL is empty.');
        }

        $response = Http::connectTimeout(10)
            ->timeout(45)
            ->get($url)
            ->throw();

        $tmpPath = tempnam(sys_get_temp_dir(), 'crm_botpress_attachment_');

        if ($tmpPath === false) {
            throw new RuntimeException('Cannot create temporary file for attachment upload.');
        }

        file_put_contents($tmpPath, $response->body());

        Log::info('Remote attachment downloaded for Facebook local upload', [
            'attachment_url' => $url,
            'bytes' => filesize($tmpPath) ?: null,
            'content_type' => $response->header('Content-Type'),
        ]);

        return $tmpPath;
    }

    private function quickReplies(array $attachments): array
    {
        foreach ($attachments as $attachment) {
            if (($attachment['type'] ?? '') === 'quick_reply') {
                return array_values((array) ($attachment['quick_replies'] ?? []));
            }
        }

        return [];
    }

    private function sendZalo(string $userId, string $content, array $attachments): array
    {
        $links = collect($attachments)
            ->map(fn (array $attachment): string => ($attachment['name'] ?? 'File').': '.$attachment['url'])
            ->implode("\n");

        return $this->zalo->sendText($userId, trim($content."\n".$links));
    }

    private function facebookPageToken(Conversation $conversation): ?string
    {
        $pageId = (string) $conversation->facebook_page_id;

        if ($pageId === '') {
            return null;
        }

        $page = FacebookPage::query()
            ->where('page_id', $pageId)
            ->first();

        if (! $page) {
            return null;
        }

        try {
            if ($page->token_status !== 'valid') {
                $this->tokens->ensurePageBelongsToMessengerApp($page->messenger_app_id);
                $debugToken = $this->tokens->validatePageToken($page->page_access_token);
                $this->facebookPages->markValid($page, $debugToken);
            }
        } catch (\Throwable $exception) {
            $this->facebookPages->markInvalid($page, $exception->getMessage());
            throw new RuntimeException('Facebook page token khong hop le. Vui long reconnect fanpage: '.$exception->getMessage(), previous: $exception);
        }

        return $page->page_access_token;
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

    private function exceptionContext(\Throwable $exception): array
    {
        $context = ['error' => $exception->getMessage()];

        if ($exception instanceof RequestException && $exception->response) {
            $context['status'] = $exception->response->status();
            $context['body'] = mb_substr($exception->response->body(), 0, 2000);
        }

        return $context;
    }
}
