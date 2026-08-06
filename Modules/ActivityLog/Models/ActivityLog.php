<?php

namespace Modules\ActivityLog\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class ActivityLog extends Model
{
    protected $fillable = ['user_id', 'action', 'subject_type', 'subject_id', 'metadata'];

    /** Chuyển metadata và các giá trị thay đổi của activity log thành mảng khi đọc/ghi. */
    protected function casts(): array
    {
        return ['metadata' => 'array'];
    }

    /** Liên kết activity log với người dùng đã thực hiện thao tác. */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Liên kết đa hình tới model chịu tác động của activity log. */
    public function subject(): MorphTo
    {
        return $this->morphTo();
    }
}
