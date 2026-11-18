<?php

namespace App\Http\Controllers;

use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class LeaveRequestController extends Controller
{
    public function index(Request $request): View
    {
        $filters = [
            'q' => trim((string) $request->input('q', '')),
            'status' => (string) $request->input('status', ''),
            'employee_id' => (string) $request->input('employee_id', ''),
        ];
        $editId = $request->integer('edit');
        $editingLeaveRequest = $editId ? LeaveRequest::with(['employee', 'leaveType'])->find($editId) : null;

        $leaveRequests = LeaveRequest::query()
            ->with(['employee', 'leaveType', 'reviewedByUser'])
            ->when($filters['status'] !== '', fn ($q) => $q->where('status', $filters['status']))
            ->when($filters['employee_id'] !== '', fn ($q) => $q->where('employee_id', $filters['employee_id']))
            ->when($filters['q'] !== '', function ($query) use ($filters): void {
                $keyword = $filters['q'];
                $query->whereHas('employee', function ($sub) use ($keyword): void {
                    $sub->where('employee_code', 'like', "%{$keyword}%")
                        ->orWhere('first_name', 'like', "%{$keyword}%")
                        ->orWhere('last_name', 'like', "%{$keyword}%");
                });
            })
            ->orderByDesc('start_date')
            ->paginate($this->resolvePerPage($request, 15))
            ->withQueryString();

        $employees = Employee::query()->where('is_active', true)->orderBy('employee_code')->get(['id', 'employee_code', 'first_name', 'last_name']);
        $leaveTypes = LeaveType::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']);

        $kpis = [
            'pending' => LeaveRequest::where('status', LeaveRequest::STATUS_PENDING)->count(),
            'approved' => LeaveRequest::where('status', LeaveRequest::STATUS_APPROVED)->count(),
            'filtered' => $leaveRequests->total(),
        ];

        return view('hr.leave-requests.index', compact('leaveRequests', 'editingLeaveRequest', 'employees', 'leaveTypes', 'filters', 'kpis'));
    }

    public function create(): RedirectResponse
    {
        return redirect()->route('hr.leave-requests.index', ['drawer' => 'create']);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'employee_id' => ['required', 'exists:employees,id'],
            'leave_type_id' => ['required', 'exists:leave_types,id'],
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after_or_equal:start_date'],
            'reason' => ['nullable', 'string'],
        ]);

        $data['total_days'] = LeaveRequest::calculateTotalDays($data['start_date'], $data['end_date']);
        $data['status'] = LeaveRequest::STATUS_PENDING;

        LeaveRequest::create($data);

        return redirect()->route('hr.leave-requests.index')->with('status', 'Leave request submitted.');
    }

    public function edit(LeaveRequest $leaveRequest): RedirectResponse
    {
        return redirect()->route('hr.leave-requests.index', ['drawer' => 'edit', 'edit' => $leaveRequest->id]);
    }

    public function update(Request $request, LeaveRequest $leaveRequest): RedirectResponse
    {
        if ($leaveRequest->status !== LeaveRequest::STATUS_PENDING) {
            return redirect()->route('hr.leave-requests.index')->with('status', 'Only pending leave requests can be edited.');
        }

        $data = $request->validate([
            'employee_id' => ['required', 'exists:employees,id'],
            'leave_type_id' => ['required', 'exists:leave_types,id'],
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after_or_equal:start_date'],
            'reason' => ['nullable', 'string'],
        ]);

        $data['total_days'] = LeaveRequest::calculateTotalDays($data['start_date'], $data['end_date']);
        $leaveRequest->update($data);

        return redirect()->route('hr.leave-requests.index')->with('status', 'Leave request updated.');
    }

    public function destroy(LeaveRequest $leaveRequest): RedirectResponse
    {
        if ($leaveRequest->status === LeaveRequest::STATUS_APPROVED) {
            return redirect()->route('hr.leave-requests.index')->with('status', 'Approved leave requests cannot be deleted.');
        }

        $leaveRequest->delete();

        return redirect()->route('hr.leave-requests.index')->with('status', 'Leave request deleted.');
    }

    public function approve(Request $request, LeaveRequest $leaveRequest): RedirectResponse
    {
        $this->authorize('approve', $leaveRequest);

        if ($leaveRequest->status !== LeaveRequest::STATUS_PENDING) {
            return back()->with('status', 'This leave request is no longer pending.');
        }

        $leaveRequest->update([
            'status' => LeaveRequest::STATUS_APPROVED,
            'reviewed_by' => $request->user()?->id,
            'reviewed_at' => now(),
            'review_notes' => $request->input('review_notes'),
        ]);

        return back()->with('status', 'Leave request approved.');
    }

    public function reject(Request $request, LeaveRequest $leaveRequest): RedirectResponse
    {
        $this->authorize('reject', $leaveRequest);

        if ($leaveRequest->status !== LeaveRequest::STATUS_PENDING) {
            return back()->with('status', 'This leave request is no longer pending.');
        }

        $leaveRequest->update([
            'status' => LeaveRequest::STATUS_REJECTED,
            'reviewed_by' => $request->user()?->id,
            'reviewed_at' => now(),
            'review_notes' => $request->input('review_notes'),
        ]);

        return back()->with('status', 'Leave request rejected.');
    }
}
