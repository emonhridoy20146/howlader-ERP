<?php

namespace App\Models;

use App\Support\SerialLabelCode;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SerialItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'product_id', 'serial_number', 'label_code', 'manufacturer_serial', 'serial_source', 'current_location_id', 'status', 'goods_receipt_line_id',
        'sale_invoice_line_id', 'warranty_start_date', 'warranty_end_date', 'purchase_cost', 'sale_price',
    ];

    protected function casts(): array
    {
        return [
            'label_code' => 'integer',
            'sale_price' => 'decimal:2',
            'warranty_start_date' => 'date',
            'warranty_end_date' => 'date',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (SerialItem $serial): void {
            if ($serial->label_code === null) {
                $serial->label_code = SerialLabelCode::generateUnique();
            }
        });
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'current_location_id');
    }

    public function goodsReceiptLine(): BelongsTo
    {
        return $this->belongsTo(GoodsReceiptLine::class);
    }

    public function saleInvoiceLine(): BelongsTo
    {
        return $this->belongsTo(SalesInvoiceLine::class, 'sale_invoice_line_id');
    }

    /** @return list<string> */
    public static function statusOptions(): array
    {
        return [
            'draft',
            'in_stock',
            'reserved',
            'sold',
            'returned',
            'damaged',
            'in_transfer',
            'under_service',
        ];
    }
}
