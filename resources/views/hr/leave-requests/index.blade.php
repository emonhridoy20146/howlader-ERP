@extends('layouts.app')

@section('title', 'Leave Requests')
@section('page-title', 'HR · Leave Requests')

@section('content')
    @php
        $drawerMode = request('drawer');
        $isEdit = $drawerMode === 'edit' && !is_null($editingLeaveRequest);
        $isDrawerOpen = in_array($drawerMode, ['create', 'edit'], true);
        $leaveRequest = $isEdit ? $editingLeaveRequest : new \App\Models\LeaveRequest();
        $drawerCloseQuery = collect(request()->except('page', 'drawer', 'edit'))->all();
    @endphp

    <div x-data="{ drawerOpen: @js($isDrawerOpen), closeDrawer() { this.drawerOpen = false; window.location.href = @js(route('hr.leave-requests.index', $drawerCloseQuery)); } }">
        <div class="mb-4 flex justify-end"><a href="{{ route('hr.leave-requests.index', ['drawer' => 'create']) }}" class="inline-flex rounded-lg bg-slate-900 px-4 py-2 text-sm font-medium text-white">New Leave Request</a></div>
        @if (session('status'))<div class="mb-4 rounded-lg bg-emerald-50 p-3 text-sm text-emerald-700">{{ session('status') }}</div>@endif

        <x-filter-drawer>
            <form method="get" class="grid gap-4 md:grid-cols-2">
                <select name="status" class="rounded-lg border border-slate-300 px-3 py-2 text-sm">
                    <option value="">All statuses</option>
                    @foreach (['pending','approved','rejected','cancelled'] as $st)
                        <option value="{{ $st }}" @selected(($filters['status'] ?? '') === $st)>{{ ucfirst($st) }}</option>
                    @endforeach
                </select>
                <select name="employee_id" class="rounded-lg border border-slate-300 px-3 py-2 text-sm">
                    <option value="">All employees</option>
                    @foreach ($employees as $emp)<option value="{{ $emp->id }}" @selected(($filters['employee_id'] ?? '') == $emp->id)>{{ $emp->employee_code }} — {{ $emp->first_name }}</option>@endforeach
                </select>
                <div class="md:col-span-2"><x-index-filter-actions :reset-url="route('hr.leave-requests.index')" /></div>
            </form>
        </x-filter-drawer>

        <div class="mb-4 grid gap-3 sm:grid-cols-3">
            <x-status-card label="Pending"><span class="text-amber-700">{{ $kpis['pending'] }}</span></x-status-card>
            <x-status-card label="Approved"><span class="text-emerald-700">{{ $kpis['approved'] }}</span></x-status-card>
            <x-status-card label="Filtered"><span class="text-slate-900">{{ $kpis['filtered'] }}</span></x-status-card>
        </div>

        <x-index-table-shell title="Leave Requests" :meta="$leaveRequests->total().' in this list'">
            <table class="index-data-table min-w-[56rem] sm:min-w-full whitespace-nowrap">
                <thead class="bg-slate-50/80 text-left text-[11px] font-semibold uppercase tracking-wide text-slate-600">
                    <tr><th class="px-4 py-3">Employee</th><th class="px-4 py-3">Type</th><th class="px-4 py-3">Dates</th><th class="px-4 py-3">Days</th><th class="px-4 py-3">Status</th><th class="px-4 py-3 text-right">Actions</th></tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse ($leaveRequests as $row)
                        <tr class="hover:bg-slate-50/70">
                            <td class="px-4 py-3"><p class="font-medium">{{ $row->employee?->fullName() }}</p><p class="text-xs text-slate-500">{{ $row->employee?->employee_code }}</p></td>
                            <td class="px-4 py-3">{{ $row->leaveType?->name }}</td>
                            <td class="px-4 py-3">{{ $row->start_date?->format('d M') }} – {{ $row->end_date?->format('d M Y') }}</td>
                            <td class="px-4 py-3">{{ number_format($row->total_days, 1) }}</td>
                            <td class="px-4 py-3 capitalize">{{ $row->status }}</td>
                            <td class="px-4 py-3 text-right">
                                <x-index-row-actions-menu>
                                    @if ($row->status === 'pending')
                                        @can('approve', $row)
                                            <form action="{{ route('hr.leave-requests.approve', $row) }}" method="post">@csrf<button type="submit">Approve</button></form>
                                        @endcan
                                        @can('reject', $row)
                                            <form action="{{ route('hr.leave-requests.reject', $row) }}" method="post">@csrf<button type="submit" class="index-actions-menu-item--danger">Reject</button></form>
                                        @endcan
                                        <a href="{{ route('hr.leave-requests.index', array_merge($drawerCloseQuery, ['drawer' => 'edit', 'edit' => $row->id])) }}">Edit</a>
                                    @endif
                                    @if ($row->status !== 'approved')
                                        <form action="{{ route('hr.leave-requests.destroy', $row) }}" method="post" data-confirm="Delete?">@csrf @method('DELETE')<button type="submit" class="index-actions-menu-item--danger">Delete</button></form>
                                    @endif
                                </x-index-row-actions-menu>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="px-4 py-8 text-center text-slate-500">No leave requests found.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </x-index-table-shell>
        <x-pagination-limit-selector /><div class="mt-4">{{ $leaveRequests->links() }}</div>

        <div x-cloak x-show="drawerOpen" class="fixed inset-0 z-40">
            <button type="button" class="absolute inset-0 bg-slate-900/40" @click="closeDrawer()"></button>
            <div class="absolute inset-y-0 right-0 w-full max-w-xl overflow-y-auto bg-white shadow-2xl">
                <div class="sticky top-0 z-10 flex items-center justify-between border-b px-6 py-4"><h2 class="text-lg font-semibold">{{ $isEdit ? 'Edit' : 'New' }} Leave Request</h2><button type="button" @click="closeDrawer()" class="text-sm text-slate-600">Close</button></div>
                <div class="p-6"><form action="{{ $isEdit ? route('hr.leave-requests.update', $leaveRequest) : route('hr.leave-requests.store') }}" method="post">@if ($isEdit) @method('PUT') @endif @include('hr.leave-requests._form')</form></div>
            </div>
        </div>
    </div>
@endsection
