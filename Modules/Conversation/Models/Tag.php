<?php

namespace Modules\Conversation\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Tag extends Model
{
    public const DEFAULT_CONSULTING = 'Đang Tư Vấn';
    public const DEFAULT_WAITING = 'Khách Đợi';

    public const DEFAULT_TEST_DRIVE = 'Lái Thử';
    public const DEFAULT_PHONE = 'Đã có SĐT';
    public const DEFAULT_QUOTE = 'Báo Giá';
    public const DEFAULT_INSTALLMENT = 'Trả Góp';
    public const DEFAULT_APPOINTMENT = 'Đặt Lịch';

    public const DEFAULTS = [
        self::DEFAULT_TEST_DRIVE => '#16a34a',
        self::DEFAULT_PHONE => '#0f766e',
        self::DEFAULT_QUOTE => '#2563eb',
        self::DEFAULT_INSTALLMENT => '#7c3aed',
        self::DEFAULT_APPOINTMENT => '#f97316',
    ];

    public const LEGACY_STATUS_TAGS = [
        self::DEFAULT_CONSULTING,
        self::DEFAULT_WAITING,
        'Đang Tư Vấn',
        'Khách Đang Đợi Tư Vấn',
        'Khách Đang Đợi Tư Vấn',
    ];

    protected $fillable = ['name', 'color', 'is_default'];

    /** Chuyển is_default thành boolean để bảo vệ nhãn mặc định. */
    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
        ];
    }

    /** Tạo hoặc cập nhật các nhãn mặc định bắt buộc và đánh dấu chúng không thể xóa. */
    public static function ensureDefaults(): void
    {
        foreach (self::DEFAULTS as $name => $color) {
            $tag = self::query()->firstOrCreate(
                ['name' => $name],
                ['color' => $color],
            );

            if (! $tag->is_default || $tag->color !== $color) {
                $tag->forceFill([
                    'color' => $color,
                    'is_default' => true,
                ])->save();
            }
        }
    }

    /** Liên kết nhiều-nhiều nhãn với các hội thoại được phân loại. */
    public function conversations(): BelongsToMany
    {
        return $this->belongsToMany(Conversation::class);
    }
}
