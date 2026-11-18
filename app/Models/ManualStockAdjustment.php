<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ManualStockAdjustment extends Model
{
    protected $fillable = [
        'product_id', 'location_id', 'qty_before', 'qty_after', 'qty_delta', 'reason', 'actor_user_id',
    ];

    protected function casts(): array
    {
        return [
            'qty_before' => 'integer',
            'qty_after' => 'integer',
            'qty_delta' => 'integer',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
