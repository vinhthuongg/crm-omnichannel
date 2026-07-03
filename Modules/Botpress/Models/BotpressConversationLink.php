<?php

namespace Modules\Botpress\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Conversation\Models\Conversation;

class BotpressConversationLink extends Model
{
    protected $fillable = [
        'conversation_id',
        'botpress_user_id',
        'botpress_user_key',
        'botpress_conversation_id',
        'last_botpress_message_id',
        'last_payload',
    ];

    protected function casts(): array
    {
        return [
            'last_payload' => 'array',
        ];
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }
}
