<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SaleLineSerial extends Model
{
    use HasFactory;

    protected $fillable = [
        'sales_invoice_line_id', 'serial_item_id', 'released_at',
    ];

    protected function casts(): array
    {
        return [
            'released_at' => 'datetime',
        ];
    }

    public function serialItem(): BelongsTo
    {
        return $this->belongsTo(SerialItem::class);
    }
}
