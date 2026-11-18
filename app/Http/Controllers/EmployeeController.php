<?php

namespace App\Http\Controllers;

use App\Models\Employee;
use App\Models\EmployeePayScaleAssignment;
use App\Models\HrDepartment;
use App\Models\HrDesignation;
use App\Models\LeaveRequest;
use App\Models\Location;
use App\Models\PayScale;
use App\Services\EmployeeCodeGenerator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class EmployeeController extends Controller
{
    public function index(Request $request): View
    {
        $filters = [
            'q' => trim((string) $request->input('q', '')),
            'department_id' => (string) $request->input('department_id', ''),
            'location_id' => (string) $request->input('location_id', ''),
            'status' => (string) $request->input('status', 'active'),
        ];
        $editId = $request->integer('edit');
        $editingEmployee = $editId ? Employee::with(['department', 'designation', 'locations'])->find($editId) : null;

        $employees = Employee::query()
            ->with(['department', 'designation', 'locations'])
            ->when($filters['q'] !== '', function ($query) use ($filters): void {
                $keyword = $filters['q'];
                $query->where(function ($sub) use ($keyword): void {
                    $sub->where('employee_code', 'like', "%{$keyword}%")
                        ->orWhere('first_name', 'like', "%{$keyword}%")
                        ->orWhere('last_name', 'like', "%{$keyword}%")
                        ->orWhere('email', 'like', "%{$keyword}%")
                        ->orWhere('phone', 'like', "%{$keyword}%");
                });
            })
            ->when($filters['department_id'] !== '', fn ($q) => $q->where('hr_department_id', $filters['department_id']))
            ->when(
                $filters['location_id'] !== '',
                fn ($q) => $q->whereHas('locations', fn ($sub) => $sub->where('locations.id', (int) $filters['location_id']))
            )
            ->when($filters['status'] === 'active', fn ($q) => $q->where('is_active', true)->where('employment_status', 'active'))
            ->when($filters['status'] === 'inactive', fn ($q) => $q->where(fn ($sub) => $sub->where('is_active', false)->orWhere('employment_status', '!=', 'active')))
            ->orderBy('employee_code')
            ->paginate($this->resolvePerPage($request, 15))
            ->withQueryString();

        $departments = HrDepartment::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']);
        $locations = Location::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']);

        $kpis = [
            'total' => Employee::count(),
            'active' => Employee::where('is_active', true)->where('employment_status', 'active')->count(),
            'filtered' => $employees->total(),
        ];

        return view('hr.employees.index', compact('employees', 'editingEmployee', 'departments', 'locations', 'filters', 'kpis'));
    }

    public function create(): RedirectResponse
    {
        return redirect()->route('hr.employees.index', ['drawer' => 'create']);
    }

    public function store(Request $request, EmployeeCodeGenerator $codeGenerator): RedirectResponse
    {
        $data = $this->validated($request);
        $locationIds = $data['location_ids'];
        unset($data['location_ids']);
        $data['employee_code'] = $codeGenerator->next();
        $employee = Employee::create($data);
        $employee->locations()->sync($locationIds);

        return redirect()->route('hr.employees.index')->with('status', 'Employee created successfully.');
    }

    public function show(Employee $employee): View
    {
        $employee->load([
            'department',
            'designation',
            'locations',
            'user',
            'payScaleAssignments.payScale',
            'payScaleAssignments.assignedByUser',
            'leaveRequests.leaveType',
            'attendanceRecords' => fn ($q) => $q->limit(30),
        ]);

        $currentAssignment = $employee->currentPayScaleAssignment();
        $payScales = PayScale::query()->where('is_active', true)->orderBy('name')->get(['id', 'name', 'gross_salary']);

        $leaveSummary = LeaveRequest::query()
            ->where('employee_id', $employee->id)
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        return view('hr.employees.show', compact('employee', 'currentAssignment', 'payScales', 'leaveSummary'));
    }

    public function edit(Employee $employee): RedirectResponse
    {
        return redirect()->route('hr.employees.index', ['drawer' => 'edit', 'edit' => $employee->id]);
    }

    public function update(Request $request, Employee $employee): RedirectResponse
    {
        $data = $this->validated($request);
        $locationIds = $data['location_ids'];
        unset($data['location_ids']);
        $employee->update($data);
        $employee->locations()->sync($locationIds);

        return redirect()->route('hr.employees.index')->with('status', 'Employee updated successfully.');
    }

    public function destroy(Employee $employee): RedirectResponse
    {
        $employee->delete();

        return redirect()->route('hr.employees.index')->with('status', 'Employee deleted.');
    }

    public function assignPayScale(Request $request, Employee $employee): RedirectResponse
    {
        $this->authorize('create', EmployeePayScaleAssignment::class);

        $data = $request->validate([
            'pay_scale_id' => ['required', 'exists:pay_scales,id'],
            'effective_from' => ['required', 'date'],
            'effective_to' => ['nullable', 'date', 'after_or_equal:effective_from'],
            'notes' => ['nullable', 'string'],
        ]);

        EmployeePayScaleAssignment::query()->create([
            'employee_id' => $employee->id,
            'pay_scale_id' => $data['pay_scale_id'],
            'effective_from' => $data['effective_from'],
            'effective_to' => $data['effective_to'] ?? null,
            'notes' => $data['notes'] ?? null,
            'assigned_by' => $request->user()?->id,
        ]);

        return redirect()->route('hr.employees.show', $employee)->with('status', 'Pay scale assigned successfully.');
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request): array
    {
        $data = $request->validate([
            'user_id' => ['nullable', 'exists:users,id'],
            'location_ids' => ['nullable', 'array'],
            'location_ids.*' => ['integer', 'exists:locations,id'],
            'hr_department_id' => ['nullable', 'exists:hr_departments,id'],
            'hr_designation_id' => ['nullable', 'exists:hr_designations,id'],
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['nullable', 'string', 'max:100'],
            'email' => ['nullable', 'email', 'max:150'],
            'phone' => ['nullable', 'string', 'max:40'],
            'gender' => ['nullable', 'string', 'max:20'],
            'date_of_birth' => ['nullable', 'date'],
            'joining_date' => ['required', 'date'],
            'termination_date' => ['nullable', 'date', 'after_or_equal:joining_date'],
            'employment_status' => ['required', 'in:active,inactive,terminated'],
            'check_in_time' => ['nullable', 'date_format:H:i'],
            'check_out_time' => ['nullable', 'date_format:H:i'],
            'bank_name' => ['nullable', 'string', 'max:120'],
            'bank_account' => ['nullable', 'string', 'max:60'],
            'notes' => ['nullable', 'string'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $data['is_active'] = $request->boolean('is_active', true);
        $data['check_in_time'] = $data['check_in_time'] ?? null;
        $data['check_out_time'] = $data['check_out_time'] ?? null;
        $data['location_ids'] = collect($data['location_ids'] ?? [])
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();

        return $data;
    }
}
