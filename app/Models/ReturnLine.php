<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReturnLine extends Model
{
    use HasFactory;

    protected $fillable = [
        'return_order_id',
        'sales_invoice_line_id',
        'serial_item_id',
        'qty',
        'resolution',
        'condition_notes',
    ];

    public function returnOrder(): BelongsTo
    {
        return $this->belongsTo(ReturnOrder::class);
    }

    public function salesInvoiceLine(): BelongsTo
    {
        return $this->belongsTo(SalesInvoiceLine::class);
    }

    public function serialItem(): BelongsTo
    {
        return $this->belongsTo(SerialItem::class);
    }
}
