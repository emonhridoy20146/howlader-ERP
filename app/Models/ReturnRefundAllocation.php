<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReturnRefundAllocation extends Model
{
    use HasFactory;

    protected $fillable = [
        'return_refund_id',
        'payment_id',
        'allocated_amount',
    ];

    public function refund(): BelongsTo
    {
        return $this->belongsTo(ReturnRefund::class, 'return_refund_id');
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }
}
