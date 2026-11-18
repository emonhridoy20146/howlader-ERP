<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Employee extends Model
{
    protected $fillable = [
        'employee_code',
        'user_id',
        'hr_department_id',
        'hr_designation_id',
        'first_name',
        'last_name',
        'email',
        'phone',
        'gender',
        'date_of_birth',
        'joining_date',
        'termination_date',
        'employment_status',
        'check_in_time',
        'check_out_time',
        'bank_name',
        'bank_account',
        'notes',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'date_of_birth' => 'date',
            'joining_date' => 'date',
            'termination_date' => 'date',
            'is_active' => 'boolean',
        ];
    }

    public function fullName(): string
    {
        return trim($this->first_name.' '.($this->last_name ?? ''));
    }

    public function displayLabel(): string
    {
        return self::labelFromParts($this->employee_code, $this->first_name, $this->last_name);
    }

    public static function labelFromParts(?string $code, ?string $firstName, ?string $lastName): string
    {
        $name = trim(trim((string) $firstName).' '.trim((string) $lastName));

        return trim(collect([(string) $code, $name])->filter()->implode(' — '), ' —');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function locations(): BelongsToMany
    {
        return $this->belongsToMany(Location::class, 'employee_location');
    }

    public function branchNames(): string
    {
        $names = $this->relationLoaded('locations')
            ? $this->locations->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)->pluck('name')
            : $this->locations()->orderBy('name')->pluck('name');

        return $names->filter()->isNotEmpty()
            ? $names->filter()->implode(', ')
            : 'No branch';
    }

    public function formattedCheckInTime(): ?string
    {
        return self::formatTimeValue($this->check_in_time);
    }

    public function formattedCheckOutTime(): ?string
    {
        return self::formatTimeValue($this->check_out_time);
    }

    public static function formatTimeValue(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return substr((string) $value, 0, 5);
    }

    /**
     * Limit employees to a selected branch and/or the user's accessible locations.
     */
    public function scopeVisibleToUser(Builder $query, ?User $user, ?int $locationId = null): Builder
    {
        if ($locationId !== null) {
            return $query->whereHas(
                'locations',
                fn (Builder $locations) => $locations->where('locations.id', $locationId)
            );
        }

        if (! $user || $user->isAdmin() || $user->hasPermission('location.view-all')) {
            return $query;
        }

        $ids = $user->accessibleLocationIds();
        if ($ids === []) {
            return $query->whereRaw('0 = 1');
        }

        return $query->whereHas(
            'locations',
            fn (Builder $locations) => $locations->whereIn('locations.id', $ids)
        );
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(HrDepartment::class, 'hr_department_id');
    }

    public function designation(): BelongsTo
    {
        return $this->belongsTo(HrDesignation::class, 'hr_designation_id');
    }

    public function payScaleAssignments(): HasMany
    {
        return $this->hasMany(EmployeePayScaleAssignment::class)->orderByDesc('effective_from');
    }

    public function leaveRequests(): HasMany
    {
        return $this->hasMany(LeaveRequest::class)->orderByDesc('start_date');
    }

    public function attendanceRecords(): HasMany
    {
        return $this->hasMany(AttendanceRecord::class)->orderByDesc('attendance_date');
    }

    public function salarySheetLines(): HasMany
    {
        return $this->hasMany(SalarySheetLine::class);
    }

    public function currentPayScaleAssignment(?string $onDate = null): ?EmployeePayScaleAssignment
    {
        $date = $onDate ?? now()->toDateString();

        return $this->payScaleAssignments()
            ->with('payScale')
            ->where('effective_from', '<=', $date)
            ->where(function ($query) use ($date): void {
                $query->whereNull('effective_to')->orWhere('effective_to', '>=', $date);
            })
            ->orderByDesc('effective_from')
            ->first();
    }
}
