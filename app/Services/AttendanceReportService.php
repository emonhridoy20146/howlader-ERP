<?php

namespace App\Services;

use App\Models\AttendanceRecord;
use App\Models\Employee;
use App\Models\HrDepartment;
use App\Models\Location;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

class AttendanceReportService
{
    /**
     * @return array{
     *     filters: array{year: int, month: int, department_id: string, location_id: string, q: string},
     *     periodLabel: string,
     *     periodStart: string,
     *     periodEnd: string,
     *     daysInMonth: int,
     *     dayDates: list<string>,
     *     departments: Collection<int, HrDepartment>,
     *     locations: Collection<int, Location>,
     *     rows: Collection<int, array{
     *         employee: Employee,
     *         cells: array<string, AttendanceRecord|null>,
     *         totals: array<string, float>,
     *         payable_days: float,
     *         unmarked_days: int
     *     }>,
     *     monthTotals: array<string, float>
     * }
     */
    public function monthlyReport(Request $request): array
    {
        $filters = $this->monthlyFilters($request);
        $periodStart = Carbon::createFromDate($filters['year'], $filters['month'], 1)->startOfDay();
        $periodEnd = $periodStart->copy()->endOfMonth();
        $dayDates = collect(range(1, $periodEnd->day))
            ->map(fn (int $day): string => $periodStart->copy()->day($day)->toDateString())
            ->all();

        $employees = $this->filteredEmployees($filters, $request->user());

        $recordsByEmployee = AttendanceRecord::query()
            ->whereBetween('attendance_date', [$periodStart->toDateString(), $periodEnd->toDateString()])
            ->whereIn('employee_id', $employees->pluck('id'))
            ->get()
            ->groupBy('employee_id')
            ->map(fn (Collection $group): Collection => $group->keyBy(
                fn (AttendanceRecord $record): string => $record->attendance_date->toDateString(),
            ));

        $monthTotals = array_fill_keys(array_keys(AttendanceRecord::statusOptions()), 0.0);
        $monthTotals['unmarked'] = 0.0;

        $rows = $employees->map(function (Employee $employee) use ($dayDates, $recordsByEmployee, &$monthTotals): array {
            $byDate = $recordsByEmployee->get($employee->id, collect());
            $cells = [];
            $totals = array_fill_keys(array_keys(AttendanceRecord::statusOptions()), 0.0);
            $payableDays = 0.0;
            $unmarkedDays = 0;

            foreach ($dayDates as $date) {
                /** @var AttendanceRecord|null $record */
                $record = $byDate->get($date);
                $cells[$date] = $record;

                if ($record instanceof AttendanceRecord) {
                    $totals[$record->status] = ($totals[$record->status] ?? 0) + 1;
                    $monthTotals[$record->status] = ($monthTotals[$record->status] ?? 0) + 1;
                    $payableDays += $record->payableDayWeight();
                } else {
                    $unmarkedDays++;
                    $monthTotals['unmarked'] = ($monthTotals['unmarked'] ?? 0) + 1;
                }
            }

            return [
                'employee' => $employee,
                'cells' => $cells,
                'totals' => $totals,
                'payable_days' => round($payableDays, 1),
                'unmarked_days' => $unmarkedDays,
            ];
        })->values();

        $departments = HrDepartment::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']);
        $locations = $this->accessibleLocations($request->user());

        return [
            'filters' => $filters,
            'periodLabel' => $periodStart->format('F Y'),
            'periodStart' => $periodStart->toDateString(),
            'periodEnd' => $periodEnd->toDateString(),
            'daysInMonth' => $periodEnd->day,
            'dayDates' => $dayDates,
            'departments' => $departments,
            'locations' => $locations,
            'rows' => $rows,
            'monthTotals' => $monthTotals,
        ];
    }

