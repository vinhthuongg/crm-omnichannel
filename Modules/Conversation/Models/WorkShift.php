<?php

namespace Modules\Conversation\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WorkShift extends Model
{
    protected $fillable = ['name', 'starts_at', 'ends_at', 'is_active'];

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'is_active' => 'boolean',
        ];
    }

    public function agents(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'work_shift_user')->withTimestamps();
    }

    public function conversations(): HasMany
    {
        return $this->hasMany(Conversation::class);
    }

    public function ownedConversations(): HasMany
    {
        return $this->hasMany(Conversation::class, 'owner_shift_id');
    }

    public function queuedConversations(): HasMany
    {
        return $this->hasMany(Conversation::class, 'queue_shift_id');
    }
}
