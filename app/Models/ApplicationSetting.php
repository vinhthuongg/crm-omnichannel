<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ApplicationSetting extends Model
{
    public $incrementing = false;

    protected $primaryKey = 'key';

    protected $keyType = 'string';

    protected $fillable = ['key', 'value'];

    /** Chuyển giá trị JSON cấu hình thành mảng PHP khi đọc và ghi. */
    protected function casts(): array
    {
        return ['value' => 'array'];
    }
}
