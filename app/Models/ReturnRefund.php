<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ReturnRefund extends Model
{
    use HasFactory;

    protected $fillable = [
        'return_order_id',
        'customer_id',
        'sales_invoice_id',
        'obligation_amount',
        'paid_amount',
        'due_amount',
        'status',
        'notes',
    ];

    public function returnOrder(): BelongsTo
    {
        return $this->belongsTo(ReturnOrder::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function salesInvoice(): BelongsTo
    {
        return $this->belongsTo(SalesInvoice::class);
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(ReturnRefundAllocation::class);
    }
}
