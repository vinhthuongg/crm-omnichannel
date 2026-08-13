<?php

namespace Modules\Facebook\Services;

use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class FacebookOutboundDispatcher
{
    /** Khởi tạo dispatcher với cổng gửi tin Messenger. */
    public function __construct(private readonly FacebookMessengerService $facebook) {}

    /** Gửi nội dung và toàn bộ tệp đính kèm, đồng thời xử lý cơ chế lấy quyền thread. */
    public function send(string $recipient, string $content, array $attachments, ?string $token): array
    {
        try {
            return $this->payload($recipient, $content, $attachments, $token);
        } catch (RequestException $exception) {
            if (! $this->isControlError($exception)) {
                throw $exception;
            }
            Log::warning('Facebook send blocked by another app, taking thread control and retrying once', ['recipient_id' => $recipient, 'status' => $exception->response->status(), 'body' => $exception->response->body()]);
            if (! $this->facebook->takeThreadControl($recipient, $token)) {
                throw $exception;
            }

            return $this->payload($recipient, $content, $attachments, $token);
        }
    }

    /** Điều phối từng thành phần payload thành các request Messenger thích hợp. */
    private function payload(string $recipient, string $content, array $attachments, ?string $token): array
    {
        $replies = $this->quickReplies($attachments);
        $response = $content !== '' ? ($replies
            ? $this->facebook->sendTextWithQuickReplies($recipient, $content, $replies, $token)
            : $this->facebook->sendText($recipient, $content, $token)) : [];
        $sent = [];
        $failed = [];
        foreach ($attachments as $index => $attachment) {
            if (($attachment['type'] ?? '') === 'quick_reply') {
                continue;
            }
            if (($attachment['type'] ?? '') === 'generic_template') {
                try {
                    $response = $this->facebook->sendGenericTemplate($recipient, (array) ($attachment['elements'] ?? []), $token);
                } catch (\Throwable $exception) {
                    $failed[$index] = ['name' => 'Messenger carousel', 'url' => '', 'error' => $exception->getMessage()];
                    Log::warning('Facebook Generic Template send failed', ['recipient_id' => $recipient, ...$this->exceptionContext($exception)]);
                }

                continue;
            }
            if (empty($attachment['facebook_attachment_id']) && empty($attachment['url']) && empty($attachment['path'])) {
                continue;
            }
            try {
                $type = (string) ($attachment['type'] ?? $this->type((string) ($attachment['mime_type'] ?? '')));
                if (! empty($attachment['facebook_attachment_id'])) {
                    $response = $this->facebook->sendAttachmentId($recipient, (string) $attachment['facebook_attachment_id'], $type, $token);

                    continue;
                }
                $local = $this->localPath($attachment);
                if ($local) {
                    $response = $this->facebook->sendLocalAttachment($recipient, $local, $type, (string) ($attachment['mime_type'] ?? ''), (string) ($attachment['name'] ?? basename($local)), $token);
                    $this->captureId($sent, $index, $response);

                    continue;
                }
                try {
                    $response = $this->facebook->sendAttachment($recipient, (string) ($attachment['url'] ?? ''), $type, $token);
                } catch (RequestException $exception) {
                    if (! $this->isUploadError($exception)) {
                        throw $exception;
                    }
                    $tmp = $this->download($attachment);
                    try {
                        $response = $this->facebook->sendLocalAttachment($recipient, $tmp, $type, (string) ($attachment['mime_type'] ?? ''), (string) ($attachment['name'] ?? basename($tmp)), $token);
                    } finally {
                        @unlink($tmp);
                    }
                    $this->captureId($sent, $index, $response);
                }
            } catch (\Throwable $exception) {
                $failed[$index] = ['name' => (string) ($attachment['name'] ?? ''), 'url' => (string) ($attachment['url'] ?? ''), 'error' => $exception->getMessage()];
                Log::warning('Facebook attachment send failed but text reply was preserved', ['recipient_id' => $recipient, 'attachment_type' => (string) ($attachment['type'] ?? ''),
                    'attachment_name' => (string) ($attachment['name'] ?? ''), 'attachment_url' => (string) ($attachment['url'] ?? ''), ...$this->exceptionContext($exception)]);
            }
        }
        if ($sent) {
            $response['_sent_attachments'] = $sent;
        }
        if ($failed) {
            $response['_failed_attachments'] = $failed;
        }

        return $response;
    }

    /** Ghi nhận message ID Facebook trả về cho phần nội dung vừa gửi. */
    private function captureId(array &$sent, int $index, array $response): void
    {
        if (! empty($response['facebook_attachment_id'])) {
            $sent[$index] = ['facebook_attachment_id' => (string) $response['facebook_attachment_id']];
        }
    }

    /** Trích xuất danh sách quick reply từ metadata của tệp đính kèm. */
    private function quickReplies(array $attachments): array
    {
        foreach ($attachments as $item) {
            if (($item['type'] ?? '') === 'quick_reply') {
                return array_values((array) ($item['quick_replies'] ?? []));
            }
        }

        return [];
    }

    /** Xác định đường dẫn local hợp lệ của tệp nếu tệp nằm trong storage. */
    private function localPath(array $attachment): ?string
    {
        $path = (string) ($attachment['path'] ?? '');
        if ($path === '') {
            return null;
        }
        $local = Storage::disk('public')->path($path);

        return is_file($local) ? $local : null;
    }

    /** Tải tạm tệp từ nguồn từ xa để có thể upload trực tiếp lên Facebook. */
    private function download(array $attachment): string
    {
        $url = (string) ($attachment['url'] ?? '');
        if ($url === '') {
            throw new RuntimeException('Attachment URL is empty.');
        }
        $response = Http::connectTimeout(10)->timeout(45)->get($url)->throw();
        $tmp = tempnam(sys_get_temp_dir(), 'crm_message_attachment_');
        if ($tmp === false) {
            throw new RuntimeException('Cannot create temporary file for attachment upload.');
        }
        file_put_contents($tmp, $response->body());

        return $tmp;
    }

    /** Ánh xạ MIME type sang loại attachment mà Messenger hỗ trợ. */
    private function type(string $mime): string
    {
        return match (true) {
            str_starts_with($mime, 'image/') => 'image', str_starts_with($mime, 'video/') => 'video',
            str_starts_with($mime, 'audio/') => 'audio', default => 'file'
        };
    }

    /** Kiểm tra lỗi có phát sinh do ứng dụng chưa nắm quyền điều khiển thread hay không. */
    private function isControlError(RequestException $e): bool
    {
        $error = (array) Arr::get((array) $e->response->json(), 'error', []);
        $message = mb_strtolower((string) Arr::get($error, 'message', ''));

        return (int) Arr::get($error, 'code') === 10 && (str_contains($message, 'another app') || str_contains($message, 'ứng dụng khác') || str_contains($message, 'ung dung khac'));
    }

    /** Kiểm tra lỗi có thuộc nhóm upload attachment thất bại hay không. */
    private function isUploadError(RequestException $e): bool
    {
        $error = (array) Arr::get((array) $e->response->json(), 'error', []);
        $message = mb_strtolower((string) Arr::get($error, 'message', ''));

        return (int) Arr::get($error, 'code') === 100 && ((int) Arr::get($error, 'error_subcode') === 2018047 || str_contains($message, 'could not upload') || str_contains($message, 'không thể tải file') || str_contains($message, 'khong the tai file'));
    }

    /** Chuẩn hóa thông tin ngoại lệ để ghi log chẩn đoán an toàn. */
    private function exceptionContext(\Throwable $e): array
    {
        $context = ['error' => $e->getMessage()];
        if ($e instanceof RequestException && $e->response) {
            $context['status'] = $e->response->status();
            $context['body'] = mb_substr($e->response->body(), 0, 2000);
        }

        return $context;
    }
}
