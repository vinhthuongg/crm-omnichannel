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
    protected $fillable = [
        'customer_id',
        'facebook_page_id',
        'external_conversation_id',
        'assigned_to',
        'assigned_by',
        'assigned_type',
        'work_shift_id',
        'owner_shift_id',
        'queue_shift_id',
        'claimed_at',
        'status',
        'last_message_at',
        'unread_messages_count',
        'automation_state',
        'last_read_at',
        'resolved_at',
        'first_response_at',
        'closed_at',
    ];

    protected function casts(): array
    {
        return [
            'last_message_at' => 'datetime',
            'last_read_at' => 'datetime',
            'claimed_at' => 'datetime',
            'resolved_at' => 'datetime',
            'first_response_at' => 'datetime',
            'closed_at' => 'datetime',
            'automation_state' => 'array',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function assignedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }

    public function workShift(): BelongsTo
    {
        return $this->belongsTo(WorkShift::class);
    }

    public function ownerShift(): BelongsTo
    {
        return $this->belongsTo(WorkShift::class, 'owner_shift_id');
    }

    public function queueShift(): BelongsTo
    {
        return $this->belongsTo(WorkShift::class, 'queue_shift_id');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }

    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class);
    }

    public function activities(): HasMany
    {
        return $this->hasMany(ConversationActivity::class);
    }

    public function handledUsers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'conversation_user_access')
            ->withPivot('first_handled_at')
            ->withTimestamps();
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
