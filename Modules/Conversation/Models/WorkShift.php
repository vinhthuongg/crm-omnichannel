<?php

namespace Modules\Conversation\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WorkShift extends Model
{
    protected $fillable = ['name', 'starts_at', 'ends_at', 'is_active'];

    /** Chuyển ngày làm việc thành mảng và trạng thái is_active thành boolean. */
    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'is_active' => 'boolean',
        ];
    }

    /** Liên kết nhiều-nhiều các nhân viên được phân vào ca trực. */
    public function agents(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'work_shift_user')->withTimestamps();
    }

    /** Liên kết các hội thoại được tiếp nhận trong ca trực. */
    public function conversations(): HasMany
    {
        return $this->hasMany(Conversation::class);
    }

    /** Liên kết các hội thoại có ca này là ca sở hữu ban đầu. */
    public function ownedConversations(): HasMany
    {
        return $this->hasMany(Conversation::class, 'owner_shift_id');
    }

    /** Liên kết các hội thoại đang chờ ca này tiếp nhận. */
    public function queuedConversations(): HasMany
    {
        return $this->hasMany(Conversation::class, 'queue_shift_id');
    }
}
