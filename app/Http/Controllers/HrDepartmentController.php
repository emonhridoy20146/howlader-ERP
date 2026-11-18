<?php

namespace App\Http\Controllers;

use App\Models\HrDepartment;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class HrDepartmentController extends Controller
{
    public function index(Request $request): View
    {
        $filters = ['q' => trim((string) $request->input('q', ''))];
        $editId = $request->integer('edit');
        $editingDepartment = $editId ? HrDepartment::find($editId) : null;

        $departments = HrDepartment::query()
            ->when($filters['q'] !== '', fn ($query) => $query->where('name', 'like', '%'.$filters['q'].'%')
                ->orWhere('code', 'like', '%'.$filters['q'].'%'))
            ->orderBy('name')
            ->paginate($this->resolvePerPage($request, 15))
            ->withQueryString();

        $kpis = [
            'total' => HrDepartment::count(),
            'active' => HrDepartment::where('is_active', true)->count(),
            'filtered' => $departments->total(),
        ];

        return view('hr.departments.index', compact('departments', 'editingDepartment', 'filters', 'kpis'));
    }

    public function create(): RedirectResponse
    {
        return redirect()->route('hr.departments.index', ['drawer' => 'create']);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120', 'unique:hr_departments,name'],
            'code' => ['nullable', 'string', 'max:30'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $data['is_active'] = $request->boolean('is_active', true);
        HrDepartment::create($data);

        return redirect()->route('hr.departments.index')->with('status', 'Department created successfully.');
    }

    public function edit(HrDepartment $department): RedirectResponse
    {
        return redirect()->route('hr.departments.index', ['drawer' => 'edit', 'edit' => $department->id]);
    }

    public function update(Request $request, HrDepartment $department): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120', 'unique:hr_departments,name,'.$department->id],
            'code' => ['nullable', 'string', 'max:30'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $data['is_active'] = $request->boolean('is_active', true);
        $department->update($data);

        return redirect()->route('hr.departments.index')->with('status', 'Department updated successfully.');
    }

    public function destroy(HrDepartment $department): RedirectResponse
    {
        $department->delete();

        return redirect()->route('hr.departments.index')->with('status', 'Department deleted.');
    }
}
