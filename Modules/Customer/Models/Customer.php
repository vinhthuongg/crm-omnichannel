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
        'phone_collected_at',
        'email',
        'is_potential',
        'potential_marked_at',
        'potential_marked_by',
    ];

    protected $casts = [
        'phone_collected_at' => 'datetime',
        'is_potential' => 'boolean',
        'potential_marked_at' => 'datetime',
    ];

    /** Đăng ký các hook model sau khi Customer hoàn tất khởi động. */
    protected static function booted(): void
    {
        static::saving(function (Customer $customer): void {
            $phone = trim((string) $customer->phone);

            if ($phone === '') {
                $customer->phone_collected_at = null;

                return;
            }

            if (! $customer->phone_collected_at && $customer->isDirty('phone')) {
                $customer->phone_collected_at = now();
            }
        });
    }

    /** Liên kết khách hàng với các định danh liên hệ Facebook và Zalo. */
    public function channels(): HasMany
    {
        return $this->hasMany(CustomerChannel::class);
    }

    /** Liên kết toàn bộ hội thoại thuộc khách hàng. */
    public function conversations(): HasMany
    {
        return $this->hasMany(Conversation::class);
    }

    /** Liên kết các ghi chú nội bộ đã lưu cho khách hàng. */
    public function notes(): HasMany
    {
        return $this->hasMany(CustomerNote::class);
    }

    /** Liên kết nhiều-nhiều các nhãn phân loại khách hàng. */
    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(CustomerTag::class, 'customer_customer_tag');
    }

    /** Liên kết người dùng đã đánh dấu khách hàng là tiềm năng. */
    public function potentialMarkedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'potential_marked_by');
    }
}
