<?php

namespace Modules\Customer\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Conversation\Models\Conversation;

class Customer extends Model
{
    protected $fillable = [
        'name',
        'avatar',
        'phone',
        'email',
        'is_potential',
        'potential_marked_at',
        'potential_marked_by',
    ];

    protected $casts = [
        'is_potential' => 'boolean',
        'potential_marked_at' => 'datetime',
    ];

    public function channels(): HasMany
    {
        return $this->hasMany(CustomerChannel::class);
    }

    public function conversations(): HasMany
    {
        return $this->hasMany(Conversation::class);
    }

    public function notes(): HasMany
    {
        return $this->hasMany(CustomerNote::class);
    }

    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(CustomerTag::class, 'customer_customer_tag');
    }

    public function potentialMarkedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'potential_marked_by');
    }
}
