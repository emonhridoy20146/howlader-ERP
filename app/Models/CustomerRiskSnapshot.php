<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomerRiskSnapshot extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'score' => 'float',
            'improving' => 'boolean',
            'has_guarantor' => 'boolean',
            'outstanding_amount' => 'float',
            'overdue_amount' => 'float',
            'avg_days_late' => 'float',
            'computed_at' => 'datetime',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /** Tailwind classes for the grade pill (A/B green, C amber, D/E red, N/A grey). */
    public static function gradeClasses(?string $grade): string
    {
        return match ($grade) {
            'A', 'B' => 'bg-emerald-100 text-emerald-800',
            'C' => 'bg-amber-100 text-amber-800',
            'D', 'E' => 'bg-rose-100 text-rose-800',
            default => 'bg-slate-100 text-slate-600',
        };
    }

    public function tooltip(): string
    {
        return sprintf(
            'Score %s · %s · computed %s',
            $this->score === null ? 'n/a' : number_format($this->score, 1),
            $this->classification === 'none' ? 'no late pattern' : $this->classification,
            $this->computed_at?->format('Y-m-d H:i') ?? '—',
        );
    }
}
