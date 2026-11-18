<?php

namespace App\Http\Controllers;

use App\Models\AttendanceRecord;
use App\Models\Employee;
use App\Models\HrDepartment;
use App\Models\Location;
use App\Services\AttendanceReportService;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AttendanceController extends Controller
{
    public function __construct(
        private readonly AttendanceReportService $attendanceReport,
    ) {}

    public function index(Request $request): View
    {
        $user = $request->user();
        $date = $request->input('date', now()->toDateString());
        $filters = [
            'date' => $date,
            'department_id' => (string) $request->input('department_id', ''),
            'location_id' => (string) $request->input('location_id', ''),
            'q' => trim((string) $request->input('q', '')),
        ];

        $locationId = $filters['location_id'] !== '' ? (int) $filters['location_id'] : null;

        $employees = Employee::query()
            ->with(['department'])
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

        $records = AttendanceRecord::query()
            ->whereDate('attendance_date', $date)
            ->whereIn('employee_id', $employees->pluck('id'))
            ->get()
            ->keyBy('employee_id');

        $departments = HrDepartment::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']);
        $locations = Location::query()
            ->where('is_active', true)
            ->whereIn('type', ['branch', 'warehouse'])
            ->when(
                $user && ! $user->isAdmin() && ! $user->hasPermission('location.view-all'),
                fn ($q) => $q->whereIn('id', $user->accessibleLocationIds())
            )
            ->orderBy('name')
            ->get(['id', 'name']);

        $monthStart = Carbon::parse($date)->startOfMonth()->toDateString();
        $monthEnd = Carbon::parse($date)->endOfMonth()->toDateString();
        $monthStats = AttendanceRecord::query()
            ->selectRaw('status, count(*) as total')
            ->whereBetween('attendance_date', [$monthStart, $monthEnd])
            ->whereIn('employee_id', $employees->pluck('id'))
            ->groupBy('status')
            ->pluck('total', 'status');

        return view('hr.attendance.index', compact('employees', 'records', 'departments', 'locations', 'filters', 'monthStats'));
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'attendance_date' => ['required', 'date'],
            'records' => ['required', 'array'],
            'records.*.employee_id' => ['required', 'exists:employees,id'],
            'records.*.status' => ['required', 'in:'.implode(',', array_keys(AttendanceRecord::statusOptions()))],
            'records.*.check_in' => ['nullable', 'date_format:H:i'],
            'records.*.check_out' => ['nullable', 'date_format:H:i'],
            'records.*.notes' => ['nullable', 'string', 'max:500'],
        ]);

        $user = $request->user();
        $userId = $user?->id;
        $date = $data['attendance_date'];

        $allowedEmployeeIds = Employee::query()
            ->whereIn('id', collect($data['records'])->pluck('employee_id'))
            ->visibleToUser($user)
            ->pluck('id')
            ->all();

        foreach ($data['records'] as $row) {
            if (! in_array((int) $row['employee_id'], $allowedEmployeeIds, true)) {
                continue;
            }

            AttendanceRecord::query()->updateOrCreate(
                [
                    'employee_id' => $row['employee_id'],
                    'attendance_date' => $date,
                ],
                [
                    'status' => $row['status'],
                    'check_in' => $row['check_in'] ?? null,
                    'check_out' => $row['check_out'] ?? null,
                    'notes' => $row['notes'] ?? null,
                    'recorded_by' => $userId,
                ]
            );
        }

        return redirect()
            ->route('hr.attendance.index', [
                'date' => $date,
                'department_id' => $request->input('department_id'),
                'location_id' => $request->input('location_id'),
                'q' => $request->input('q'),
            ])
            ->with('status', 'Attendance saved for '.Carbon::parse($date)->format('d M Y').'.');
    }

    public function monthlyReport(Request $request): View
    {
        $this->authorize('viewAny', AttendanceRecord::class);

        $report = $this->attendanceReport->monthlyReport($request);

        return view('hr.attendance.monthly-report', $report);
    }

    public function printMonthlyReport(Request $request): View
    {
        $this->authorize('viewAny', AttendanceRecord::class);

        $report = $this->attendanceReport->monthlyReport($request);

        return view('hr.attendance.monthly-report-print', $report);
    }
}
