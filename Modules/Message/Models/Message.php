<?php

namespace Modules\Message\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Conversation\Models\Conversation;

class Message extends Model
{
    use SoftDeletes;

    protected $fillable = ['conversation_id', 'sender_type', 'sender_id', 'channel', 'content', 'message_type', 'attachments', 'external_message_id', 'client_message_id', 'outbound_status', 'outbound_error', 'sent_at', 'recalled_at', 'recalled_by_user_id', 'deleted_by_user_id'];

    protected function casts(): array
    {
        return [
            'attachments' => 'array',
            'sent_at' => 'datetime',
            'recalled_at' => 'datetime',
            'deleted_at' => 'datetime',
        ];
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function sender(): MorphTo
    {
        return $this->morphTo(__FUNCTION__, 'sender_type', 'sender_id');
    }

    public function senderName(): string
    {
        if ($this->sender_type === 'system') {
            return 'System';
        }

        return (string) ($this->sender?->name ?? 'Unknown');
    }

    public function conversationPreviewText(): string
    {
        $content = trim((string) $this->content);

        if ($content === '') {
            $content = $this->attachmentPreviewText() ?? 'Chua co tin nhan';
        }

        if ($this->recalled_at) {
            $content = 'Tin nhan da duoc thu hoi';
        }

        if ($this->message_type === 'whisper' || $this->channel === 'internal') {
            return 'Thi tham: '.$content;
        }

        if ($this->sender_type === 'user') {
            return 'Ban: '.$content;
        }

        return $content;
    }

    private function attachmentPreviewText(): ?string
    {
        $attachment = collect($this->attachments ?? [])->first();

        if (! $attachment) {
            return null;
        }

        $type = strtolower((string) data_get($attachment, 'type', ''));
        $mimeType = strtolower((string) data_get($attachment, 'mime_type', ''));
        $payload = data_get($attachment, 'payload', []);

        if ($type === 'sticker' || filled(data_get($payload, 'sticker_id'))) {
            return '[Emoji]';
        }

        if ($type === 'image' || substr($mimeType, 0, 6) === 'image/' || filled(data_get($payload, 'image_data.url'))) {
            return '[Hinh anh]';
        }

        if ($type === 'video' || substr($mimeType, 0, 6) === 'video/' || filled(data_get($payload, 'video_data.url'))) {
            return '[Video]';
        }

        if ($type === 'audio' || substr($mimeType, 0, 6) === 'audio/' || filled(data_get($payload, 'audio_data.url'))) {
            return '[Audio]';
        }

        return '[Tep dinh kem]';
    }
}
