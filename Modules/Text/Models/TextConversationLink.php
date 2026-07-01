<?php

namespace Modules\Text\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Conversation\Models\Conversation;

class TextConversationLink extends Model
{
    protected $fillable = [
        'conversation_id',
        'text_chat_id',
        'text_thread_id',
        'text_customer_id',
        'facebook_page_id',
        'facebook_psid',
        'bot_paused_at',
        'bot_resume_due_at',
        'bot_resumed_at',
        'last_payload',
    ];

    protected function casts(): array
    {
        return [
            'bot_paused_at' => 'datetime',
            'bot_resume_due_at' => 'datetime',
            'bot_resumed_at' => 'datetime',
            'last_payload' => 'array',
        ];
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }
}
