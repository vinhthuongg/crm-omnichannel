<?php

namespace App\Services\Chatbot;

use Modules\Message\Models\Message;

class ChatbotInboundMediaNormalizer
{
    /** Chuẩn hóa ảnh khách gửi thành payload tối thiểu, không chuyển metadata thô hoặc quick reply sang chatbot. */
    public function normalize(Message $message): array
    {
        return collect($message->attachments ?? [])
            ->map(fn ($attachment): ?array => $this->normalizeAttachment(is_array($attachment) ? $attachment : []))
            ->filter()
            ->unique('url')
            ->take(min(3, max(1, (int) config('chatbot.max_inbound_images', 3))))
            ->values()
            ->all();
    }

    /** Kiểm tra message có ít nhất một ảnh hợp lệ để quyết định xếp job Vision dù không có caption. */
    public function hasImages(Message $message): bool
    {
        return $this->normalize($message) !== [];
    }

    /** Tạo câu mô tả fallback bắt buộc cho API chatbot khi khách chỉ gửi ảnh. */
    public function fallbackMessage(int $imageCount): string
    {
        return $imageCount > 1
            ? "Khách hàng đã gửi {$imageCount} hình ảnh. Hãy phân tích các ảnh và phản hồi theo ngữ cảnh hội thoại."
            : 'Khách hàng đã gửi một hình ảnh. Hãy phân tích ảnh và phản hồi theo ngữ cảnh hội thoại.';
    }

    /** Chỉ nhận URL HTTPS của image/sticker và loại bỏ file, metadata hoặc URL nội bộ không an toàn. */
    private function normalizeAttachment(array $attachment): ?array
    {
        $type = strtolower((string) data_get($attachment, 'type', ''));
        $mime = strtolower((string) (data_get($attachment, 'mime_type') ?? data_get($attachment, 'mimeType') ?? ''));
        $url = (string) (
            data_get($attachment, 'url')
            ?? data_get($attachment, 'src')
            ?? data_get($attachment, 'payload.url')
            ?? data_get($attachment, 'payload.image_data.url')
            ?? ''
        );
        $allowedMimeTypes = ['image/jpeg', 'image/png', 'image/webp', 'image/avif'];
        $isImage = in_array($type, ['image', 'sticker'], true) || str_starts_with($mime, 'image/');

        if (! $isImage || ! in_array($mime, $allowedMimeTypes, true) || ! $this->isSafeUrl($url)) {
            return null;
        }

        $name = trim((string) (data_get($attachment, 'name') ?? data_get($attachment, 'filename') ?? ''));

        if ($name === '') {
            $name = basename((string) parse_url($url, PHP_URL_PATH)) ?: ($type === 'sticker' ? 'sticker' : 'image');
        }

        return array_filter([
            'type' => 'image',
            'url' => $url,
            'mimeType' => $mime,
            'name' => $name,
        ], fn ($value): bool => $value !== '');
    }

    /** Chỉ cho phép URL HTTPS công khai để chatbot không bị lợi dụng truy cập địa chỉ nội bộ. */
    private function isSafeUrl(string $url): bool
    {
        if (filter_var($url, FILTER_VALIDATE_URL) === false || strtolower((string) parse_url($url, PHP_URL_SCHEME)) !== 'https') {
            return false;
        }

        $host = strtolower((string) parse_url($url, PHP_URL_HOST));

        if ($host === '' || $host === 'localhost') {
            return false;
        }

        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
        }

        return true;
    }
}
