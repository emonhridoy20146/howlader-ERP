<?php

namespace App\Http\Controllers;

use App\Models\LeaveType;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class LeaveTypeController extends Controller
{
    public function index(Request $request): View
    {
        $filters = ['q' => trim((string) $request->input('q', ''))];
        $editId = $request->integer('edit');
        $editingLeaveType = $editId ? LeaveType::find($editId) : null;

        $leaveTypes = LeaveType::query()
            ->when($filters['q'] !== '', fn ($query) => $query->where('name', 'like', '%'.$filters['q'].'%'))
            ->orderBy('name')
            ->paginate($this->resolvePerPage($request, 15))
            ->withQueryString();

        $kpis = [
            'total' => LeaveType::count(),
            'active' => LeaveType::where('is_active', true)->count(),
            'filtered' => $leaveTypes->total(),
        ];

        return view('hr.leave-types.index', compact('leaveTypes', 'editingLeaveType', 'filters', 'kpis'));
    }

    public function create(): RedirectResponse
    {
        return redirect()->route('hr.leave-types.index', ['drawer' => 'create']);
    }

    public function store(Request $request): RedirectResponse
    {
        LeaveType::create($this->validated($request));

        return redirect()->route('hr.leave-types.index')->with('status', 'Leave type created successfully.');
    }

    public function edit(LeaveType $leaveType): RedirectResponse
    {
        return redirect()->route('hr.leave-types.index', ['drawer' => 'edit', 'edit' => $leaveType->id]);
    }

    public function update(Request $request, LeaveType $leaveType): RedirectResponse
    {
        $leaveType->update($this->validated($request, $leaveType));

        return redirect()->route('hr.leave-types.index')->with('status', 'Leave type updated successfully.');
    }

    public function destroy(LeaveType $leaveType): RedirectResponse
    {
        $leaveType->delete();

        return redirect()->route('hr.leave-types.index')->with('status', 'Leave type deleted.');
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, ?LeaveType $leaveType = null): array
    {
        $uniqueRule = $leaveType
            ? 'unique:leave_types,name,'.$leaveType->id
            : 'unique:leave_types,name';

        $data = $request->validate([
            'name' => ['required', 'string', 'max:80', $uniqueRule],
            'max_days_per_year' => ['required', 'integer', 'min:0', 'max:366'],
            'is_paid' => ['nullable', 'boolean'],
            'requires_approval' => ['nullable', 'boolean'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $data['is_paid'] = $request->boolean('is_paid', true);
        $data['requires_approval'] = $request->boolean('requires_approval', true);
        $data['is_active'] = $request->boolean('is_active', true);

        return $data;
    }
}
