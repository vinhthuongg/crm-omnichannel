<?php

namespace Modules\Customer\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Conversation\Models\Conversation;

class Customer extends Model
{
    protected $fillable = ['name', 'avatar', 'phone', 'email'];

    public function channels(): HasMany
    {
        return $this->hasMany(CustomerChannel::class);
    }

    public function conversations(): HasMany
    {
        return $this->hasMany(Conversation::class);
    }
}