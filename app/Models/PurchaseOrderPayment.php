<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PurchaseOrderPayment extends Model
{
    protected $fillable = [
        'purchase_order_id',
        'wallet_id',
        'amount',
        'settlement_discount',
        'paid_at',
        'reference',
        'notes',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'paid_at' => 'date',
            'amount' => 'decimal:2',
            'settlement_discount' => 'decimal:2',
        ];
    }

    public function totalApplied(): float
    {
        return round((float) $this->amount + (float) $this->settlement_discount, 2);
    }

    /** Negative cash amount = excess refunded back into a company wallet. */
    public function isRefund(): bool
    {
        return (float) $this->amount < -0.009;
    }

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function wallet(): BelongsTo
    {
        return $this->belongsTo(Wallet::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
