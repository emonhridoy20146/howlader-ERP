<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

class InstallmentSchedule extends Model
{
    use HasFactory;

    protected $fillable = [
        'installment_plan_id', 'installment_no', 'due_date', 'due_amount', 'paid_amount', 'late_fee_amount', 'status',
    ];

    protected function casts(): array
    {
        return [
            'due_date' => 'date',
        ];
    }

    public function installmentPlan(): BelongsTo
    {
        return $this->belongsTo(InstallmentPlan::class);
    }

    public function paymentAllocations(): HasMany
    {
        return $this->hasMany(InstallmentPaymentAllocation::class);
    }

    public function remainingDue(): float
    {
        $due = max(0, round((float) $this->due_amount + (float) $this->late_fee_amount, 2));
        $paid = max(0, round((float) $this->paid_amount, 2));

        return max(0, round($due - $paid, 2));
    }

    public function isFullyPaid(): bool
    {
        return $this->remainingDue() < 0.009;
    }

    public function monthsLate(?Carbon $asOf = null): int
    {
        if (! $this->due_date) {
            return 0;
        }

        $asOfDate = ($asOf ?? Carbon::today())->copy()->startOfDay();
        $dueDate = $this->due_date->copy()->startOfDay();

        if ($dueDate->gte($asOfDate)) {
            return 0;
        }

        return (int) $dueDate->diffInMonths($asOfDate);
    }

    public function computeStatus(): string
    {
        if ($this->isFullyPaid()) {
            return 'paid';
        }

        if ((float) $this->paid_amount > 0.009) {
            return 'partially_paid';
        }

        if ($this->due_date && $this->due_date->isPast()) {
            return 'overdue';
        }

        return 'pending';
    }
}
