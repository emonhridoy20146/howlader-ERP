<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

class Product extends Model
{
    use HasFactory;

    protected static function booted(): void
    {
        static::saving(function (Product $product): void {
            $m = $product->model_number;
            if ($m === null) {
                return;
            }
            $trimmed = trim((string) $m);
            $product->model_number = $trimmed === '' ? null : $trimmed;
        });
    }

    protected $fillable = [
        'dizicashier_uuid',
        'sku',
        'barcode',
        'brand_id',
        'category_id',
        'name',
        'model_number',
        'description',
        'warranty_months',
        'default_purchase_price',
        'default_sale_price',
        'maximum_deductible',
        'reorder_level',
        'unit_of_measure',
        'is_serialized',
        'is_active',
        'photo_path',
    ];

    protected function casts(): array
    {
        return [
            'is_serialized' => 'boolean',
            'is_active' => 'boolean',
            'maximum_deductible' => 'decimal:2',
        ];
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function locationPrices(): HasMany
    {
        return $this->hasMany(LocationProductPrice::class);
    }

    public function inventoryBalances(): HasMany
    {
        return $this->hasMany(InventoryBalance::class);
    }

    public function photoUrl(): ?string
    {
        if ($this->photo_path === null || $this->photo_path === '') {
            return null;
        }

        return Storage::disk('public')->url($this->photo_path);
    }
}
