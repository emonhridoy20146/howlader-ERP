<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;

class SalesInvoice extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'dizicashier_uuid',
        'invoice_number', 'invoice_date', 'branch_id', 'customer_id', 'sale_type', 'status', 'subtotal',
        'payment_status', 'discount_total', 'tax_total', 'grand_total', 'amount_paid', 'amount_due', 'notes', 'pdf_path',
        'created_by', 'referrer_id', 'posted_by', 'posted_at',
        'credit_due_date', 'installment_down_payment', 'installment_count', 'installment_first_due_date',
        'installment_frequency', 'installment_custom_days', 'installment_penalty_enabled', 'installment_penalty_rate',
        'guarantor_name', 'guarantor_address', 'guarantor_phone_numbers', 'guarantor_notes', 'guarantor_nid_number',
        'guarantor_id', 'customer_nid_path', 'guarantor_nid_path', 'verification_attachments',
    ];

    protected function casts(): array
    {
        return [
            'invoice_date' => 'date',
            'posted_at' => 'datetime',
            'deleted_at' => 'datetime',
            'credit_due_date' => 'date',
            'installment_first_due_date' => 'date',
            'installment_penalty_enabled' => 'boolean',
            'guarantor_phone_numbers' => 'array',
            'verification_attachments' => 'array',
        ];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(SalesInvoiceLine::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function guarantor(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'guarantor_id');
    }

    public function customerNidUrl(): ?string
    {
        if ($this->customer_nid_path === null || $this->customer_nid_path === '') {
            return null;
        }

        return \Illuminate\Support\Facades\Storage::disk('public')->url($this->customer_nid_path);
    }

    public function guarantorNidUrl(): ?string
    {
        if ($this->guarantor_nid_path === null || $this->guarantor_nid_path === '') {
            return null;
        }

        return \Illuminate\Support\Facades\Storage::disk('public')->url($this->guarantor_nid_path);
    }

    /**
     * Guarantor line for collection reports: invoice guarantor first, else customer default guarantor.
     *
     * @return list<string>
     */
    public function collectionGuarantorDisplayParts(): array
    {
        if ($this->hasAssignedInvoiceGuarantor()) {
            $details = $this->invoiceGuarantorDetails();
            if ($details !== null) {
                return self::guarantorDetailsDisplayParts($details);
            }
        }

        $defaultDetails = $this->customerDefaultGuarantorDetails();
        if ($defaultDetails !== null) {
            return self::guarantorDetailsDisplayParts($defaultDetails);
        }

        return [];
    }

    public function collectionGuarantorDisplayText(): string
    {
        $parts = $this->collectionGuarantorDisplayParts();

        return $parts === [] ? '—' : implode(' · ', $parts);
    }

    /**
     * @return array{name: ?string, address: ?string, phone_numbers: array<int, string>, notes: ?string}|null
     */
    private function invoiceGuarantorDetails(): ?array
    {
        if ($this->guarantor_id) {
            $guarantor = $this->relationLoaded('guarantor')
                ? $this->guarantor
                : $this->guarantor()->first();
            if ($guarantor instanceof Customer) {
                return [
                    'name' => $guarantor->name,
                    'address' => $guarantor->address,
                    'phone_numbers' => $guarantor->normalizedPhoneNumbers(),
                    'notes' => $this->guarantor_notes,
                ];
            }
        }

        if (
            filled($this->guarantor_name)
            || filled($this->guarantor_address)
            || (is_array($this->guarantor_phone_numbers) && $this->guarantor_phone_numbers !== [])
        ) {
            return [
                'name' => $this->guarantor_name,
                'address' => $this->guarantor_address,
                'phone_numbers' => is_array($this->guarantor_phone_numbers) ? $this->guarantor_phone_numbers : [],
                'notes' => $this->guarantor_notes,
            ];
        }

        return null;
    }

    private function hasAssignedInvoiceGuarantor(): bool
    {
        if ($this->guarantor_id) {
            return (int) $this->guarantor_id !== (int) $this->customer_id;
        }

        if (! filled($this->guarantor_name)) {
            return false;
        }

        $buyer = $this->buyerCustomer();
        if ($buyer instanceof Customer) {
            return strcasecmp(trim((string) $this->guarantor_name), trim($buyer->name)) !== 0;
        }

        return true;
    }

    private function buyerCustomer(): ?Customer
    {
        if ($this->relationLoaded('customer')) {
            return $this->customer;
        }

        if ($this->customer_id) {
            return Customer::query()->find($this->customer_id);
        }

        return null;
    }

    /**
     * @return array{name: ?string, address: ?string, phone_numbers: array<int, string>, notes: ?string}|null
     */
    private function customerDefaultGuarantorDetails(): ?array
    {
        $buyer = $this->buyerCustomer();
        if (! $buyer instanceof Customer) {
            return null;
        }

        $defaultGuarantor = $buyer->relationLoaded('defaultGuarantor')
            ? $buyer->defaultGuarantor
            : $buyer->defaultGuarantor()->first();

        if (! $defaultGuarantor instanceof Customer) {
            return null;
        }

        if ((int) $defaultGuarantor->id === (int) $buyer->id) {
            return null;
        }

        return [
            'name' => $defaultGuarantor->name,
            'address' => $defaultGuarantor->address,
            'phone_numbers' => $defaultGuarantor->normalizedPhoneNumbers(),
            'notes' => null,
        ];
    }

    /**
     * @param  array{name: ?string, address: ?string, phone_numbers: array<int, string>, notes: ?string}  $details
     * @return list<string>
     */
    private static function guarantorDetailsDisplayParts(array $details): array
    {
        return array_values(array_filter([
            filled($details['name']) ? 'Name: '.$details['name'] : null,
            filled($details['address']) ? 'Addr: '.$details['address'] : null,
            $details['phone_numbers'] !== [] ? 'Tel: '.implode(', ', $details['phone_numbers']) : null,
            filled($details['notes']) ? 'Note: '.Str::limit((string) $details['notes'], 100) : null,
        ]));
    }


    public function branch(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'branch_id');
    }

    public function installmentPlan(): HasOne
    {
        return $this->hasOne(InstallmentPlan::class);
    }

    public function payments(): BelongsToMany
    {
        return $this->belongsToMany(Payment::class, 'payment_allocations', 'sales_invoice_id', 'payment_id')
            ->withPivot(['id', 'allocated_amount', 'settlement_discount']);
    }

    public function paymentAllocations(): HasMany
    {
        return $this->hasMany(PaymentAllocation::class, 'sales_invoice_id');
    }

    /** Payment allocation rows with a live inbound payment record (excludes orphaned pivots). */
    public function recordedInboundPaymentAllocations(): HasMany
    {
        return $this->paymentAllocations()->forRecordedInboundPayment();
    }

    public function totalAllocatedPaymentAmount(): float
    {
        return round((float) $this->recordedInboundPaymentAllocations()
            ->selectRaw('COALESCE(SUM(allocated_amount), 0) + COALESCE(SUM(settlement_discount), 0) as total')
            ->value('total'), 2);
    }

    /** Cash collected from the customer (excludes settlement discounts). */
    public function totalCashCollected(): float
    {
        return round((float) $this->recordedInboundPaymentAllocations()
            ->selectRaw('COALESCE(SUM(allocated_amount), 0) as total')
            ->value('total'), 2);
    }

    /** Settlement discounts applied when recording payments (not invoice line discounts). */
    public function totalSettlementDiscountApplied(): float
    {
        return round((float) $this->recordedInboundPaymentAllocations()
            ->selectRaw('COALESCE(SUM(settlement_discount), 0) as total')
            ->value('total'), 2);
    }

    /**
     * Imported invoices (e.g. DiziCashier S-*) may have header collections not stored as allocation rows.
     *
     * @return array{cash: float, discount: float, total: float}
     */
    public function headerPaymentBaseline(): array
    {
        // Header amount_paid includes bad-debt write-offs; exclude them so they are not shown as cash.
        $writeoff = round((float) $this->debtWriteoffs()->sum('amount'), 2);

        $cashFromAlloc = $this->totalCashCollected();
        $discountFromAlloc = $this->totalSettlementDiscountApplied();
        $recordedTotal = round($cashFromAlloc + $discountFromAlloc, 2);
        $grandTotal = round((float) $this->grand_total, 2);
        $amountDue = round(max(0, (float) $this->amount_due), 2);
        $headerSettled = round(max(0, $grandTotal - $amountDue - $writeoff), 2);

        if ($headerSettled <= $recordedTotal + 0.009) {
            return ['cash' => 0.0, 'discount' => 0.0, 'total' => 0.0];
        }

        $baselineTotal = round($headerSettled - $recordedTotal, 2);
        $baselineCash = round(min($baselineTotal, max(0, $headerSettled - $cashFromAlloc)), 2);
        $baselineDiscount = round(max(0, $baselineTotal - $baselineCash), 2);

        return [
            'cash' => $baselineCash,
            'discount' => $baselineDiscount,
            'total' => round($baselineCash + $baselineDiscount, 2),
        ];
    }

    /** Cash collected for invoice summary cards — includes imported header collections. */
    public function displayCashCollected(): float
    {
        return round($this->totalCashCollected() + $this->headerPaymentBaseline()['cash'], 2);
    }

    /** Settlement discount for invoice summary cards — includes imported header collections. */
    public function displaySettlementDiscountApplied(): float
    {
        return round($this->totalSettlementDiscountApplied() + $this->headerPaymentBaseline()['discount'], 2);
    }

    /** Cash + settlement discount applied via payment allocation rows. */
    public function totalAppliedFromAllocations(): float
    {
        return round($this->totalCashCollected() + $this->totalSettlementDiscountApplied(), 2);
    }

    /** Sum of allocation rows tied to inbound payment records (matches the Transactions list). */
    public function totalRecordedInboundPaymentAmount(): float
    {
        return $this->totalAllocatedPaymentAmount();
    }

    public function hasInboundPaymentTransactions(): bool
    {
        return $this->recordedInboundPaymentAllocations()->exists();
    }

    /** Paid on the invoice header but not yet stored as payment allocation rows. */
    public function unrecordedPaymentAmount(): float
    {
        $recorded = $this->totalRecordedInboundPaymentAmount();
        $grandTotal = round((float) $this->grand_total, 2);
        $amountPaid = round((float) $this->amount_paid, 2);
        $amountDue = round((float) $this->amount_due, 2);

        if ($this->allowsAdminHistoricalPaymentBackfill()) {
            return max(0, round($grandTotal - $recorded, 2));
        }

        if ($amountDue > 0.009) {
            return max(0, round($amountDue, 2));
        }

        if ($amountPaid > 0.009) {
            return max(0, round($amountPaid - $recorded, 2));
        }

        if ($amountDue <= 0.009 && ($this->payment_status === 'paid' || $recorded + 0.009 < $grandTotal)) {
            return max(0, round($grandTotal - $recorded, 2));
        }

        return 0.0;
    }

    public function allowsAdminHistoricalPaymentBackfill(): bool
    {
        if (! in_array($this->status, ['posted', 'active'], true)) {
            return false;
        }

        $grandTotal = round((float) $this->grand_total, 2);
        if ($grandTotal <= 0.009) {
            return false;
        }

        if (round((float) $this->amount_due, 2) > 0.009) {
            return false;
        }

        return ! $this->hasInboundPaymentTransactions();
    }

    public function maxHistoricalPaymentAmount(): float
    {
        return $this->unrecordedPaymentAmount();
    }

    public function allowsAdminHistoricalPayment(): bool
    {
        if (! in_array($this->status, ['posted', 'active'], true)) {
            return false;
        }

        return $this->maxHistoricalPaymentAmount() > 0.009;
    }

    public function historicalPaymentUnavailableReason(): ?string
    {
        if (! in_array($this->status, ['posted', 'active'], true)) {
            return 'Historical payments are only available on posted or active invoices (this invoice is '.$this->status.').';
        }

        if ($this->maxHistoricalPaymentAmount() > 0.009) {
            return null;
        }

        $grandTotal = round((float) $this->grand_total, 2);

        if ($grandTotal <= 0.009) {
            return 'This invoice has no balance to record as a payment.';
        }

        if ($this->hasInboundPaymentTransactions()) {
            return 'All payments are already recorded on this invoice.';
        }

        if (round((float) $this->amount_due, 2) > 0.009) {
            return 'No collectible balance is available for a historical payment.';
        }

        return 'This invoice cannot accept a historical payment in its current state.';
    }

    public function isDizicashierImport(): bool
    {
        return trim((string) ($this->dizicashier_uuid ?? '')) !== '';
    }

    public function scopeNativeSales(Builder $query): Builder
    {
        return $query->where(function (Builder $q): void {
            $q->whereNull('dizicashier_uuid')
                ->orWhere('dizicashier_uuid', '');
        });
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function referrer(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'referrer_id');
    }

    public function returnOrders(): HasMany
    {
        return $this->hasMany(ReturnOrder::class, 'sales_invoice_id')
            ->orderByDesc('return_date')
            ->orderByDesc('id');
    }

    public function returnRefunds(): HasMany
    {
        return $this->hasMany(ReturnRefund::class);
    }

    /** Cash refunded to the customer for this invoice (excludes refunds of rolled-back returns). */
    public function totalRefundedAmount(): float
    {
        return round((float) $this->returnRefunds()
            ->whereHas('returnOrder', fn (Builder $q) => $q->whereNull('rolled_back_at'))
            ->sum('paid_amount'), 2);
    }

    public function debtWriteoffs(): HasMany
    {
        return $this->hasMany(InvoiceDebtWriteoff::class)->orderByDesc('id');
    }

    public function isPayable(): bool
    {
        return in_array($this->status, ['posted', 'active'], true) && $this->payment_status !== 'paid';
    }

    /**
     * Cash/default sales must be settled in full when recording payment (no partial collection).
     */
    public function requiresFullPaymentOnCollection(): bool
    {
        return $this->sale_type === 'default';
    }

    /**
     * Due date used by monthly collection and overdue receivables reports.
     */
    public function collectionDueDate(): ?Carbon
    {
        return match ($this->sale_type) {
            'credit' => $this->credit_due_date,
            'default' => $this->invoice_date,
            default => null,
        };
    }

    public function creditMonthsLate(?Carbon $asOf = null): int
    {
        $dueDate = $this->collectionDueDate();
        if (! $dueDate) {
            return 0;
        }

        $asOfDate = ($asOf ?? Carbon::today())->copy()->startOfDay();
        $dueDate = $dueDate->copy()->startOfDay();

        if ($dueDate->gte($asOfDate)) {
            return 0;
        }

        return (int) $dueDate->diffInMonths($asOfDate);
    }

    /**
     * Due date of this invoice's last scheduled installment ("plan maturity date"),
     * i.e. when the whole plan — not just one schedule — should have been settled.
     */
    public function finalInstallmentDueDate(): ?Carbon
    {
        $plan = $this->relationLoaded('installmentPlan') ? $this->installmentPlan : $this->installmentPlan()->first();
        if (! $plan) {
            return null;
        }

        $schedules = $plan->relationLoaded('schedules') ? $plan->schedules : $plan->schedules()->get();

        return $schedules->sortByDesc('installment_no')->first()?->due_date;
    }

    /** Months since the whole installment plan matured (last installment due date), if overdue. */
    public function installmentMaturityMonthsLate(?Carbon $asOf = null): int
    {
        $dueDate = $this->finalInstallmentDueDate();
        if (! $dueDate) {
            return 0;
        }

        $asOfDate = ($asOf ?? Carbon::today())->copy()->startOfDay();
        $dueDate = $dueDate->copy()->startOfDay();

        if ($dueDate->gte($asOfDate)) {
            return 0;
        }

        return (int) $dueDate->diffInMonths($asOfDate);
    }

    /** Cash and credit invoices only — installment totals are tracked via schedules and collections. */
    public function scopeExcludingInstallmentSales(Builder $query): Builder
    {
        return $query->where('sale_type', '!=', 'installment');
    }

    /** Posted cash/credit sales and active installment invoices count toward revenue. */
    public function scopeCountedAsPostedSale(Builder $query): Builder
    {
        return $query->whereIn('status', ['posted', 'active']);
    }

    /** Exclude invoices whose items were fully returned (including legacy rows still marked posted). */
    public function scopeExcludingFullyReturned(Builder $query): Builder
    {
        return $query->whereNot(function (Builder $q): void {
            $q->where('status', 'returned')
                ->orWhere(fn (Builder $fullyReturned) => $fullyReturned->havingAllItemsReturned());
        });
    }

    /** Invoices where every line item has been returned through approved/refunded return orders. */
    public function scopeHavingAllItemsReturned(Builder $query): Builder
    {
        return $query->whereHas('lines')
            ->whereDoesntHave('lines', function (Builder $lineQuery): void {
                $lineQuery->where(function (Builder $line): void {
                    $line->whereHas('saleLineSerials', fn (Builder $serialQuery) => $serialQuery->whereNull('released_at'))
                        ->orWhere(function (Builder $nonSerial): void {
                            $nonSerial->whereDoesntHave('saleLineSerials')
                                ->whereRaw(
                                    '(SELECT COALESCE(SUM(rl.qty), 0) FROM return_lines rl INNER JOIN return_orders ro ON ro.id = rl.return_order_id WHERE rl.sales_invoice_line_id = sales_invoice_lines.id AND ro.status IN (?, ?) AND ro.rolled_back_at IS NULL) < sales_invoice_lines.qty',
                                    ['approved', 'refunded']
                                );
                        });
                });
            });
    }

    public function isFullyReturned(): bool
    {
        $this->loadMissing(['lines.saleLineSerials', 'returnOrders.returnLines']);

        if ($this->lines->isEmpty()) {
            return false;
        }

        foreach ($this->lines as $line) {
            if (! $this->lineIsFullyReturned($line)) {
                return false;
            }
        }

        return true;
    }

    public function refreshReturnedStatus(): void
    {
        if (! in_array($this->status, ['posted', 'active', 'returned'], true)) {
            return;
        }

        if ($this->isFullyReturned()) {
            if ($this->status !== 'returned') {
                $this->update(['status' => 'returned']);
            }

            return;
        }

        if ($this->status === 'returned') {
            $this->update(['status' => $this->sale_type === 'installment' ? 'active' : 'posted']);
        }
    }

    private function activeReturnOrders()
    {
        return $this->returnOrders
            ->filter(fn (ReturnOrder $returnOrder): bool => in_array($returnOrder->status, ['approved', 'refunded'], true)
                && $returnOrder->rolled_back_at === null);
    }

    private function lineIsFullyReturned(SalesInvoiceLine $line): bool
    {
        if ($line->saleLineSerials->isNotEmpty()) {
            return $line->saleLineSerials->every(fn (SaleLineSerial $serial): bool => $serial->released_at !== null);
        }

        $returnedQty = $this->activeReturnOrders()
            ->flatMap(fn (ReturnOrder $returnOrder) => $returnOrder->returnLines)
            ->where('sales_invoice_line_id', $line->id)
            ->sum('qty');

        return (int) $returnedQty >= (int) $line->qty;
    }
}
