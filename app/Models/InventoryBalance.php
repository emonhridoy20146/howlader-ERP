<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InventoryBalance extends Model
{
    use HasFactory;

    public $incrementing = false;

    public $timestamps = false;

    protected $primaryKey = null;

    protected $fillable = [
        'product_id', 'location_id', 'on_hand_qty', 'reserved_qty', 'available_qty',
    ];

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }
}
