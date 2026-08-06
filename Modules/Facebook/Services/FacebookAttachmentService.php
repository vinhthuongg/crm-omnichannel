<?php

namespace Modules\Facebook\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class FacebookAttachmentService
{
    /** Khởi tạo dịch vụ attachment với HTTP client của Laravel. */
    public function __construct(private readonly Http $http)
    {
    }

    /** Gửi attachment từ URL và tự chuyển sang upload khi Facebook không đọc được URL. */
    public function sendUrl(string $recipientId, string $url, string $type, string $token): array
    {
        $this->validateType($type);
        if (! str_starts_with($url, 'https://')) throw new RuntimeException("Facebook attachment URL must be public HTTPS. Current URL: {$url}");
        try {
            $response = $this->request($token, 60)->post($this->url('/me/messages'), [
                'messaging_type' => 'RESPONSE', 'recipient' => ['id' => $recipientId],
                'message' => ['attachment' => ['type' => $type, 'payload' => ['url' => $url, 'is_reusable' => true]]],
            ]);
        } catch (ConnectionException $exception) {
            if (! str_contains($exception->getMessage(), 'cURL error 28')) throw $exception;
            Log::warning('Facebook attachment send timed out after request was sent', [
                'recipient_id' => $recipientId, 'attachment_type' => $type, 'attachment_url' => $url, 'error' => $exception->getMessage(),
            ]);
            return ['recipient_id' => $recipientId, 'message_id' => null, 'delivery_status' => 'sent_response_timeout'];
        }
        $response->throw();
        return $response->json();
    }

    /** Upload tệp local rồi gửi attachment ID nhận được đến người dùng. */
    public function sendLocal(string $recipientId, string $path, string $type, ?string $mime, ?string $filename, string $token): array
    {
        $this->validateType($type);
        if (! is_file($path)) throw new RuntimeException("Facebook attachment file does not exist: {$path}");
        [$uploadPath, $uploadMime, $remove] = $this->prepare($path, $type, $mime);
        try { $id = $this->upload($uploadPath, $type, $uploadMime, $filename, $token); }
        finally { if ($remove) @unlink($uploadPath); }
        return [...$this->sendId($recipientId, $id, $type, $token), 'facebook_attachment_id' => $id];
    }

    /** G?i attachment d� t?n t?i tr�n Facebook b?ng attachment ID. */
    public function sendId(string $recipientId, string $id, string $type, string $token): array
    {
        $this->validateType($type);
        $response = $this->request($token, 30)->post($this->url('/me/messages'), [
            'messaging_type' => 'RESPONSE', 'recipient' => ['id' => $recipientId],
            'message' => ['attachment' => ['type' => $type, 'payload' => ['attachment_id' => $id]]],
        ]);
        $response->throw();
        return $response->json();
    }

    /** Chuẩn bị tệp, MIME type và loại attachment trước khi upload. */
    private function prepare(string $path, string $type, ?string $mime): array
    {
        if ($type !== 'image' || $mime === 'image/gif') return [$path, $mime, false];
        $contents = @file_get_contents($path);
        $image = $contents !== false ? @imagecreatefromstring($contents) : false;
        if (! $image) return [$path, $mime, false];
        $width = imagesx($image); $height = imagesy($image); $ratio = min(1, 1280 / max($width, $height));
        $target = imagecreatetruecolor(max(1, (int) round($width * $ratio)), max(1, (int) round($height * $ratio)));
        imagefill($target, 0, 0, imagecolorallocate($target, 255, 255, 255));
        imagecopyresampled($target, $image, 0, 0, 0, 0, imagesx($target), imagesy($target), $width, $height);
        $tmp = tempnam(sys_get_temp_dir(), 'crm_fb_image_');
        if ($tmp === false) { imagedestroy($image); imagedestroy($target); return [$path, $mime, false]; }
        imagejpeg($target, $tmp, 82); imagedestroy($image); imagedestroy($target);
        return [$tmp, 'image/jpeg', true];
    }

    /** Upload binary lên Messenger Attachment API và trả về attachment ID. */
    private function upload(string $path, string $type, ?string $mime, ?string $filename, string $token): string
    {
        $handle = fopen($path, 'r');
        if ($handle === false) throw new RuntimeException("Cannot open Facebook attachment file: {$path}");
        try {
            $response = $this->request($token, 60)
                ->attach('filedata', $handle, $filename ?: basename($path), $mime ? ['Content-Type' => $mime] : [])
                ->post($this->url('/me/message_attachments'), ['message' => json_encode(['attachment' => ['type' => $type, 'payload' => ['is_reusable' => true]]])]);
        } finally { fclose($handle); }
        $response->throw();
        $id = data_get($response->json(), 'attachment_id');
        if (! $id) throw new RuntimeException('Facebook did not return an attachment_id.');
        Log::info('Facebook attachment uploaded', ['attachment_type' => $type, 'filename' => $filename ?: basename($path), 'attachment_id' => $id]);
        return (string) $id;
    }

    /** T?o HTTP request d� g?n access token v� timeout ph� h?p. */
    private function request(string $token, int $timeout)
    {
        return $this->http->connectTimeout(10)->timeout($timeout)->withToken($token);
    }

    /** Từ chối loại attachment không nằm trong danh sách Messenger hỗ trợ. */
    private function validateType(string $type): void
    {
        if (! in_array($type, ['image', 'audio', 'video', 'file'], true)) throw new RuntimeException("Unsupported Facebook attachment type [{$type}].");
    }

    /** Tạo URL Graph API đầy đủ cho endpoint attachment. */
    private function url(string $path): string
    {
        return 'https://graph.facebook.com/'.config('services.facebook.graph_version', 'v25.0').$path;
    }
}
