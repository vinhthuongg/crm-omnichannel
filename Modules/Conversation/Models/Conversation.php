<?php

namespace Modules\Conversation\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Customer\Models\Customer;
use Modules\Message\Models\Message;

class Conversation extends Model
{
    protected $fillable = ['customer_id', 'facebook_page_id', 'external_conversation_id', 'assigned_to', 'work_shift_id', 'claimed_at', 'status', 'last_message_at', 'unread_messages_count', 'last_read_at', 'closed_at'];

    protected function casts(): array
    {
        return ['last_message_at' => 'datetime', 'last_read_at' => 'datetime', 'claimed_at' => 'datetime', 'closed_at' => 'datetime'];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function workShift(): BelongsTo
    {
        return $this->belongsTo(WorkShift::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }

    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class);
    }

    public function markAsRead(): void
    {
        $this->messages()
            ->where('sender_type', 'customer')
            ->whereNull('read_at')
            ->update(['read_at' => now()]);

        $this->forceFill([
            'unread_messages_count' => 0,
            'last_read_at' => now(),
        ])->save();
    }

    public function incrementUnreadMessages(): void
    {
        $this->increment('unread_messages_count');
        $this->refresh();
    }
}
