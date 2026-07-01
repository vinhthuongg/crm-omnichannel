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

    protected function casts(): array
    {
        return [
            'suggestions' => 'array',
            'generated_at' => 'datetime',
        ];
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class);
    }
}
