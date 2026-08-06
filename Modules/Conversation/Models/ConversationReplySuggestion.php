<?php

namespace Modules\Conversation\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Message\Models\Message;

class ConversationReplySuggestion extends Model
{
    protected $fillable = [
        'conversation_id',
        'message_id',
        'provider',
        'suggestions',
        'generated_at',
    ];

    /** Chuyển suggestions thành mảng và các mốc tạo/hết hạn thành datetime. */
    protected function casts(): array
    {
        return [
            'suggestions' => 'array',
            'generated_at' => 'datetime',
        ];
    }

    /** Liên kết bộ gợi ý với hội thoại dùng để tạo ngữ cảnh. */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    /** Liên kết bộ gợi ý với tin khách hàng làm nguồn sinh gợi ý. */
    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class);
    }
}
