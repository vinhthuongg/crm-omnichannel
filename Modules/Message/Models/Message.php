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
}
