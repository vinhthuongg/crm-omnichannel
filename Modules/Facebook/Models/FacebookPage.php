<?php

namespace Modules\Facebook\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FacebookPage extends Model
{
    protected $fillable = [
        'user_id',
        'facebook_user_id',
        'page_id',
        'page_name',
        'page_access_token',
        'page_avatar',
        'meta_app_id',
        'messenger_app_id',
        'subscribed_at',
        'token_expires_at',
        'token_status',
        'token_invalid_at',
        'token_last_error',
    ];

    protected $hidden = ['page_access_token'];

    protected $casts = [
        'subscribed_at' => 'datetime',
        'token_expires_at' => 'datetime',
        'token_invalid_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
