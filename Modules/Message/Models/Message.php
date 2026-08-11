<?php

namespace Modules\Message\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Conversation\Models\Conversation;

class Message extends Model
{
    use SoftDeletes;

    protected $fillable = ['conversation_id', 'sender_type', 'sender_id', 'channel', 'content', 'message_type', 'attachments', 'external_message_id', 'client_message_id', 'outbound_status', 'outbound_error', 'sent_at', 'read_at', 'recalled_at', 'recalled_by_user_id', 'deleted_by_user_id'];

    /** Chuyển attachment thành mảng và các mốc gửi, đọc, thu hồi, xóa thành datetime. */
    protected function casts(): array
    {
        return [
            'attachments' => 'array',
            'sent_at' => 'datetime',
            'read_at' => 'datetime',
            'recalled_at' => 'datetime',
            'deleted_at' => 'datetime',
        ];
    }

    /** Liên kết tin nhắn với hội thoại chứa nó. */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    /** Liên kết tin khách với lần sinh chatbot duy nhất mà nó kích hoạt. */
    public function chatbotResponse(): HasOne
    {
        return $this->hasOne(ChatbotResponse::class, 'source_message_id');
    }

    /** Liên kết đa hình tới người dùng, khách hàng hoặc system đã gửi tin. */
    public function sender(): MorphTo
    {
        return $this->morphTo(__FUNCTION__, 'sender_type', 'sender_id');
    }

    /** Trả tên hiển thị của sender hoặc nhãn hệ thống khi không có model sender. */
    public function senderName(): string
    {
        if ($this->sender_type === 'system') {
            return 'Bot';
        }

        if ($this->isMetaAgent()) {
            return 'Nhân viên Meta';
        }

        return (string) ($this->sender?->name ?? 'Unknown');
    }

    /** Xác định tin được nhân viên gửi trực tiếp từ Meta Business Suite dựa trên metadata echo. */
    public function isMetaAgent(): bool
    {
        return $this->sender_type === 'user' && $this->sender_id === null
            && collect($this->attachments ?? [])->contains(fn (array $attachment): bool => data_get($attachment, 'name') === 'facebook_echo'
                && (bool) data_get($attachment, 'payload.is_echo'));
    }

    /** Tạo nội dung xem trước ngắn gọn cho tin nhắn trong danh sách hội thoại. */
    public function conversationPreviewText(): string
    {
        $content = trim((string) $this->content);

        if ($content === '') {
            $content = $this->attachmentPreviewText() ?? 'Chưa có tin nhắn';
        }

        if ($this->recalled_at) {
            $content = 'Tin nhắn đã được thu hồi';
        }

        if ($this->message_type === 'whisper' || $this->channel === 'internal') {
            return 'Thì Thầm: '.$content;
        }

        if ($this->sender_type === 'user') {
            return 'Bạn: '.$content;
        }

        if ($this->sender_type === 'customer') {
            return 'Khách Hàng: '.$content;
        }

        return $content;
    }

    /** Tạo nhãn xem trước theo loại attachment đầu tiên khi tin không có text. */
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
            return '[Hình ảnh]';
        }

        if ($type === 'video' || substr($mimeType, 0, 6) === 'video/' || filled(data_get($payload, 'video_data.url'))) {
            return '[Video]';
        }

        if ($type === 'audio' || substr($mimeType, 0, 6) === 'audio/' || filled(data_get($payload, 'audio_data.url'))) {
            return '[Audio]';
        }

        return '[Tệp đính kèm]';
    }
}
