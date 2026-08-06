<?php

namespace Modules\Customer\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomerNote extends Model
{
    protected $fillable = ['customer_id', 'user_id', 'body'];

    /** Liên kết ghi chú với khách hàng sở hữu ghi chú. */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /** Liên kết ghi chú với người dùng đã tạo nội dung. */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
