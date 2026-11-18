<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class StockLedger extends Model
{
    use HasFactory;

    protected $table = 'stock_ledger';

    protected $fillable = [
        'event_time', 'product_id', 'serial_item_id', 'location_id', 'movement_type', 'quantity_delta',
        'unit_cost', 'reference_type', 'reference_id', 'actor_user_id',
    ];

    protected function casts(): array
    {
        return [
            'event_time' => 'datetime',
        ];
    }
}
