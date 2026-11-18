<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class InstallmentPlan extends Model
{
    use HasFactory;

    protected $fillable = [
        'sales_invoice_id', 'customer_id', 'total_amount', 'down_payment', 'financed_amount', 'remaining_amount',
        'penalty_enabled', 'penalty_rate', 'reschedule_count', 'status', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'penalty_enabled' => 'boolean',
        ];
    }

    public function schedules(): HasMany
    {
        return $this->hasMany(InstallmentSchedule::class)->orderBy('installment_no');
    }

    /**
     * Schedules that still have a balance and can be chosen for a targeted (early) payment
     * (all earlier installments must already be settled).
     *
     * @return Collection<int, InstallmentSchedule>
     */
    public function schedulesEligibleForTargetedPayment()
    {
        $ordered = $this->schedules()->get();

        return $ordered->values()->filter(function (InstallmentSchedule $schedule, int $index) use ($ordered): bool {
            if ($schedule->remainingDue() < 0.01) {
                return false;
            }

            return $ordered->take($index)->every(fn (InstallmentSchedule $prior) => $prior->isFullyPaid());
        });
    }

    /**
     * Installment rows available in the payment register schedule picker.
     *
     * @return Collection<int, InstallmentSchedule>
     */
    public function schedulesSelectableForTargetedPayment(bool $allowAnySchedule = false): Collection
    {
        if ($allowAnySchedule) {
            return $this->schedules()->orderBy('installment_no')->get()->values();
        }

        return $this->schedulesEligibleForTargetedPayment();
    }

    public function salesInvoice(): BelongsTo
    {
        return $this->belongsTo(SalesInvoice::class, 'sales_invoice_id');
    }

    /**
     * Due date of the first installment — the anchor both planned and actual
     * duration are measured from. Assumes `schedules` is loaded.
     */
    public function plannedStartDate(): ?Carbon
    {
        return $this->schedules->sortBy('installment_no')->first()?->due_date;
    }

    /**
     * Due date of the last installment as currently scheduled (reflects any
     * reschedule). Assumes `schedules` is loaded.
     */
    public function plannedEndDate(): ?Carbon
    {
        return $this->schedules->sortByDesc('installment_no')->first()?->due_date;
    }

    /**
     * Span, in days, the installment schedule is spread over (first due date to
     * last due date).
     */
    public function plannedDurationDays(): ?int
    {
        $start = $this->plannedStartDate();
        $end = $this->plannedEndDate();

        if (! $start || ! $end) {
            return null;
        }

        return max(0, $start->diffInDays($end, false));
    }

    /**
     * Date of the payment that brought the plan to fully paid, i.e. the latest
     * `payment_date` among payments allocated to any of its schedules. Null
     * unless the plan is actually completed. Assumes `schedules.paymentAllocations.payment`
     * is loaded.
     */
    public function actualCompletionDate(): ?Carbon
    {
        if ($this->status !== 'completed') {
            return null;
        }

        $latest = $this->schedules
            ->flatMap(fn (InstallmentSchedule $schedule) => $schedule->paymentAllocations)
            ->map(fn (InstallmentPaymentAllocation $allocation) => $allocation->payment?->payment_date)
            ->filter()
            ->sort()
            ->last();

        return $latest instanceof Carbon ? $latest : ($latest ? Carbon::parse($latest) : null);
    }

    /**
     * How long the plan actually took to pay off (first due date to the date of
     * the final payment). Null while the plan is still active.
     */
    public function actualDurationDays(): ?int
    {
        $start = $this->plannedStartDate();
        $end = $this->actualCompletionDate();

        if (! $start || ! $end) {
            return null;
        }

        return $start->diffInDays($end, false);
    }

    /**
     * Actual duration minus planned duration: positive means it took longer
     * than scheduled, negative means it was paid off early. Null while active.
     */
    public function durationVarianceDays(): ?int
    {
        $planned = $this->plannedDurationDays();
        $actual = $this->actualDurationDays();

        if ($planned === null || $actual === null) {
            return null;
        }

        return $actual - $planned;
    }

    /**
     * Days elapsed since the schedule started (for plans still in progress).
     */
    public function elapsedDaysSinceStart(?Carbon $asOf = null): ?int
    {
        $start = $this->plannedStartDate();
        if (! $start) {
            return null;
        }

        $asOfDate = ($asOf ?? Carbon::today())->copy()->startOfDay();

        return $start->copy()->startOfDay()->diffInDays($asOfDate, false);
    }
}
