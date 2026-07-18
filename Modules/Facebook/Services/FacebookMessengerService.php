<?php

namespace Modules\Facebook\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;

class FacebookMessengerService
{
    private const QUICK_REPLY_TEXT_LIMIT = 1024;
    private const QUICK_REPLY_TITLE_LIMIT = 20;
    private const QUICK_REPLY_COUNT_LIMIT = 13;
    private const QUICK_REPLY_PAYLOAD_LIMIT = 1000;

    public function __construct(private readonly Http $http)
    {
    }

    public function sendText(string $recipientId, string $message, ?string $pageAccessToken = null): array
    {
        return $this->sendTextPayload($recipientId, ['text' => $message], $pageAccessToken);
    }

    public function sendTextWithQuickReplies(string $recipientId, string $message, array $quickReplies, ?string $pageAccessToken = null): array
    {
        return $this->sendTextPayload($recipientId, [
            'text' => $message,
            'quick_replies' => array_values($quickReplies),
        ], $pageAccessToken);
    }

    public function sendTypingOn(string $recipientId, ?string $pageAccessToken = null): bool
    {
        return $this->sendSenderAction($recipientId, 'typing_on', $pageAccessToken);
    }

    public function sendTypingOff(string $recipientId, ?string $pageAccessToken = null): bool
    {
        return $this->sendSenderAction($recipientId, 'typing_off', $pageAccessToken);
    }

