<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AttendanceRecord extends Model
{
    public const STATUS_PRESENT = 'present';

    public const STATUS_ABSENT = 'absent';

    public const STATUS_HALF_DAY = 'half_day';

    public const STATUS_LEAVE = 'leave';

    public const STATUS_HOLIDAY = 'holiday';

    public const STATUS_WEEKEND = 'weekend';

    protected $fillable = [
        'employee_id',
        'attendance_date',
        'status',
        'check_in',
        'check_out',
        'notes',
        'recorded_by',
    ];

    protected function casts(): array
    {
        return [
            'attendance_date' => 'date',
        ];
    }

    public static function statusOptions(): array
    {
        return [
            self::STATUS_PRESENT => 'Present',
            self::STATUS_ABSENT => 'Absent',
            self::STATUS_HALF_DAY => 'Half day',
            self::STATUS_LEAVE => 'Leave',
            self::STATUS_HOLIDAY => 'Holiday',
            self::STATUS_WEEKEND => 'Weekend',
        ];
    }

    public static function statusAbbreviations(): array
    {
        return [
            self::STATUS_PRESENT => 'P',
            self::STATUS_ABSENT => 'A',
            self::STATUS_HALF_DAY => 'HD',
            self::STATUS_LEAVE => 'L',
            self::STATUS_HOLIDAY => 'Ho',
            self::STATUS_WEEKEND => 'W',
        ];
    }

    public function statusAbbreviation(): string
    {
        return self::statusAbbreviations()[$this->status] ?? '—';
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function recordedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    public function payableDayWeight(): float
    {
        return match ($this->status) {
            self::STATUS_PRESENT => 1.0,
            self::STATUS_HALF_DAY => 0.5,
            self::STATUS_LEAVE => 1.0,
            self::STATUS_HOLIDAY => 1.0,
            default => 0.0,
        };
    }
}
