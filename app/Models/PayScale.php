<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PayScale extends Model
{
    protected $fillable = [
        'name',
        'code',
        'basic_salary',
        'house_rent_allowance',
        'medical_allowance',
        'transport_allowance',
        'other_allowance',
        'gross_salary',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'basic_salary' => 'decimal:2',
            'house_rent_allowance' => 'decimal:2',
            'medical_allowance' => 'decimal:2',
            'transport_allowance' => 'decimal:2',
            'other_allowance' => 'decimal:2',
            'gross_salary' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(EmployeePayScaleAssignment::class);
    }

    public static function computeGross(array $components): float
    {
        return round(
            (float) ($components['basic_salary'] ?? 0)
            + (float) ($components['house_rent_allowance'] ?? 0)
            + (float) ($components['medical_allowance'] ?? 0)
            + (float) ($components['transport_allowance'] ?? 0)
            + (float) ($components['other_allowance'] ?? 0),
            2
        );
    }
}
