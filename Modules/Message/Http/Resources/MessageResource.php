<?php

namespace Modules\Message\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Conversation\Support\ConversationStatus;

class MessageResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'conversation_id' => $this->conversation_id,
            'conversation_url' => route('crm.conversations.show', $this->conversation_id),
            'conversation_customer_name' => $this->conversation?->customer?->name,
            'conversation_customer_avatar' => $this->conversation?->customer?->avatar,
            'conversation_customer_phone' => $this->conversation?->customer?->phone,
            'conversation_assigned_to' => $this->conversation?->assigned_to ? (int) $this->conversation->assigned_to : null,
            'conversation_unread_messages_count' => (int) ($this->conversation?->unread_messages_count ?? 0),
            'conversation_is_unread' => (int) ($this->conversation?->unread_messages_count ?? 0) > 0,
            'conversation_last_message_at' => $this->conversation?->last_message_at?->toISOString() ?: $this->created_at?->toISOString(),
            'conversation_status' => $this->conversationStatusPayload(),
            'conversation_tags' => $this->conversation?->tags
                ?->map(fn ($tag): array => [
                    'id' => (int) $tag->id,
                    'name' => $tag->name,
                    'color' => $tag->color,
                ])
                ->values()
                ->all() ?? [],
            'sender_type' => $this->sender_type,
            'sender_id' => $this->sender_id,
            'sender_name' => $this->sender_type === 'customer'
                ? (string) ($this->conversation?->customer?->name ?? $this->senderName())
                : $this->senderName(),
            'sender_avatar' => $this->sender_type === 'customer'
                ? $this->conversation?->customer?->avatar
                : null,
            'channel' => $this->channel,
            'content' => $this->recalled_at ? null : $this->content,
            'message_type' => $this->message_type,
            'attachments' => $this->recalled_at ? [] : $this->normalizedAttachments(),
            'external_message_id' => $this->external_message_id,
            'client_message_id' => $this->client_message_id,
            'outbound_status' => $this->outbound_status,
            'outbound_error' => $this->outbound_error,
            'status' => $this->outbound_status && $this->outbound_status !== 'sent' ? $this->outbound_status : null,
            'is_recalled' => (bool) $this->recalled_at,
            'facebook_recalled' => false,
            'recalled_at' => $this->recalled_at?->toISOString(),
            'created_at' => $this->created_at?->toISOString(),
        ];
    }

    private function conversationStatusPayload(): array
    {
        $status = ConversationStatus::normalize($this->conversation?->status);

        return match ($status) {
            ConversationStatus::IN_PROGRESS => [
                'key' => ConversationStatus::IN_PROGRESS,
                'name' => 'Đang tư vấn',
                'color' => '#e11d48',
            ],
            ConversationStatus::RESOLVED => [
                'key' => ConversationStatus::RESOLVED,
                'name' => 'Đã xử lý',
                'color' => '#16a34a',
            ],
            ConversationStatus::CLOSED => [
                'key' => ConversationStatus::CLOSED,
                'name' => 'Đã đóng',
                'color' => '#64748b',
            ],
            ConversationStatus::REOPENED => [
                'key' => ConversationStatus::REOPENED,
                'name' => 'Mở lại',
                'color' => '#7c3aed',
            ],
            default => [
                'key' => ConversationStatus::WAITING,
                'name' => 'Khách đợi',
                'color' => '#f59e0b',
            ],
        };
    }

    private function normalizedAttachments(): array
    {
        return collect($this->attachments ?? [])
            ->reject(fn (array $attachment): bool => ($attachment['type'] ?? '') === 'quick_reply')
            ->map(function (array $attachment): array {
                $mimeType = (string) data_get($attachment, 'mime_type', '');
                $type = (string) data_get($attachment, 'type', '');
                $url = (string) (
                    data_get($attachment, 'payload.image_data.url')
                    ?: data_get($attachment, 'payload.video_data.url')
                    ?: data_get($attachment, 'payload.audio_data.url')
                    ?: data_get($attachment, 'url')
                    ?: data_get($attachment, 'payload.url')
                    ?: data_get($attachment, 'payload.file_url')
                );
                $type = $this->attachmentType($mimeType, $type, $attachment);
                $name = (string) data_get($attachment, 'name', '');

                if ($name === '' && $url !== '') {
                    $name = basename((string) parse_url($url, PHP_URL_PATH));
                }

                return array_merge($attachment, [
                    'name' => $name ?: ucfirst($type),
                    'url' => $url,
                    'type' => $type,
                ]);
            })
            ->filter(fn (array $attachment): bool => $attachment['url'] !== '')
            ->unique('url')
            ->values()
            ->all();
    }

    private function attachmentType(string $mimeType, string $type = '', array $attachment = []): string
    {
        return match (true) {
            data_get($attachment, 'payload.image_data.url') !== null => 'image',
            data_get($attachment, 'payload.video_data.url') !== null => 'video',
            data_get($attachment, 'payload.audio_data.url') !== null => 'audio',
            str_starts_with($mimeType, 'image/') => 'image',
            str_starts_with($mimeType, 'video/') => 'video',
            str_starts_with($mimeType, 'audio/') => 'audio',
            $type !== '' => $type,
            default => 'file',
        };
    }
}
