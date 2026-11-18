@extends('layouts.app')

@section('title', 'Pay Scales')
@section('page-title', 'HR · Pay Scales')

@section('content')
    @php
        $drawerMode = request('drawer');
        $isEdit = $drawerMode === 'edit' && !is_null($editingPayScale);
        $isDrawerOpen = in_array($drawerMode, ['create', 'edit'], true);
        $payScale = $isEdit ? $editingPayScale : new \App\Models\PayScale();
        $drawerCloseQuery = collect(request()->except('page', 'drawer', 'edit'))->all();
    @endphp

    <div x-data="{ drawerOpen: @js($isDrawerOpen), closeDrawer() { this.drawerOpen = false; window.location.href = @js(route('hr.pay-scales.index', $drawerCloseQuery)); } }">
        <div class="mb-4 flex justify-end">
            <a href="{{ route('hr.pay-scales.index', ['drawer' => 'create']) }}" class="inline-flex rounded-lg bg-slate-900 px-4 py-2 text-sm font-medium text-white">New Pay Scale</a>
        </div>
        @if (session('status'))<div class="mb-4 rounded-lg bg-emerald-50 p-3 text-sm text-emerald-700">{{ session('status') }}</div>@endif

        <x-filter-drawer>
            <form method="get" class="flex flex-col gap-4">
                <input type="text" name="q" value="{{ $filters['q'] ?? '' }}" placeholder="Search pay scale" class="rounded-lg border border-slate-300 px-3 py-2 text-sm">
                <x-index-filter-actions :reset-url="route('hr.pay-scales.index')" />
            </form>
        </x-filter-drawer>

        <x-index-table-shell title="Pay Scales" :meta="$payScales->total().' in this list'">
            <table class="index-data-table min-w-[48rem] sm:min-w-full whitespace-nowrap">
                <thead class="bg-slate-50/80 text-left text-[11px] font-semibold uppercase tracking-wide text-slate-600">
                    <tr>
                        <th class="px-4 py-3">Name</th>
                        <th class="px-4 py-3 text-right">Basic</th>
                        <th class="px-4 py-3 text-right">Allowances</th>
                        <th class="px-4 py-3 text-right">Gross</th>
                        <th class="px-4 py-3">Status</th>
                        <th class="px-4 py-3 text-right">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse ($payScales as $row)
                        @php $allowances = (float)$row->house_rent_allowance + (float)$row->medical_allowance + (float)$row->transport_allowance + (float)$row->other_allowance; @endphp
                        <tr class="hover:bg-slate-50/70">
                            <td class="px-4 py-3 font-medium">{{ $row->name }}</td>
                            <td class="px-4 py-3 text-right tabular-nums">{{ number_format($row->basic_salary, 2) }}</td>
                            <td class="px-4 py-3 text-right tabular-nums">{{ number_format($allowances, 2) }}</td>
                            <td class="px-4 py-3 text-right tabular-nums font-semibold">{{ number_format($row->gross_salary, 2) }}</td>
                            <td class="px-4 py-3">{{ $row->is_active ? 'Active' : 'Inactive' }}</td>
                            <td class="px-4 py-3 text-right">
                                <x-index-row-actions-menu>
                                    <a href="{{ route('hr.pay-scales.index', array_merge($drawerCloseQuery, ['drawer' => 'edit', 'edit' => $row->id])) }}">Edit</a>
                                    <form action="{{ route('hr.pay-scales.destroy', $row) }}" method="post" data-confirm="Delete?">@csrf @method('DELETE')<button type="submit" class="index-actions-menu-item--danger">Delete</button></form>
                                </x-index-row-actions-menu>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="px-4 py-8 text-center text-slate-500">No pay scales found.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </x-index-table-shell>
        <x-pagination-limit-selector /><div class="mt-4">{{ $payScales->links() }}</div>

        <div x-cloak x-show="drawerOpen" class="fixed inset-0 z-40">
            <button type="button" class="absolute inset-0 bg-slate-900/40" @click="closeDrawer()"></button>
            <div class="absolute inset-y-0 right-0 w-full max-w-2xl overflow-y-auto bg-white shadow-2xl">
                <div class="sticky top-0 z-10 flex items-center justify-between border-b px-6 py-4"><h2 class="text-lg font-semibold">{{ $isEdit ? 'Edit' : 'New' }} Pay Scale</h2><button type="button" @click="closeDrawer()" class="text-sm text-slate-600">Close</button></div>
                <div class="p-6"><form action="{{ $isEdit ? route('hr.pay-scales.update', $payScale) : route('hr.pay-scales.store') }}" method="post">@if ($isEdit) @method('PUT') @endif @include('hr.pay-scales._form')</form></div>
            </div>
        </div>
    </div>
@endsection
