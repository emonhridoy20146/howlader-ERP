<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

class PurchaseOrder extends Model
{
    use HasFactory;

    protected $fillable = [
        'dizicashier_uuid',
        'po_number', 'supplier_id', 'destination_location_id', 'status', 'ordered_at', 'expected_at',
        'notes', 'subtotal_amount', 'discount_amount', 'tax_amount', 'additional_cost_amount', 'additional_costs', 'total_amount',
        'created_by', 'approved_by', 'approved_at',
    ];

    protected function casts(): array
    {
        return [
            'ordered_at' => 'date',
            'expected_at' => 'date',
            'approved_at' => 'datetime',
            'subtotal_amount' => 'decimal:2',
            'discount_amount' => 'decimal:2',
            'tax_amount' => 'decimal:2',
            'additional_cost_amount' => 'decimal:2',
            'additional_costs' => 'array',
            'total_amount' => 'decimal:2',
        ];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(PurchaseOrderLine::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(PurchaseOrderPayment::class);
    }

    /** @param  Builder<PurchaseOrder>  $query */
    public function scopeWithSettledPaymentsSum(Builder $query): Builder
    {
        return $query->addSelect([
            'payments_sum_amount' => PurchaseOrderPayment::query()
                ->selectRaw('COALESCE(SUM(amount + COALESCE(settlement_discount, 0)), 0)')
                ->whereColumn('purchase_order_id', 'purchase_orders.id'),
        ]);
    }

    public function changeLogs(): HasMany
    {
        return $this->hasMany(PurchaseOrderChangeLog::class)->orderByDesc('id');
    }

    public function costPostings(): HasMany
    {
        return $this->hasMany(PurchaseOrderCostPosting::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function destinationLocation(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'destination_location_id');
    }

    /**
     * Net supplier payments applied (cash + settlement discount − excess refunds).
     */
    public function supplierPaymentsTotal(): float
    {
        if ($this->relationLoaded('payments')) {
            return round((float) $this->payments->sum(
                fn (PurchaseOrderPayment $payment): float => $payment->totalApplied(),
            ), 2);
        }

        return round((float) $this->payments()
            ->selectRaw('COALESCE(SUM(amount + COALESCE(settlement_discount, 0)), 0) as total')
            ->value('total'), 2);
    }

    /**
     * Total settled toward the PO grand total: supplier payments plus additional costs
     * (extras are paid from wallet when recorded and included in grand total).
     */
    public function amountPaid(): float
    {
        return round($this->supplierPaymentsTotal() + (float) $this->additional_cost_amount, 2);
    }

    /**
     * Remaining balance on the PO grand total (total_amount).
     */
    public function amountDue(): float
    {
        return round(max(0, (float) $this->total_amount - $this->amountPaid()), 2);
    }

    /** Amount paid toward this PO in excess of the current grand total (supplier credit). */
    public function supplierCreditBalance(): float
    {
        return round(max(0, $this->amountPaid() - (float) $this->total_amount), 2);
    }

    /**
     * Spread the PO grand total across lines in proportion to each line's merchandise subtotal
     * (same ratio as line_total ÷ sum(line_total), so discount/tax/additional costs follow line value).
     *
     * @return array<int, array{landed_line_total: float, landed_unit_cost: float, landed_received_total: float}>
     */
    public function landedLineAllocations(): array
    {
        $lines = $this->relationLoaded('lines')
            ? $this->lines->sortBy('id')->values()
            : $this->lines()->orderBy('id')->get();

        if ($lines->isEmpty()) {
            return [];
        }

        $grandTotal = max(0.0, round((float) $this->total_amount, 2));
        $totalCents = (int) round($grandTotal * 100);

        $weights = [];
        foreach ($lines as $line) {
            $weights[$line->id] = max(0.0, round((float) $line->line_total, 2));
        }

        $sumLines = round((float) array_sum($weights), 2);
        $subtotal = max(0.0, round((float) $this->subtotal_amount, 2));
        $denom = $sumLines > 0.0 ? $sumLines : $subtotal;

        if ($denom <= 0.0) {
            return $this->landedEqualSplitAllocations($lines, $totalCents);
        }

        $baseCents = [];
        $fractions = [];
        foreach ($lines as $line) {
            $w = $weights[$line->id];
            $exact = $totalCents * ($w / $denom);
            $base = (int) floor($exact + 1e-9);
            $baseCents[$line->id] = $base;
            $fractions[$line->id] = $exact - $base;
        }

        $remainder = $totalCents - (int) array_sum($baseCents);
        arsort($fractions);
        foreach (array_keys($fractions) as $lineId) {
            if ($remainder <= 0) {
                break;
            }
            $baseCents[$lineId]++;
            $remainder--;
        }

        $out = [];
        foreach ($lines as $line) {
            $landed = ($baseCents[$line->id] ?? 0) / 100.0;
            $qty = max(0, (int) $line->qty_ordered);
            $received = max(0, (int) $line->qty_received);
            $landedUnit = $qty > 0 ? round($landed / $qty, 2) : 0.0;
            $landedReceived = $qty > 0 ? round($landed * ($received / $qty), 2) : 0.0;

            $out[$line->id] = [
                'landed_line_total' => round($landed, 2),
                'landed_unit_cost' => $landedUnit,
                'landed_received_total' => $landedReceived,
            ];
        }

        return $out;
    }

    /**
     * @param  Collection<int, PurchaseOrderLine>  $lines
     * @return array<int, array{landed_line_total: float, landed_unit_cost: float, landed_received_total: float}>
     */
    private function landedEqualSplitAllocations(Collection $lines, int $totalCents): array
    {
        $n = $lines->count();
        if ($n === 0) {
            return [];
        }

        $per = intdiv($totalCents, $n);
        $rem = $totalCents % $n;
        $out = [];
        $i = 0;
        foreach ($lines as $line) {
            $cents = $per + ($i < $rem ? 1 : 0);
            $i++;
            $landed = $cents / 100.0;
            $qty = max(0, (int) $line->qty_ordered);
            $received = max(0, (int) $line->qty_received);
            $landedUnit = $qty > 0 ? round($landed / $qty, 2) : 0.0;
            $landedReceived = $qty > 0 ? round($landed * ($received / $qty), 2) : 0.0;

            $out[$line->id] = [
                'landed_line_total' => round($landed, 2),
                'landed_unit_cost' => $landedUnit,
                'landed_received_total' => $landedReceived,
            ];
        }

        return $out;
    }
}
