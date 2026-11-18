<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SalesInvoiceLine extends Model
{
    use HasFactory;

    protected $fillable = [
        'sales_invoice_id', 'product_id', 'qty', 'unit_price', 'discount_type', 'discount_input', 'discount_amount', 'service_charge_type', 'service_charge_input', 'service_charge', 'tax_amount', 'line_total', 'warranty_months_snapshot',
    ];

    protected function casts(): array
    {
        return [
            'discount_input' => 'decimal:2',
            'discount_amount' => 'decimal:2',
            'service_charge_input' => 'decimal:2',
            'service_charge' => 'decimal:2',
            'tax_amount' => 'decimal:2',
            'line_total' => 'decimal:2',
            'unit_price' => 'decimal:2',
        ];
    }

    public function saleLineSerials(): HasMany
    {
        return $this->hasMany(SaleLineSerial::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * Returns are picked by serial only when the product is serialized AND this sale line actually has
     * serials recorded. Old/imported invoices for serialized products often have none, so those lines
     * are returned by quantity instead.
     */
    public function isReturnedBySerial(): bool
    {
        return (bool) ($this->product?->is_serialized ?? true) && $this->saleLineSerials->isNotEmpty();
    }

    public function salesInvoice(): BelongsTo
    {
        return $this->belongsTo(SalesInvoice::class, 'sales_invoice_id');
    }

    public function lineGrossBeforeDiscount(): float
    {
        return round(max(0, (int) $this->qty * (float) $this->unit_price), 2);
    }

    public function discountPercent(): ?float
    {
        $discount = max(0, (float) ($this->discount_amount ?? 0));
        if ($discount <= 0.0001) {
            return null;
        }

        if ($this->discount_type === 'percent' && $this->discount_input !== null && (float) $this->discount_input > 0) {
            return round((float) $this->discount_input, 2);
        }

        $gross = $this->lineGrossBeforeDiscount();
        if ($gross <= 0) {
            return null;
        }

        return round(($discount / $gross) * 100, 2);
    }

    public function formattedDiscountPercent(): ?string
    {
        $percent = $this->discountPercent();
        if ($percent === null) {
            return null;
        }

        return rtrim(rtrim(number_format($percent, 2, '.', ''), '0'), '.');
    }
}
