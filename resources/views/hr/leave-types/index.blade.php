@extends('layouts.app')

@section('title', 'Leave Types')
@section('page-title', 'HR · Leave Types')

@section('content')
    @php
        $drawerMode = request('drawer');
        $isEdit = $drawerMode === 'edit' && !is_null($editingLeaveType);
        $isDrawerOpen = in_array($drawerMode, ['create', 'edit'], true);
        $leaveType = $isEdit ? $editingLeaveType : new \App\Models\LeaveType();
        $drawerCloseQuery = collect(request()->except('page', 'drawer', 'edit'))->all();
    @endphp

    <div x-data="{ drawerOpen: @js($isDrawerOpen), closeDrawer() { this.drawerOpen = false; window.location.href = @js(route('hr.leave-types.index', $drawerCloseQuery)); } }">
        <div class="mb-4 flex justify-end"><a href="{{ route('hr.leave-types.index', ['drawer' => 'create']) }}" class="inline-flex rounded-lg bg-slate-900 px-4 py-2 text-sm font-medium text-white">New Leave Type</a></div>
        @if (session('status'))<div class="mb-4 rounded-lg bg-emerald-50 p-3 text-sm text-emerald-700">{{ session('status') }}</div>@endif

        <x-index-table-shell title="Leave Types" :meta="$leaveTypes->total().' in this list'">
            <table class="index-data-table min-w-[40rem] sm:min-w-full whitespace-nowrap">
                <thead class="bg-slate-50/80 text-left text-[11px] font-semibold uppercase tracking-wide text-slate-600">
                    <tr><th class="px-4 py-3">Name</th><th class="px-4 py-3">Max days</th><th class="px-4 py-3">Paid</th><th class="px-4 py-3">Approval</th><th class="px-4 py-3 text-right">Actions</th></tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse ($leaveTypes as $row)
                        <tr class="hover:bg-slate-50/70">
                            <td class="px-4 py-3 font-medium">{{ $row->name }}</td>
                            <td class="px-4 py-3">{{ $row->max_days_per_year }}</td>
                            <td class="px-4 py-3">{{ $row->is_paid ? 'Yes' : 'No' }}</td>
                            <td class="px-4 py-3">{{ $row->requires_approval ? 'Required' : 'Auto' }}</td>
                            <td class="px-4 py-3 text-right">
                                <x-index-row-actions-menu>
                                    <a href="{{ route('hr.leave-types.index', array_merge($drawerCloseQuery, ['drawer' => 'edit', 'edit' => $row->id])) }}">Edit</a>
                                    <form action="{{ route('hr.leave-types.destroy', $row) }}" method="post" data-confirm="Delete?">@csrf @method('DELETE')<button type="submit" class="index-actions-menu-item--danger">Delete</button></form>
                                </x-index-row-actions-menu>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="px-4 py-8 text-center text-slate-500">No leave types found.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </x-index-table-shell>
        <x-pagination-limit-selector /><div class="mt-4">{{ $leaveTypes->links() }}</div>

        <div x-cloak x-show="drawerOpen" class="fixed inset-0 z-40">
            <button type="button" class="absolute inset-0 bg-slate-900/40" @click="closeDrawer()"></button>
            <div class="absolute inset-y-0 right-0 w-full max-w-xl overflow-y-auto bg-white shadow-2xl">
                <div class="sticky top-0 z-10 flex items-center justify-between border-b px-6 py-4"><h2 class="text-lg font-semibold">{{ $isEdit ? 'Edit' : 'New' }} Leave Type</h2><button type="button" @click="closeDrawer()" class="text-sm text-slate-600">Close</button></div>
                <div class="p-6"><form action="{{ $isEdit ? route('hr.leave-types.update', $leaveType) : route('hr.leave-types.store') }}" method="post">@if ($isEdit) @method('PUT') @endif @include('hr.leave-types._form')</form></div>
            </div>
        </div>
    </div>
@endsection
