<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Modules\Conversation\Models\Conversation;
use Modules\Facebook\Models\FacebookAccount;
use Modules\Facebook\Models\FacebookPage;
use Modules\Message\Models\Message;
use Modules\Conversation\Models\WorkShift;
use Modules\Notification\Models\FcmDeviceToken;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    use HasApiTokens;
    use HasRoles;
    use Notifiable;

    protected $fillable = ['name', 'email', 'password', 'is_active'];

    protected $hidden = ['password', 'remember_token'];

    /** Chuyển email_verified_at thành datetime và tự băm trường password khi lưu. */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
        ];
    }

    /** Khai báo các hội thoại hiện đang được giao cho người dùng xử lý. */
    public function assignedConversations(): HasMany
    {
        return $this->hasMany(Conversation::class, 'assigned_to');
    }

    /** Khai báo các tin nhắn do người dùng gửi trong hội thoại. */
    public function sentMessages(): HasMany
    {
        return $this->hasMany(Message::class, 'sender_id')->where('sender_type', 'user');
    }

    /** Khai báo các tài khoản Facebook mà người dùng đã kết nối. */
    public function facebookAccounts(): HasMany
    {
        return $this->hasMany(FacebookAccount::class);
    }

    /** Khai báo các Facebook Page thuộc quyền quản lý của người dùng. */
    public function facebookPages(): HasMany
    {
        return $this->hasMany(FacebookPage::class);
    }

    /** Khai báo quan hệ các ca trực mà người dùng được phân công. */
    public function workShifts()
    {
        return $this->belongsToMany(WorkShift::class, 'work_shift_user')->withTimestamps();
    }

    /** Khai báo các token thiết bị dùng để gửi push notification cho người dùng. */
    public function fcmDeviceTokens(): HasMany
    {
        return $this->hasMany(FcmDeviceToken::class);
    }
}
