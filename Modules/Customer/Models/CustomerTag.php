<?php

namespace Modules\Customer\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class CustomerTag extends Model
{
    protected $fillable = ['name', 'color'];

    public function customers(): BelongsToMany
    {
        return $this->belongsToMany(Customer::class, 'customer_customer_tag');
    }
}
