<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DemandForecast extends Model
{
    use HasFactory;

    protected $fillable = [
        'product_id', 'location_id', 'forecast_month', 'predicted_qty', 'actual_qty', 'algorithm', 'generated_at',
    ];

    protected function casts(): array
    {
        return [
            'forecast_month' => 'date',
            'generated_at' => 'datetime',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }
}
