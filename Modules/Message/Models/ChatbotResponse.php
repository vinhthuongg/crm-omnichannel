<?php

namespace Modules\Message\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Conversation\Models\Conversation;

class ChatbotResponse extends Model
{
    protected $fillable = [
        'conversation_id', 'source_message_id', 'external_message_id', 'request_id',
        'chatbot_message_id', 'status', 'segments', 'error_code', 'error_message',
        'started_at', 'completed_at',
    ];

    /** Chuyển danh sách bubble và các mốc xử lý chatbot thành kiểu dữ liệu PHP phù hợp. */
    protected function casts(): array
    {
        return [
            'segments' => 'array',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    /** Liên kết lần sinh chatbot với hội thoại CRM ổn định của nó. */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    /** Liên kết lần sinh chatbot với đúng tin khách đã kích hoạt nó. */
    public function sourceMessage(): BelongsTo
    {
        return $this->belongsTo(Message::class, 'source_message_id');
    }
}
