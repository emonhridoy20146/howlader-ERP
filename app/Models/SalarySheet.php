<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SalarySheet extends Model
{
    public const STATUS_DRAFT = 'draft';

    public const STATUS_FINALIZED = 'finalized';

    protected $fillable = [
        'period_year',
        'period_month',
        'title',
        'status',
        'total_gross',
        'total_deductions',
        'total_net',
        'generated_by',
        'finalized_by',
        'finalized_at',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'period_year' => 'integer',
            'period_month' => 'integer',
            'total_gross' => 'decimal:2',
            'total_deductions' => 'decimal:2',
            'total_net' => 'decimal:2',
            'finalized_at' => 'datetime',
        ];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(SalarySheetLine::class)->orderBy('employee_id');
    }

    public function generatedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'generated_by');
    }

    public function finalizedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'finalized_by');
    }

    public function periodLabel(): string
    {
        return \Carbon\Carbon::createFromDate($this->period_year, $this->period_month, 1)->format('F Y');
    }

    public function isDraft(): bool
    {
        return $this->status === self::STATUS_DRAFT;
    }
}
