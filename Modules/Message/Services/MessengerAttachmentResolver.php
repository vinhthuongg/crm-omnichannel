<?php

namespace Modules\Message\Services;

class MessengerAttachmentResolver
{
    /** Nhận MessengerAttachmentStorage để lưu và đọc tệp. */
    public function __construct(private readonly MessengerAttachmentStorage $storage) {}

    /** Kết hợp attachment đã upload và file mới trong request thành danh sách gửi thống nhất. */
    public function resolve(array $uploaded, array $files): array
    {
        $normalized = collect($uploaded)->filter(fn (array $item): bool => str_starts_with((string) ($item['path'] ?? ''), 'messages/'))
            ->map(fn (array $item): array => ['name' => (string) ($item['name'] ?? 'Attachment'), 'path' => (string) ($item['path'] ?? ''),
                'url' => (string) ($item['url'] ?? ''), 'mime_type' => (string) ($item['mime_type'] ?? ''),
                'type' => (string) ($item['type'] ?? 'file'), 'size' => (int) ($item['size'] ?? 0),
                ...(! empty($item['facebook_attachment_id']) ? ['facebook_attachment_id' => (string) $item['facebook_attachment_id']] : [])])
            ->values()->all();
        $stored = collect($files)->filter()->map(fn ($file): array => $this->storage->store($file))->values()->all();
        return [...$normalized, ...$stored];
    }
}