    public function takeThreadControl(string $recipientId, ?string $pageAccessToken = null, string $metadata = 'CRM agent replied'): bool
    {
        if ($recipientId === '') {
            return false;
        }

        try {
            $response = $this->http
                ->connectTimeout(5)
                ->timeout(10)
                ->withToken($this->token($pageAccessToken))
                ->post($this->graphUrl('/me/take_thread_control'), [
                    'recipient' => ['id' => $recipientId],
                    'metadata' => $metadata,
                ]);

            if ($response->successful()) {
                Log::info('Facebook thread control taken for CRM reply', [
                    'recipient_id' => $recipientId,
                ]);

                return true;
            }

            Log::warning('Facebook take_thread_control failed', [
                'recipient_id' => $recipientId,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
        } catch (\Throwable $exception) {
            Log::warning('Facebook take_thread_control exception', [
                'recipient_id' => $recipientId,
                'error' => $exception->getMessage(),
            ]);
        }

        return false;
    }

    public function sendSenderAction(string $recipientId, string $action, ?string $pageAccessToken = null): bool
    {
        if (! in_array($action, ['typing_on', 'typing_off', 'mark_seen'], true)) {
            throw new RuntimeException("Unsupported Facebook sender action [{$action}].");
        }

        if ($recipientId === '') {
            return false;
        }

        try {
            $response = $this->http
                ->connectTimeout(3)
                ->timeout(5)
                ->withToken($this->token($pageAccessToken))
                ->post($this->graphUrl('/me/messages'), [
                    'recipient' => ['id' => $recipientId],
                    'sender_action' => $action,
                ]);

            if ($response->successful()) {
                return true;
            }

            Log::warning('Facebook sender action failed', [
                'recipient_id' => $recipientId,
                'action' => $action,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
        } catch (\Throwable $exception) {
            Log::warning('Facebook sender action exception', [
                'recipient_id' => $recipientId,
                'action' => $action,
                'error' => $exception->getMessage(),
            ]);
        }

        return false;
    }

    private function sendTextPayload(string $recipientId, array $message, ?string $pageAccessToken = null): array
    {
        $message = $this->normalizeTextPayload($message);

        if (! empty($message['quick_replies'])) {
            Log::warning('Facebook text payload includes quick replies', [
                'recipient_id' => $recipientId,
                'quick_replies_count' => count((array) $message['quick_replies']),
                'quick_replies' => $message['quick_replies'],
                'text_preview' => mb_substr((string) ($message['text'] ?? ''), 0, 200),
            ]);
        }

        try {
            $response = $this->http
                ->connectTimeout(5)
                ->timeout(15)
                ->withToken($this->token($pageAccessToken))
                ->post($this->graphUrl('/me/messages'), [
                    'messaging_type' => 'RESPONSE',
                    'recipient' => ['id' => $recipientId],
                    'message' => $message,
                ]);
        } catch (ConnectionException $exception) {
            if (! str_contains($exception->getMessage(), 'cURL error 28')) {
                throw $exception;
            }

            Log::warning('Facebook text send timed out after request was sent', [
                'recipient_id' => $recipientId,
                'error' => $exception->getMessage(),
            ]);

            return [
                'recipient_id' => $recipientId,
                'message_id' => null,
                'delivery_status' => 'sent_response_timeout',
            ];
        }

        $response->throw();
        return $response->json();
    }

    private function normalizeTextPayload(array $message): array
    {
        $quickReplies = $this->normalizeQuickReplies((array) ($message['quick_replies'] ?? []));

        if ($quickReplies === []) {
            unset($message['quick_replies']);

            return $message;
        }

        $originalText = (string) ($message['text'] ?? '');
        $message['text'] = mb_substr($originalText, 0, self::QUICK_REPLY_TEXT_LIMIT);
        $message['quick_replies'] = $quickReplies;

        if (mb_strlen($originalText) > self::QUICK_REPLY_TEXT_LIMIT) {
            Log::info('Facebook quick reply text truncated to Messenger limit', [
                'original_length' => mb_strlen($originalText),
                'limit' => self::QUICK_REPLY_TEXT_LIMIT,
            ]);
        }

        return $message;
    }

    private function normalizeQuickReplies(array $quickReplies): array
    {
        return collect($quickReplies)
            ->filter(fn (mixed $item): bool => is_array($item))
            ->map(function (array $item): ?array {
                $contentType = (string) ($item['content_type'] ?? 'text');

                if ($contentType === 'user_phone_number') {
                    return ['content_type' => 'user_phone_number'];
                }

                if ($contentType !== 'text') {
                    return null;
                }

                $title = trim((string) ($item['title'] ?? ''));

                if ($title === '') {
                    return null;
                }

                $payload = trim((string) ($item['payload'] ?? $title));
                $payload = $this->payloadAlignedWithTitle($title, $payload);

                return [
                    'content_type' => 'text',
                    'title' => mb_substr($title, 0, self::QUICK_REPLY_TITLE_LIMIT),
                    'payload' => mb_substr($payload, 0, self::QUICK_REPLY_PAYLOAD_LIMIT),
                ];
            })
            ->filter()
            ->unique(fn (array $item): string => ($item['content_type'] ?? '').'|'.($item['title'] ?? ''))
            ->take(self::QUICK_REPLY_COUNT_LIMIT)
            ->values()
            ->all();
    }

    private function payloadAlignedWithTitle(string $title, string $payload): string
    {
        $titleIntent = $this->quickReplyIntent($title);
        $payloadIntent = $this->quickReplyIntent($payload);

        if ($titleIntent !== null && ($payloadIntent === null || $payloadIntent !== $titleIntent)) {
            $fixedPayload = $this->payloadForQuickReplyIntent($titleIntent);

            Log::info('Facebook quick reply payload aligned with title', [
                'title' => mb_substr($title, 0, self::QUICK_REPLY_TITLE_LIMIT),
                'old_payload' => mb_substr($payload, 0, 160),
                'new_payload' => $fixedPayload,
            ]);

            return $fixedPayload;
        }

        return $payload !== '' ? $payload : $title;
    }

    private function quickReplyIntent(string $text): ?string
    {
        $normalized = Str::of($text)
            ->lower()
            ->ascii()
            ->replaceMatches('/[^a-z0-9\s]+/', ' ')
            ->replaceMatches('/\s+/', ' ')
            ->trim()
            ->toString();

        foreach ($this->quickReplyIntentKeywords() as $intent => $keywords) {
            foreach ($keywords as $keyword) {
                if (str_contains($normalized, $keyword)) {
                    return $intent;
                }
            }
        }

        return null;
    }

    private function payloadForQuickReplyIntent(string $intent): string
    {
        return match ($intent) {
            'specs' => 'Khách muốn xem thông số kỹ thuật, trang bị và đặc điểm của mẫu xe đang được tư vấn.',
            'promotion' => 'Khách muốn hỏi ưu đãi và khuyến mãi hiện tại cho mẫu xe đang được tư vấn.',
            'price' => 'Khách muốn hỏi giá niêm yết hoặc giá lăn bánh của mẫu xe đang được tư vấn.',
            'finance' => 'Khách muốn hỏi phương án trả góp cho mẫu xe đang được tư vấn.',
            'documents' => 'Khách muốn biết hồ sơ và giấy tờ cần chuẩn bị để mua xe.',
            'colors' => 'Khách muốn hỏi mẫu xe đang được tư vấn còn những màu nào.',
            'test_drive' => 'Khách muốn đặt lịch lái thử hoặc hỏi điều kiện lái thử mẫu xe đang quan tâm.',
            'appointment' => 'Khách muốn đặt lịch hẹn để được Toyota Kiên Giang hỗ trợ.',
            'compare' => 'Khách muốn so sánh mẫu xe đang được tư vấn với mẫu xe khác.',
            'availability' => 'Khách muốn hỏi xe còn hàng hoặc thời gian giao xe.',
            'phone' => 'Khách muốn để lại số điện thoại để nhân viên Toyota Kiên Giang liên hệ tư vấn.',
            default => 'Khách muốn được tư vấn tiếp theo đúng nội dung nút đã chọn.',
        };
    }

    private function quickReplyIntentKeywords(): array
    {
        return [
            'specs' => ['thong so', 'trang bi', 'dong co', 'kich thuoc', 'noi that', 'ngoai that', 'an toan', 'tieu hao', 'option'],
            'promotion' => ['uu dai', 'khuyen mai', 'giam gia', 'qua tang', 'chuong trinh'],
            'price' => ['gia', 'lan banh', 'bao gia', 'niem yet'],
            'finance' => ['tra gop', 'lai suat', 'vay', 'tra truoc', 'ngan hang', 'gop'],
            'documents' => ['ho so', 'giay to', 'cccd', 'cmnd', 'thu tuc'],
            'colors' => ['mau', 'mau nao', 'mau xe'],
            'test_drive' => ['lai thu', 'test drive'],
            'appointment' => ['dat lich', 'lich hen', 'hen lich', 'showroom'],
            'compare' => ['so sanh', 'khac gi', 'hon gi'],
            'availability' => ['con xe', 'con hang', 'giao xe', 'co san'],
            'phone' => ['so dien thoai', 'sdt', 'gui so', 'de lai so', 'goi lai', 'lien he'],
        ];
    }

    public function profile(string $psid, ?string $pageAccessToken = null): array
    {
        if ($psid === '') {
            return [];
        }

        try {
            $accessToken = $this->token($pageAccessToken);
            $response = $this->http
                ->connectTimeout(5)
                ->timeout(10)
                ->get($this->graphUrl("/{$psid}"), [
                    'fields' => 'name,first_name,last_name,profile_pic',
                    'access_token' => $accessToken,
                ]);

            if (! $response->successful()) {
                Log::warning('Facebook profile lookup failed', [
                    'psid' => $psid,
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);

                return [];
            }

            $profile = $response->json();

            Log::info('Facebook profile lookup succeeded', [
                'psid' => $psid,
                'has_name' => filled(data_get($profile, 'name')) || filled(data_get($profile, 'first_name')),
                'has_profile_pic' => filled(data_get($profile, 'profile_pic')),
            ]);

            return $profile;
        } catch (\Throwable $exception) {
            Log::warning('Facebook profile lookup exception', [
                'psid' => $psid,
                'error' => $exception->getMessage(),
            ]);

            return [];
        }
    }

    public function sendAttachment(string $recipientId, string $url, string $type = 'file', ?string $pageAccessToken = null): array
    {
        if (! in_array($type, ['image', 'audio', 'video', 'file'], true)) {
            throw new RuntimeException("Unsupported Facebook attachment type [{$type}].");
        }

        if (! str_starts_with($url, 'https://')) {
            throw new RuntimeException("Facebook attachment URL must be public HTTPS. Current URL: {$url}");
        }

        $payload = [
            'messaging_type' => 'RESPONSE',
            'recipient' => ['id' => $recipientId],
            'message' => [
                'attachment' => [
                    'type' => $type,
                    'payload' => [
                        'url' => $url,
                        'is_reusable' => true,
                    ],
                ],
            ],
        ];

        Log::info('Facebook attachment payload prepared', [
            'recipient_id' => $recipientId,
            'attachment_type' => $type,
            'attachment_url' => $url,
        ]);

        try {
            $response = $this->http
                ->connectTimeout(10)
                ->timeout(60)
                ->withToken($this->token($pageAccessToken))
                ->post($this->graphUrl('/me/messages'), $payload);
        } catch (ConnectionException $exception) {
            if (! str_contains($exception->getMessage(), 'cURL error 28')) {
                throw $exception;
            }

            Log::warning('Facebook attachment send timed out after request was sent', [
                'recipient_id' => $recipientId,
                'attachment_type' => $type,
                'attachment_url' => $url,
                'error' => $exception->getMessage(),
            ]);

            return [
                'recipient_id' => $recipientId,
                'message_id' => null,
                'delivery_status' => 'sent_response_timeout',
            ];
        }

        $response->throw();
        return $response->json();
    }

    public function sendLocalAttachment(string $recipientId, string $path, string $type = 'file', ?string $mimeType = null, ?string $filename = null, ?string $pageAccessToken = null): array
    {
        if (! in_array($type, ['image', 'audio', 'video', 'file'], true)) {
            throw new RuntimeException("Unsupported Facebook attachment type [{$type}].");
        }

        if (! is_file($path)) {
            throw new RuntimeException("Facebook attachment file does not exist: {$path}");
        }

        [$uploadPath, $uploadMimeType, $removeAfterUpload] = $this->prepareUploadFile($path, $type, $mimeType);

        try {
            $attachmentId = $this->uploadReusableAttachment($uploadPath, $type, $uploadMimeType, $filename, $pageAccessToken);
        } finally {
            if ($removeAfterUpload) {
                @unlink($uploadPath);
            }
        }

        $response = $this->http
            ->connectTimeout(10)
            ->timeout(30)
            ->withToken($this->token($pageAccessToken))
            ->post($this->graphUrl('/me/messages'), [
                'messaging_type' => 'RESPONSE',
                'recipient' => ['id' => $recipientId],
                'message' => [
                    'attachment' => [
                        'type' => $type,
                        'payload' => [
                            'attachment_id' => $attachmentId,
                        ],
                    ],
                ],
            ]);

        $response->throw();
        return array_merge($response->json(), [
            'facebook_attachment_id' => $attachmentId,
        ]);
    }

    public function sendAttachmentId(string $recipientId, string $attachmentId, string $type = 'file', ?string $pageAccessToken = null): array
    {
        if (! in_array($type, ['image', 'audio', 'video', 'file'], true)) {
            throw new RuntimeException("Unsupported Facebook attachment type [{$type}].");
        }

        $response = $this->http
            ->connectTimeout(10)
            ->timeout(30)
            ->withToken($this->token($pageAccessToken))
            ->post($this->graphUrl('/me/messages'), [
                'messaging_type' => 'RESPONSE',
                'recipient' => ['id' => $recipientId],
                'message' => [
                    'attachment' => [
                        'type' => $type,
                        'payload' => [
                            'attachment_id' => $attachmentId,
                        ],
                    ],
                ],
            ]);

        $response->throw();
        return $response->json();
    }

    private function prepareUploadFile(string $path, string $type, ?string $mimeType): array
    {
        if ($type !== 'image' || $mimeType === 'image/gif') {
            return [$path, $mimeType, false];
        }

        $contents = @file_get_contents($path);
        $image = $contents !== false ? @imagecreatefromstring($contents) : false;

        if (! $image) {
            return [$path, $mimeType, false];
        }

        $width = imagesx($image);
        $height = imagesy($image);
        $maxSize = 1280;
        $ratio = min(1, $maxSize / max($width, $height));
        $targetWidth = max(1, (int) round($width * $ratio));
        $targetHeight = max(1, (int) round($height * $ratio));
        $target = imagecreatetruecolor($targetWidth, $targetHeight);
        $white = imagecolorallocate($target, 255, 255, 255);

        imagefill($target, 0, 0, $white);
        imagecopyresampled($target, $image, 0, 0, 0, 0, $targetWidth, $targetHeight, $width, $height);

        $tmpPath = tempnam(sys_get_temp_dir(), 'crm_fb_image_');

        if ($tmpPath === false) {
            imagedestroy($image);
            imagedestroy($target);

            return [$path, $mimeType, false];
        }

        imagejpeg($target, $tmpPath, 82);
        imagedestroy($image);
        imagedestroy($target);

        return [$tmpPath, 'image/jpeg', true];
    }

    private function uploadReusableAttachment(string $path, string $type, ?string $mimeType, ?string $filename, ?string $pageAccessToken = null): string
    {
        $handle = fopen($path, 'r');

        if ($handle === false) {
            throw new RuntimeException("Cannot open Facebook attachment file: {$path}");
        }

        try {
            $response = $this->http
                ->connectTimeout(10)
                ->timeout(60)
                ->withToken($this->token($pageAccessToken))
                ->attach('filedata', $handle, $filename ?: basename($path), $mimeType ? ['Content-Type' => $mimeType] : [])
                ->post($this->graphUrl('/me/message_attachments'), [
                    'message' => json_encode([
                        'attachment' => [
                            'type' => $type,
                            'payload' => [
                                'is_reusable' => true,
                            ],
                        ],
                    ]),
                ]);
        } finally {
            fclose($handle);
        }

        $response->throw();
        $attachmentId = data_get($response->json(), 'attachment_id');

        if (! $attachmentId) {
            throw new RuntimeException('Facebook did not return an attachment_id.');
        }

        Log::info('Facebook attachment uploaded', [
            'attachment_type' => $type,
            'filename' => $filename ?: basename($path),
            'attachment_id' => $attachmentId,
        ]);

        return (string) $attachmentId;
    }

    private function token(?string $pageAccessToken = null): string
    {
        if (! $pageAccessToken) {
            throw new RuntimeException('Facebook page access token is missing for this conversation.');
        }

        return $pageAccessToken;
    }

    private function graphUrl(string $path): string
    {
        return 'https://graph.facebook.com/'.config('services.facebook.graph_version', 'v25.0').$path;
    }
}
