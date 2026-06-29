<?php

namespace Modules\Search\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class VectorSearchDocument extends Model
{
    protected $fillable = [
        'searchable_type',
        'searchable_id',
        'scope',
        'title',
        'content',
        'embedding',
        'embedding_provider',
        'embedding_model',
        'content_hash',
        'indexed_at',
    ];

    protected function casts(): array
    {
        return [
            'embedding' => 'array',
            'indexed_at' => 'datetime',
        ];
    }

    public function searchable(): MorphTo
    {
        return $this->morphTo();
    }
}
