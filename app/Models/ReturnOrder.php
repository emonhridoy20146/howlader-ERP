<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class ReturnOrder extends Model
{
    use HasFactory;

    protected $fillable = [
        'return_number', 'sales_invoice_id', 'customer_id', 'branch_id', 'status', 'return_date', 'reason',
        'created_by', 'approved_by', 'approved_at', 'accepted_by', 'accepted_at',
        'refund_base_amount', 'refund_percentage', 'refund_settlement_amount',
        'refund_paid_amount', 'refund_due_amount', 'refund_status', 'resolution_notes', 'restocked_at',
        'rolled_back_at', 'rolled_back_by', 'rollback_notes',
    ];

    protected function casts(): array
    {
        return [
            'return_date' => 'date',
            'approved_at' => 'datetime',
            'accepted_at' => 'datetime',
            'restocked_at' => 'datetime',
            'rolled_back_at' => 'datetime',
        ];
    }

    public function isRolledBack(): bool
    {
        return $this->rolled_back_at !== null;
    }

    public function canRollback(): bool
    {
        if ($this->isRolledBack()) {
            return false;
        }

        if ($this->restocked_at === null) {
            return false;
        }

        return in_array($this->status, ['approved', 'refunded'], true);
    }

    public function salesInvoice(): BelongsTo
    {
        return $this->belongsTo(SalesInvoice::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'branch_id');
    }

    public function refund(): HasOne
    {
        return $this->hasOne(ReturnRefund::class);
    }

    public function returnLines(): HasMany
    {
        return $this->hasMany(ReturnLine::class);
    }

    /**
     * Amount still payable on this return (handles legacy partial_final rows with due_amount = 0).
     */
    public function refundAmountPayable(): float
    {
        $due = round((float) $this->refund_due_amount, 2);
        if ($due > 0) {
            return $due;
        }

        $settlement = round((float) $this->refund_settlement_amount, 2);
        $paid = round((float) $this->refund_paid_amount, 2);
        if ($settlement > 0 && $paid + 0.001 < $settlement) {
            return round($settlement - $paid, 2);
        }

        return 0.0;
    }

}
