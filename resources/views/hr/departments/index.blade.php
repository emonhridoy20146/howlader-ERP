@extends('layouts.app')

@section('title', 'Departments')
@section('page-title', 'HR · Departments')

@section('content')
    @php
        $drawerMode = request('drawer');
        $isEdit = $drawerMode === 'edit' && !is_null($editingDepartment);
        $isDrawerOpen = in_array($drawerMode, ['create', 'edit'], true);
        $department = $isEdit ? $editingDepartment : new \App\Models\HrDepartment();
        $drawerCloseQuery = collect(request()->except('page', 'list', 'drawer', 'edit'))->all();
    @endphp

    <div x-data="{
        drawerOpen: @js($isDrawerOpen),
        closeDrawer() {
            this.drawerOpen = false;
            window.location.href = @js(route('hr.departments.index', $drawerCloseQuery));
        },
    }">
        <div class="mb-4 flex justify-end">
            <a href="{{ route('hr.departments.index', ['drawer' => 'create']) }}" class="inline-flex rounded-lg bg-slate-900 px-4 py-2 text-sm font-medium text-white hover:bg-slate-800">New Department</a>
        </div>

        @if (session('status'))
            <div class="mb-4 rounded-lg bg-emerald-50 p-3 text-sm text-emerald-700">{{ session('status') }}</div>
        @endif

        <x-filter-drawer>
            <form method="get" action="{{ route('hr.departments.index') }}" class="flex flex-col gap-4">
                <input type="text" name="q" value="{{ $filters['q'] ?? '' }}" placeholder="Search department" class="rounded-lg border border-slate-300 px-3 py-2 text-sm">
                <x-index-filter-actions :reset-url="route('hr.departments.index')" submit-label="Search" />
            </form>
        </x-filter-drawer>

        <div class="mb-4 grid gap-3 sm:grid-cols-3">
            <x-status-card label="Total"><span class="text-slate-900">{{ $kpis['total'] }}</span></x-status-card>
            <x-status-card label="Active"><span class="text-slate-900">{{ $kpis['active'] }}</span></x-status-card>
            <x-status-card label="Filtered"><span class="text-slate-900">{{ $kpis['filtered'] }}</span></x-status-card>
        </div>

        <x-index-table-shell title="Departments" :meta="$departments->total().' in this list'">
            <table class="index-data-table min-w-[32rem] sm:min-w-full whitespace-nowrap">
                <thead class="bg-slate-50/80 text-left text-[11px] font-semibold uppercase tracking-wide text-slate-600">
                    <tr>
                        <th class="px-4 py-3">Name</th>
                        <th class="px-4 py-3">Code</th>
                        <th class="px-4 py-3">Status</th>
                        <th class="px-4 py-3 text-right">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse ($departments as $row)
                        <tr class="hover:bg-slate-50/70">
                            <td class="px-4 py-3 font-medium">{{ $row->name }}</td>
                            <td class="px-4 py-3">{{ $row->code ?: '—' }}</td>
                            <td class="px-4 py-3">{{ $row->is_active ? 'Active' : 'Inactive' }}</td>
                            <td class="px-4 py-3 text-right">
                                <x-index-row-actions-menu>
                                    <a href="{{ route('hr.departments.index', array_merge($drawerCloseQuery, ['drawer' => 'edit', 'edit' => $row->id])) }}">Edit</a>
                                    <form action="{{ route('hr.departments.destroy', $row) }}" method="post" data-confirm="Delete this department?">
                                        @csrf @method('DELETE')
                                        <button type="submit" class="index-actions-menu-item--danger">Delete</button>
                                    </form>
                                </x-index-row-actions-menu>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="px-4 py-8 text-center text-slate-500">No departments found.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </x-index-table-shell>
        <x-pagination-limit-selector />
        <div class="mt-4">{{ $departments->links() }}</div>

        <div x-cloak x-show="drawerOpen" class="fixed inset-0 z-40">
            <button type="button" class="absolute inset-0 bg-slate-900/40" @click="closeDrawer()" aria-label="Close"></button>
            <div class="absolute inset-y-0 right-0 w-full max-w-xl overflow-y-auto bg-white shadow-2xl">
                <div class="sticky top-0 z-10 flex items-center justify-between border-b border-slate-200 bg-white px-6 py-4">
                    <h2 class="text-lg font-semibold">{{ $isEdit ? 'Edit Department' : 'New Department' }}</h2>
                    <button type="button" class="text-sm text-slate-600" @click="closeDrawer()">Close</button>
                </div>
                <div class="p-6">
                    <form action="{{ $isEdit ? route('hr.departments.update', $department) : route('hr.departments.store') }}" method="post" class="space-y-4">
                        @if ($isEdit) @method('PUT') @endif
                        @include('hr.departments._form')
                    </form>
                </div>
            </div>
        </div>
    </div>
@endsection
