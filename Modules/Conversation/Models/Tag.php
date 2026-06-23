<?php

namespace Modules\Conversation\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Tag extends Model
{
    protected $fillable = ['name', 'color'];

    public function conversations(): BelongsToMany
    {
        return $this->belongsToMany(Conversation::class);
    }
}