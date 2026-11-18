@extends('layouts.app')

@section('title', 'Employees')
@section('page-title', 'HR · Employees')

@section('content')
    @php
        $drawerMode = request('drawer');
        $isEdit = $drawerMode === 'edit' && !is_null($editingEmployee);
        $isDrawerOpen = in_array($drawerMode, ['create', 'edit'], true);
        $employee = $isEdit ? $editingEmployee : new \App\Models\Employee();
        $drawerCloseQuery = collect(request()->except('page', 'drawer', 'edit'))->all();
    @endphp

    <div x-data="{ drawerOpen: @js($isDrawerOpen), closeDrawer() { this.drawerOpen = false; window.location.href = @js(route('hr.employees.index', $drawerCloseQuery)); } }">
        <div class="mb-4 flex justify-end">
            <a href="{{ route('hr.employees.index', ['drawer' => 'create']) }}" class="inline-flex rounded-lg bg-slate-900 px-4 py-2 text-sm font-medium text-white">New Employee</a>
        </div>
        @if (session('status'))<div class="mb-4 rounded-lg bg-emerald-50 p-3 text-sm text-emerald-700">{{ session('status') }}</div>@endif

        <x-filter-drawer>
            <form method="get" class="grid gap-4 md:grid-cols-2">
                <input type="text" name="q" value="{{ $filters['q'] ?? '' }}" placeholder="Search employee" class="rounded-lg border border-slate-300 px-3 py-2 text-sm">
                <select name="department_id" class="rounded-lg border border-slate-300 px-3 py-2 text-sm">
                    <option value="">All departments</option>
                    @foreach ($departments as $dept)<option value="{{ $dept->id }}" @selected(($filters['department_id'] ?? '') == $dept->id)>{{ $dept->name }}</option>@endforeach
                </select>
                <select name="location_id" class="rounded-lg border border-slate-300 px-3 py-2 text-sm">
                    <option value="">All branches</option>
                    @foreach ($locations as $loc)<option value="{{ $loc->id }}" @selected(($filters['location_id'] ?? '') == $loc->id)>{{ $loc->name }}</option>@endforeach
                </select>
                <select name="status" class="rounded-lg border border-slate-300 px-3 py-2 text-sm">
                    <option value="active" @selected(($filters['status'] ?? 'active') === 'active')>Active only</option>
                    <option value="inactive" @selected(($filters['status'] ?? '') === 'inactive')>Inactive / terminated</option>
                    <option value="" @selected(($filters['status'] ?? '') === '')>All</option>
                </select>
                <div class="md:col-span-2"><x-index-filter-actions :reset-url="route('hr.employees.index')" /></div>
            </form>
        </x-filter-drawer>

        <div class="mb-4 grid gap-3 sm:grid-cols-3">
            <x-status-card label="Total"><span class="text-slate-900">{{ $kpis['total'] }}</span></x-status-card>
            <x-status-card label="Active"><span class="text-slate-900">{{ $kpis['active'] }}</span></x-status-card>
            <x-status-card label="Filtered"><span class="text-slate-900">{{ $kpis['filtered'] }}</span></x-status-card>
        </div>

        <x-index-table-shell title="Employees" :meta="$employees->total().' in this list'">
            <table class="index-data-table min-w-[56rem] sm:min-w-full whitespace-nowrap">
                <thead class="bg-slate-50/80 text-left text-[11px] font-semibold uppercase tracking-wide text-slate-600">
                    <tr>
                        <th class="px-4 py-3">Code</th>
                        <th class="px-4 py-3">Name</th>
                        <th class="px-4 py-3">Department</th>
                        <th class="px-4 py-3">Branches</th>
                        <th class="px-4 py-3">Joined</th>
                        <th class="px-4 py-3">Status</th>
                        <th class="px-4 py-3 text-right">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse ($employees as $row)
                        <tr class="hover:bg-slate-50/70">
                            <td class="px-4 py-3 font-mono text-xs">{{ $row->employee_code }}</td>
                            <td class="px-4 py-3 font-medium">{{ $row->fullName() }}</td>
                            <td class="px-4 py-3">{{ $row->department?->name ?? '—' }}</td>
                            <td class="px-4 py-3">{{ $row->branchNames() }}</td>
                            <td class="px-4 py-3">{{ $row->joining_date?->format('d M Y') }}</td>
                            <td class="px-4 py-3 capitalize">{{ str_replace('_', ' ', $row->employment_status) }}</td>
                            <td class="px-4 py-3 text-right">
                                <x-index-row-actions-menu>
                                    <a href="{{ route('hr.employees.show', $row) }}">View</a>
                                    <a href="{{ route('hr.employees.index', array_merge($drawerCloseQuery, ['drawer' => 'edit', 'edit' => $row->id])) }}">Edit</a>
                                    <form action="{{ route('hr.employees.destroy', $row) }}" method="post" data-confirm="Delete employee?">@csrf @method('DELETE')<button type="submit" class="index-actions-menu-item--danger">Delete</button></form>
                                </x-index-row-actions-menu>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="px-4 py-8 text-center text-slate-500">No employees found.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </x-index-table-shell>
        <x-pagination-limit-selector /><div class="mt-4">{{ $employees->links() }}</div>

        <div x-cloak x-show="drawerOpen" class="fixed inset-0 z-40">
            <button type="button" class="absolute inset-0 bg-slate-900/40" @click="closeDrawer()"></button>
            <div class="absolute inset-y-0 right-0 w-full max-w-2xl overflow-y-auto bg-white shadow-2xl">
                <div class="sticky top-0 z-10 flex items-center justify-between border-b px-6 py-4"><h2 class="text-lg font-semibold">{{ $isEdit ? 'Edit Employee' : 'New Employee' }}</h2><button type="button" @click="closeDrawer()" class="text-sm text-slate-600">Close</button></div>
                <div class="p-6"><form action="{{ $isEdit ? route('hr.employees.update', $employee) : route('hr.employees.store') }}" method="post">@if ($isEdit) @method('PUT') @endif @include('hr.employees._form', ['departments' => $departments, 'locations' => $locations])</form></div>
            </div>
        </div>
    </div>
@endsection
