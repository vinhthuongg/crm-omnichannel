<?php

namespace Modules\Message\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;

class MessengerAttachmentStorage
{
    public function store(UploadedFile $file): array
    {
        $mimeType = (string) $file->getMimeType();
        $path = $file->storeAs(
            'messages/'.now()->format('Y/m'),
            Str::uuid().'.'.$file->getClientOriginalExtension(),
            'public',
        );
        $publicPath = 'storage/'.str_replace('\\', '/', $path);

        return [
            'name' => $file->getClientOriginalName(),
            'path' => $path,
            'url' => $this->publicUrl($publicPath),
            'mime_type' => $mimeType,
            'type' => $this->attachmentType($mimeType),
            'size' => $file->getSize(),
        ];
    }

    private function attachmentType(string $mimeType): string
    {
        return match (true) {
            str_starts_with($mimeType, 'image/') => 'image',
            str_starts_with($mimeType, 'video/') => 'video',
            str_starts_with($mimeType, 'audio/') => 'audio',
            default => 'file',
        };
    }

    private function publicUrl(string $path): string
    {
        $path = ltrim($path, '/');
        $request = request();
        $scheme = trim(explode(',', (string) ($request->headers->get('x-forwarded-proto') ?: $request->getScheme()))[0]);
        $host = trim(explode(',', (string) ($request->headers->get('x-forwarded-host') ?: $request->getHost()))[0]);

        if ($host) {
            return "{$scheme}://{$host}/{$path}";
        }

        return url($path);
    }
}
