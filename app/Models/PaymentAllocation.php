<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PaymentAllocation extends Model
{
    use HasFactory;

    protected $fillable = [
        'payment_id', 'sales_invoice_id', 'allocated_amount', 'settlement_discount',
    ];

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    public function salesInvoice(): BelongsTo
    {
        return $this->belongsTo(SalesInvoice::class);
    }

    /** Allocations whose payment row still exists and is an inbound collection. */
    public function scopeForRecordedInboundPayment(Builder $query): Builder
    {
        return $query->whereHas('payment', fn (Builder $paymentQuery): Builder => $paymentQuery->where('direction', 'inbound'));
    }
}
