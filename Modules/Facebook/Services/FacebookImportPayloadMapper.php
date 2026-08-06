<?php

namespace Modules\Facebook\Services;

use Carbon\Carbon;
use Illuminate\Support\Arr;
use Modules\Facebook\Models\FacebookPage;

class FacebookImportPayloadMapper
{
    /** Nhập và đồng bộ dữ liệu hội thoại Facebook tại bước participant. */
    public function participant(FacebookPage $page, array $message, array $conversation = []): array
    {
        foreach ((array) Arr::get($conversation, 'participants.data', []) as $item) if ((string) Arr::get($item, 'id') !== $page->page_id) return $item;
        foreach ((array) Arr::get($message, 'to.data', []) as $item) if ((string) Arr::get($item, 'id') !== $page->page_id) return $item;
        return (string) Arr::get($message, 'from.id') !== $page->page_id ? (array) Arr::get($message, 'from', []) : [];
    }

    /** Chuyển attachment Graph API thành cấu trúc lưu trong message CRM. */
    public function attachments(array $message): array
    {
        return collect((array) Arr::get($message, 'attachments.data', []))->map(function (array $item): array {
            $url = (string) (Arr::get($item, 'image_data.url') ?: Arr::get($item, 'video_data.url') ?: Arr::get($item, 'file_url'));
            $mime = (string) Arr::get($item, 'mime_type', '');
            $type = match (true) {
                Arr::has($item, 'image_data.url'), str_starts_with($mime, 'image/') => 'image',
                Arr::has($item, 'video_data.url'), str_starts_with($mime, 'video/') => 'video',
                str_starts_with($mime, 'audio/') => 'audio', default => (string) Arr::get($item, 'type', 'file'),
            };
            return ['name' => (string) Arr::get($item, 'name', basename((string) parse_url($url, PHP_URL_PATH))),
                'url' => $url, 'type' => $type, 'mime_type' => $mime, 'payload' => $item];
        })->filter(fn (array $item): bool => $item['url'] !== '')->unique('url')->values()->all();
    }

    /** Trích xuất và chuẩn hóa số điện thoại từ nội dung đầu vào. */
    public function phone(string $content): ?string
    {
        preg_match_all('/(?:\+?84|0)(?:[\s.\-()]?\d){8,10}/', $content, $matches);
        foreach ($matches[0] ?? [] as $candidate) {
            $value = preg_replace('/\D+/', '', $candidate) ?: '';
            if (str_starts_with($value, '84')) $value = '0'.substr($value, 2);
            if (preg_match('/^0\d{8,10}$/', $value)) return $value;
        }
        return null;
    }

    /** Chọn thời gian tạo từ message hoặc conversation và chuyển thành Carbon. */
    public function createdAt(array $message, array $conversation = []): Carbon
    {
        $value = (string) (Arr::get($message, 'created_time') ?: Arr::get($conversation, 'updated_time') ?: '');
        return $value === '' ? now() : Carbon::parse($value)->timezone(config('app.timezone'));
    }
}
