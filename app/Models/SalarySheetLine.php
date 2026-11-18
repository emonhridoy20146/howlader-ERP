<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SalarySheetLine extends Model
{
    protected $fillable = [
        'salary_sheet_id',
        'employee_id',
        'pay_scale_id',
        'days_in_month',
        'days_present',
        'days_absent',
        'leave_days',
        'basic_salary',
        'house_rent_allowance',
        'medical_allowance',
        'transport_allowance',
        'other_allowance',
        'gross_salary',
        'deductions',
        'net_salary',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'days_in_month' => 'integer',
            'days_present' => 'decimal:1',
            'days_absent' => 'decimal:1',
            'leave_days' => 'decimal:1',
            'basic_salary' => 'decimal:2',
            'house_rent_allowance' => 'decimal:2',
            'medical_allowance' => 'decimal:2',
            'transport_allowance' => 'decimal:2',
            'other_allowance' => 'decimal:2',
            'gross_salary' => 'decimal:2',
            'deductions' => 'decimal:2',
            'net_salary' => 'decimal:2',
        ];
    }

    public function salarySheet(): BelongsTo
    {
        return $this->belongsTo(SalarySheet::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function payScale(): BelongsTo
    {
        return $this->belongsTo(PayScale::class);
    }
}
