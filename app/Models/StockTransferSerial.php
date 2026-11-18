<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockTransferSerial extends Model
{
    use HasFactory;

    protected $fillable = [
        'stock_transfer_id',
        'serial_item_id',
        'product_id',
        'status',
        'issued_at',
        'received_at',
    ];

    protected function casts(): array
    {
        return [
            'issued_at' => 'datetime',
            'received_at' => 'datetime',
        ];
    }

    public function transfer(): BelongsTo
    {
        return $this->belongsTo(StockTransfer::class, 'stock_transfer_id');
    }

    public function serialItem(): BelongsTo
    {
        return $this->belongsTo(SerialItem::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
