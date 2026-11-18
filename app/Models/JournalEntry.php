<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Collection;

class JournalEntry extends Model
{
    use HasFactory;

    /** @var list<string> */
    public const EDITABLE_SOURCES = [
        'manual_expense',
        'manual_income',
        'manual_transfer',
        'wallet_opening_balance',
    ];

    protected $fillable = [
        'branch_id',
        'entry_date',
        'document_no',
        'description',
        'source',
        'status',
        'reference_type',
        'reference_id',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'entry_date' => 'date',
        ];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'branch_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(JournalLine::class)->orderBy('id')->withoutGlobalScope('posted');
    }

    public function scopePosted($query)
    {
        return $query->where('status', 'posted');
    }

    public function scopeDraft($query)
    {
        return $query->where('status', 'draft');
    }

    public function reference(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Customer tied to this entry when the polymorphic reference is invoice/payment-related.
     */
    public function linkedCustomer(): ?Customer
    {
        $ref = $this->reference;

        return match (true) {
            $ref instanceof SalesInvoice => $ref->customer,
            $ref instanceof Payment => $ref->customer,
            $ref instanceof PaymentAllocation => $ref->payment?->customer ?? $ref->salesInvoice?->customer,
            $ref instanceof InvoiceDebtWriteoff => $ref->salesInvoice?->customer,
            default => null,
        };
    }

    /**
     * @param  Collection<int, JournalEntry>  $entries
     */
    public static function loadLinkedCustomerReferences(Collection $entries): void
    {
        if ($entries->isEmpty()) {
            return;
        }

        $entries->loadMorph('reference', [
            Payment::class => ['customer', 'paymentAllocations.salesInvoice'],
            PaymentAllocation::class => ['payment.customer', 'salesInvoice.customer'],
            SalesInvoice::class => ['customer'],
            InvoiceDebtWriteoff::class => ['salesInvoice.customer'],
        ]);
    }

    /**
     * @return array{invoice: SalesInvoice, allocation: PaymentAllocation}|null
     */
    public function linkedPaymentRemovalTarget(): ?array
    {
        if (! in_array($this->source, ['sales_payment', 'sales_payment_discount'], true)) {
            return null;
        }

        $allocation = match (true) {
            $this->source === 'sales_payment_discount' && $this->reference instanceof PaymentAllocation => $this->reference,
            $this->source === 'sales_payment_discount'
                && $this->reference_type === PaymentAllocation::class
                && $this->reference_id => PaymentAllocation::query()
                    ->with('salesInvoice')
                    ->find((int) $this->reference_id),
            $this->source === 'sales_payment' && $this->reference instanceof Payment => $this->reference->paymentAllocations->first(),
            $this->source === 'sales_payment'
                && $this->reference_type === Payment::class
                && $this->reference_id => PaymentAllocation::query()
                    ->with('salesInvoice')
                    ->where('payment_id', (int) $this->reference_id)
                    ->first(),
            default => null,
        };

        if (! $allocation instanceof PaymentAllocation) {
            return null;
        }

        if ($this->source === 'sales_payment' && $this->reference instanceof Payment) {
            if ($this->reference->paymentAllocations->count() !== 1) {
                return null;
            }
        } elseif ($this->source === 'sales_payment' && $this->reference_type === Payment::class && $this->reference_id) {
            $paymentId = (int) $this->reference_id;
            if (PaymentAllocation::query()->where('payment_id', $paymentId)->count() !== 1) {
                return null;
            }
        }

        $invoice = $allocation->salesInvoice;
        if (! $invoice instanceof SalesInvoice) {
            return null;
        }

        if (! in_array($invoice->status, ['posted', 'active'], true)) {
            return null;
        }

        return ['invoice' => $invoice, 'allocation' => $allocation];
    }

    public function isAdminPostedJournalDeleteOnly(): bool
    {
        return $this->status === 'posted'
            && ! $this->isEditableManualEntry()
            && $this->linkedPaymentRemovalTarget() === null;
    }

    public function isEditableManualEntry(): bool
    {
        return in_array($this->source, self::EDITABLE_SOURCES, true)
            && ($this->reference_type === null || $this->reference_type === '')
            && ($this->reference_id === null || (int) $this->reference_id === 0);
    }

    public static function sources(): array
    {
        return [
            'sales_invoice' => 'Sales invoice (GL)',
            'sales_payment' => 'Customer payment',
            'sales_payment_discount' => 'Payment settlement discount',
            'po_supplier_payment' => 'Supplier PO payment',
            'po_supplier_refund' => 'Supplier PO excess refund',
            'po_additional_cost' => 'PO additional cost (wallet)',
            'manual_expense' => 'Operating expense',
            'manual_income' => 'Other income',
            'manual_transfer' => 'Wallet transfer',
            'wallet_opening_balance' => 'Wallet opening balance',
            'manual_journal' => 'Manual journal',
            'return_settlement' => 'Return settlement',
            'return_settlement_reversal' => 'Return settlement reversal',
            'refund_payment' => 'Refund payment',
            'refund_payment_reversal' => 'Refund payment reversal',
        ];
    }
}
