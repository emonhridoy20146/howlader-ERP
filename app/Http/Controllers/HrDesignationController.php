<?php

namespace App\Http\Controllers;

use App\Models\HrDepartment;
use App\Models\HrDesignation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class HrDesignationController extends Controller
{
    public function index(Request $request): View
    {
        $filters = [
            'q' => trim((string) $request->input('q', '')),
            'department_id' => (string) $request->input('department_id', ''),
        ];
        $editId = $request->integer('edit');
        $editingDesignation = $editId ? HrDesignation::with('department')->find($editId) : null;

        $designations = HrDesignation::query()
            ->with('department')
            ->when($filters['q'] !== '', fn ($query) => $query->where('name', 'like', '%'.$filters['q'].'%'))
            ->when($filters['department_id'] !== '', fn ($query) => $query->where('hr_department_id', $filters['department_id']))
            ->orderBy('name')
            ->paginate($this->resolvePerPage($request, 15))
            ->withQueryString();

        $departments = HrDepartment::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']);

        $kpis = [
            'total' => HrDesignation::count(),
            'active' => HrDesignation::where('is_active', true)->count(),
            'filtered' => $designations->total(),
        ];

        return view('hr.designations.index', compact('designations', 'editingDesignation', 'departments', 'filters', 'kpis'));
    }

    public function create(): RedirectResponse
    {
        return redirect()->route('hr.designations.index', ['drawer' => 'create']);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120', 'unique:hr_designations,name'],
            'hr_department_id' => ['nullable', 'exists:hr_departments,id'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $data['is_active'] = $request->boolean('is_active', true);
        HrDesignation::create($data);

        return redirect()->route('hr.designations.index')->with('status', 'Designation created successfully.');
    }

    public function edit(HrDesignation $designation): RedirectResponse
    {
        return redirect()->route('hr.designations.index', ['drawer' => 'edit', 'edit' => $designation->id]);
    }

    public function update(Request $request, HrDesignation $designation): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120', 'unique:hr_designations,name,'.$designation->id],
            'hr_department_id' => ['nullable', 'exists:hr_departments,id'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $data['is_active'] = $request->boolean('is_active', true);
        $designation->update($data);

        return redirect()->route('hr.designations.index')->with('status', 'Designation updated successfully.');
    }

    public function destroy(HrDesignation $designation): RedirectResponse
    {
        $designation->delete();

        return redirect()->route('hr.designations.index')->with('status', 'Designation deleted.');
    }
}
