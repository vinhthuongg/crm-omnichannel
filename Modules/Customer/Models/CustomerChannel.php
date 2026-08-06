<?php

namespace Modules\Customer\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomerChannel extends Model
{
    protected $fillable = ['customer_id', 'channel', 'external_id', 'metadata'];

    /** Chuyển metadata riêng của kênh liên hệ thành mảng khi đọc/ghi. */
    protected function casts(): array
    {
        return ['metadata' => 'array'];
    }

    /** Liên kết channel Facebook/Zalo với khách hàng sở hữu external ID. */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }
}
