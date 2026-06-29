<?php

namespace Modules\Conversation\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Tag extends Model
{
    public const DEFAULT_CONSULTING = 'Đang Tư Vấn';
    public const DEFAULT_WAITING = 'Khách Đợi';

    public const DEFAULTS = [
        self::DEFAULT_CONSULTING => '#e11d48',
        self::DEFAULT_WAITING => '#f59e0b',
    ];

    protected $fillable = ['name', 'color', 'is_default'];

    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
        ];
    }

    public static function ensureDefaults(): void
    {
        foreach (self::DEFAULTS as $name => $color) {
            $tag = self::query()->firstOrCreate(
                ['name' => $name],
                ['color' => $color],
            );

            if (! $tag->is_default) {
                $tag->forceFill(['is_default' => true])->save();
            }
        }
    }

    public function conversations(): BelongsToMany
    {
        return $this->belongsToMany(Conversation::class);
    }
}
