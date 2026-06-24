<?php

namespace Modules\Facebook\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FacebookAccount extends Model
{
    protected $fillable = ['user_id', 'facebook_user_id', 'name', 'email', 'access_token', 'refresh_token'];

    protected $hidden = ['access_token', 'refresh_token'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