    /**
     * @return array{
     *     date: string,
     *     counts: array{total: int, present: int, absent: int, half_day: int, leave: int, holiday: int, weekend: int, unmarked: int, checked_in: int},
     *     rows: Collection<int, array{employee: Employee, record: AttendanceRecord|null, status: string, check_in: ?string, check_out: ?string, branch: string}>
     * }
     */
    public function todayDashboardSummary(?User $user, ?int $locationId = null): array
    {
        $date = now()->toDateString();

        $employees = Employee::query()
            ->with(['locations', 'department'])
            ->where('is_active', true)
            ->where('employment_status', 'active')
            ->visibleToUser($user, $locationId)
            ->orderBy('employee_code')
            ->get();

        $records = AttendanceRecord::query()
            ->whereDate('attendance_date', $date)
            ->whereIn('employee_id', $employees->pluck('id'))
            ->get()
            ->keyBy('employee_id');

        $counts = [
            'total' => $employees->count(),
            'present' => 0,
            'absent' => 0,
            'half_day' => 0,
            'leave' => 0,
            'holiday' => 0,
            'weekend' => 0,
            'unmarked' => 0,
            'checked_in' => 0,
        ];

        $rows = $employees->map(function (Employee $employee) use ($records, &$counts): array {
            /** @var AttendanceRecord|null $record */
            $record = $records->get($employee->id);
            $status = $record?->status ?? 'unmarked';

            if ($record) {
                $counts[$record->status] = ($counts[$record->status] ?? 0) + 1;
                if ($record->check_in) {
                    $counts['checked_in']++;
                }
            } else {
                $counts['unmarked']++;
            }

            return [
                'employee' => $employee,
                'record' => $record,
                'status' => $status,
                'check_in' => Employee::formatTimeValue($record?->check_in),
                'check_out' => Employee::formatTimeValue($record?->check_out),
                'branch' => $employee->branchNames(),
            ];
        })->values();

        return [
            'date' => $date,
            'counts' => $counts,
            'rows' => $rows,
        ];
    }

    /**
     * @return array{year: int, month: int, department_id: string, location_id: string, q: string}
     */
    public function monthlyFilters(Request $request): array
    {
        $year = (int) $request->input('year', now()->year);
        $month = (int) $request->input('month', now()->month);

        if ($year < 2000 || $year > 2100) {
            $year = now()->year;
        }
        if ($month < 1 || $month > 12) {
            $month = (int) now()->month;
        }

        return [
            'year' => $year,
            'month' => $month,
            'department_id' => (string) $request->input('department_id', ''),
            'location_id' => (string) $request->input('location_id', ''),
            'q' => trim((string) $request->input('q', '')),
        ];
    }

    /**
     * @param  array{year: int, month: int, department_id: string, location_id: string, q: string}  $filters
     * @return Collection<int, Employee>
     */
    private function filteredEmployees(array $filters, ?User $user): Collection
    {
        $locationId = $filters['location_id'] !== '' ? (int) $filters['location_id'] : null;

        return Employee::query()
            ->with(['department', 'locations'])
            ->where('is_active', true)
            ->where('employment_status', 'active')
            ->visibleToUser($user, $locationId)
            ->when($filters['department_id'] !== '', fn ($q) => $q->where('hr_department_id', $filters['department_id']))
            ->when($filters['q'] !== '', function ($query) use ($filters): void {
                $keyword = $filters['q'];
                $query->where(function ($sub) use ($keyword): void {
                    $sub->where('employee_code', 'like', "%{$keyword}%")
                        ->orWhere('first_name', 'like', "%{$keyword}%")
                        ->orWhere('last_name', 'like', "%{$keyword}%");
                });
            })
            ->orderBy('employee_code')
            ->get();
    }

    /**
     * @return Collection<int, Location>
     */
    private function accessibleLocations(?User $user): Collection
    {
        return Location::query()
            ->where('is_active', true)
            ->whereIn('type', ['branch', 'warehouse'])
            ->when(
                $user && ! $user->isAdmin() && ! $user->hasPermission('location.view-all'),
                fn ($q) => $q->whereIn('id', $user->accessibleLocationIds())
            )
            ->orderBy('name')
            ->get(['id', 'name']);
    }
}
