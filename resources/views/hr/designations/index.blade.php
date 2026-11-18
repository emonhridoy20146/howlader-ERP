@extends('layouts.app')

@section('title', 'Designations')
@section('page-title', 'HR · Designations')

@section('content')
    @php
        $drawerMode = request('drawer');
        $isEdit = $drawerMode === 'edit' && !is_null($editingDesignation);
        $isDrawerOpen = in_array($drawerMode, ['create', 'edit'], true);
        $designation = $isEdit ? $editingDesignation : new \App\Models\HrDesignation();
        $drawerCloseQuery = collect(request()->except('page', 'drawer', 'edit'))->all();
    @endphp

    <div x-data="{ drawerOpen: @js($isDrawerOpen), closeDrawer() { this.drawerOpen = false; window.location.href = @js(route('hr.designations.index', $drawerCloseQuery)); } }">
        <div class="mb-4 flex justify-end">
            <a href="{{ route('hr.designations.index', ['drawer' => 'create']) }}" class="inline-flex rounded-lg bg-slate-900 px-4 py-2 text-sm font-medium text-white hover:bg-slate-800">New Designation</a>
        </div>
        @if (session('status'))<div class="mb-4 rounded-lg bg-emerald-50 p-3 text-sm text-emerald-700">{{ session('status') }}</div>@endif

        <x-filter-drawer>
            <form method="get" action="{{ route('hr.designations.index') }}" class="flex flex-col gap-4">
                <input type="text" name="q" value="{{ $filters['q'] ?? '' }}" placeholder="Search designation" class="rounded-lg border border-slate-300 px-3 py-2 text-sm">
                <select name="department_id" class="rounded-lg border border-slate-300 px-3 py-2 text-sm">
                    <option value="">All departments</option>
                    @foreach ($departments as $dept)
                        <option value="{{ $dept->id }}" @selected(($filters['department_id'] ?? '') == $dept->id)>{{ $dept->name }}</option>
                    @endforeach
                </select>
                <x-index-filter-actions :reset-url="route('hr.designations.index')" />
            </form>
        </x-filter-drawer>

        <x-index-table-shell title="Designations" :meta="$designations->total().' in this list'">
            <table class="index-data-table min-w-[40rem] sm:min-w-full whitespace-nowrap">
                <thead class="bg-slate-50/80 text-left text-[11px] font-semibold uppercase tracking-wide text-slate-600">
                    <tr><th class="px-4 py-3">Name</th><th class="px-4 py-3">Department</th><th class="px-4 py-3">Status</th><th class="px-4 py-3 text-right">Actions</th></tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse ($designations as $row)
                        <tr class="hover:bg-slate-50/70">
                            <td class="px-4 py-3 font-medium">{{ $row->name }}</td>
                            <td class="px-4 py-3">{{ $row->department?->name ?? '—' }}</td>
                            <td class="px-4 py-3">{{ $row->is_active ? 'Active' : 'Inactive' }}</td>
                            <td class="px-4 py-3 text-right">
                                <x-index-row-actions-menu>
                                    <a href="{{ route('hr.designations.index', array_merge($drawerCloseQuery, ['drawer' => 'edit', 'edit' => $row->id])) }}">Edit</a>
                                    <form action="{{ route('hr.designations.destroy', $row) }}" method="post" data-confirm="Delete?">@csrf @method('DELETE')<button type="submit" class="index-actions-menu-item--danger">Delete</button></form>
                                </x-index-row-actions-menu>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="px-4 py-8 text-center text-slate-500">No designations found.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </x-index-table-shell>
        <x-pagination-limit-selector /><div class="mt-4">{{ $designations->links() }}</div>

        <div x-cloak x-show="drawerOpen" class="fixed inset-0 z-40">
            <button type="button" class="absolute inset-0 bg-slate-900/40" @click="closeDrawer()"></button>
            <div class="absolute inset-y-0 right-0 w-full max-w-xl overflow-y-auto bg-white shadow-2xl">
                <div class="sticky top-0 z-10 flex items-center justify-between border-b px-6 py-4"><h2 class="text-lg font-semibold">{{ $isEdit ? 'Edit' : 'New' }} Designation</h2><button type="button" @click="closeDrawer()" class="text-sm text-slate-600">Close</button></div>
                <div class="p-6">
                    <form action="{{ $isEdit ? route('hr.designations.update', $designation) : route('hr.designations.store') }}" method="post">@if ($isEdit) @method('PUT') @endif @include('hr.designations._form')</form>
                </div>
            </div>
        </div>
    </div>
@endsection
