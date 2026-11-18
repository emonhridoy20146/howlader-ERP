@extends('layouts.app')
@section('title', 'Ledger accounts')
@section('page-title', 'Account heads & ledger')
@php
    $listQuery = request()->only(['q', 'type', 'active', 'system']);
    $drawerMode = request('drawer');
    $isEdit = (bool) $editingLedgerAccount && (($drawerMode === 'edit') || (old('_drawer') === 'edit'));
    $isDrawerOpen = in_array((string) $drawerMode, ['create', 'edit'], true) || $errors->any();
    if ($drawerMode === 'edit' && ! $editingLedgerAccount) {
        $isDrawerOpen = $errors->any();
    }
@endphp
@section('content')
<div x-data="{ drawerOpen: @js($isDrawerOpen) }">
<div class="mb-4 flex flex-col gap-2 sm:flex-row sm:flex-wrap sm:items-center sm:justify-end">
    @can('create', \App\Models\LedgerAccount::class)
    <a href="{{ route('ledger-accounts.index', array_merge($listQuery, ['drawer' => 'create'])) }}" class="inline-flex justify-center rounded-lg bg-slate-900 px-4 py-2.5 text-sm font-medium text-white shadow-sm hover:bg-slate-800 sm:py-2">New account head</a>
    @endcan
</div>

@if (session('status'))
    <div class="mb-4 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">{{ session('status') }}</div>
@endif

<x-filter-drawer>
<form method="get" action="{{ route('ledger-accounts.index') }}" class="flex flex-col gap-4">
    <p class="mb-3 text-xs font-semibold uppercase tracking-wide text-slate-500">Filters</p>
    <div class="flex flex-col gap-4">
        <input type="search" name="q" value="{{ $filters['q'] ?? '' }}" placeholder="Search code or name" class="rounded-xl border border-slate-200 bg-slate-50/50 px-3 py-2.5 text-sm ring-slate-900/5 placeholder:text-slate-400 focus:border-slate-400 focus:bg-white focus:outline-none focus:ring-2 focus:ring-slate-200 ">
        <select name="type" class="rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm focus:border-slate-400 focus:outline-none focus:ring-2 focus:ring-slate-200">
            <option value="">All types</option>
            @foreach($types as $value => $label)
                <option value="{{ $value }}" @selected(($filters['type'] ?? '') === $value)>{{ $label }}</option>
            @endforeach
        </select>
        <select name="active" class="rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm focus:border-slate-400 focus:outline-none focus:ring-2 focus:ring-slate-200">
            <option value="">Any status</option>
            <option value="1" @selected(($filters['active'] ?? '') === '1')>Active</option>
            <option value="0" @selected(($filters['active'] ?? '') === '0')>Inactive</option>
        </select>
        <select name="system" class="rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm focus:border-slate-400 focus:outline-none focus:ring-2 focus:ring-slate-200">
            <option value="">System & custom</option>
            <option value="1" @selected(($filters['system'] ?? '') === '1')>System only</option>
            <option value="0" @selected(($filters['system'] ?? '') === '0')>Custom only</option>
        </select>
    </div>
    <div class="mt-3 flex flex-wrap gap-2">
        <button type="submit" class="rounded-lg bg-slate-800 px-4 py-2 text-sm font-medium text-white hover:bg-slate-700">Apply</button>
        <a href="{{ route('ledger-accounts.index') }}" class="rounded-lg border border-slate-200 bg-white px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50">Reset</a>
    </div>
</form>
</x-filter-drawer>

<div class="mb-6 grid gap-3 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-6">
    <x-status-card label="Total">
			<span class="text-slate-900">{{ $kpis['total'] }}</span>
		</x-status-card>
    <x-status-card label="System">
			<span class="text-slate-900">{{ $kpis['system'] }}</span>
		</x-status-card>
    <x-status-card label="Custom">
			<span class="text-slate-900">{{ $kpis['custom'] }}</span>
		</x-status-card>
    <x-status-card label="Active">
			<span class="text-slate-900">{{ $kpis['active'] }}</span>
		</x-status-card>
    <x-status-card label="Inactive">
			<span class="text-slate-900">{{ $kpis['inactive'] }}</span>
		</x-status-card>
    <x-status-card label="Filtered">
			<span class="text-slate-900">{{ $kpis['filtered'] }}</span>
		</x-status-card>
</div>

<x-index-table-shell title="Ledger accounts" :meta="$accounts->total().' in this list'">
        <table class="index-data-table min-w-[44rem] sm:min-w-full text-sm whitespace-nowrap">
            <thead class="border-b border-slate-200 bg-slate-50/80 text-left text-xs font-semibold uppercase tracking-wide text-slate-600">
                <tr>
                    <th class="whitespace-nowrap px-4 py-3">Code</th>
                    <th class="px-4 py-3">Name</th>
                    <th class="whitespace-nowrap px-4 py-3">Type</th>
                    <th class="whitespace-nowrap px-4 py-3">System</th>
                    <th class="whitespace-nowrap px-4 py-3">Status</th>
                    <th class="whitespace-nowrap px-4 py-3 text-right">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
            @forelse($accounts as $account)
                <tr class="hover:bg-slate-50/80">
                    <td class="whitespace-nowrap px-4 py-3 font-mono text-xs text-slate-800">{{ $account->code }}</td>
                    <td class="px-4 py-3 font-medium text-slate-900">{{ $account->name }}</td>
                    <td class="whitespace-nowrap px-4 py-3 text-slate-700">{{ $types[$account->type] ?? $account->type }}</td>
                    <td class="whitespace-nowrap px-4 py-3 text-slate-600">{{ $account->is_system ? 'Yes' : '—' }}</td>
                    <td class="whitespace-nowrap px-4 py-3">
                        @if($account->is_active)
                            <span class="inline-flex rounded-full bg-emerald-100 px-2 py-0.5 text-xs font-medium text-emerald-800">Active</span>
                        @else
                            <span class="inline-flex rounded-full bg-slate-100 px-2 py-0.5 text-xs font-medium text-slate-600">Inactive</span>
                        @endif
                    </td>
                    <td class="whitespace-nowrap px-4 py-3 text-right">
                        @canany(['update', 'delete'], $account)
                        <x-index-row-actions-menu>
                            @can('update', $account)
                            <a href="{{ route('ledger-accounts.index', array_merge($listQuery, ['drawer' => 'edit', 'edit' => $account->id])) }}">Edit</a>
                            @endcan
                            @can('delete', $account)
                            <form action="{{ route('ledger-accounts.destroy', $account) }}" method="post" data-confirm="Delete this account?">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="index-actions-menu-item--danger">Delete</button>
                            </form>
                            @endcan
                        </x-index-row-actions-menu>
                        @endcanany
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="6" class="px-4 py-12 text-center text-slate-500">No ledger accounts match your filters.</td>
                </tr>
            @endforelse
            </tbody>
        </table>
</x-index-table-shell>
	<x-pagination-limit-selector />

<div class="mt-6">{{ $accounts->links() }}</div>

<div x-cloak x-show="drawerOpen" class="fixed inset-0 z-40">
    <a href="{{ route('ledger-accounts.index', $listQuery) }}" class="absolute inset-0 bg-slate-900/40" aria-label="Close"></a>
    <div class="absolute inset-y-0 right-0 w-full max-w-xl overflow-y-auto bg-white shadow-2xl">
        <div class="sticky top-0 flex items-center justify-between border-b border-slate-200 bg-white px-6 py-4">
            <h2 class="text-lg font-semibold text-slate-900">{{ $isEdit ? 'Edit account head' : 'New account head' }}</h2>
            <a href="{{ route('ledger-accounts.index', $listQuery) }}" class="text-sm text-slate-600 hover:text-slate-900">Close</a>
        </div>
        <div class="p-6">
            <form action="{{ $isEdit ? route('ledger-accounts.update', $drawerAccount) : route('ledger-accounts.store') }}" method="post" class="space-y-4">
                @csrf
                @if ($isEdit)
                    @method('PUT')
                @endif
                <input type="hidden" name="_drawer" value="{{ $isEdit ? 'edit' : 'create' }}">
                @if ($isEdit)
                    <input type="hidden" name="ledger_account_context_id" value="{{ $drawerAccount->id }}">
                @endif
                @foreach($listQuery as $key => $value)
                    @if($value !== '' && $value !== null)
                        <input type="hidden" name="preserve[{{ $key }}]" value="{{ $value }}">
                    @endif
                @endforeach
                @include('ledger-accounts._form-fields', ['account' => $drawerAccount, 'formTypes' => $formTypes])
                <div class="flex flex-wrap gap-2 border-t border-slate-200 pt-6">
                    <button type="submit" class="rounded-lg bg-slate-900 px-5 py-2.5 text-sm font-medium text-white hover:bg-slate-800">{{ $isEdit ? 'Save changes' : 'Save' }}</button>
                    <a href="{{ route('ledger-accounts.index', $listQuery) }}" class="rounded-lg border border-slate-300 bg-white px-5 py-2.5 text-sm font-medium text-slate-700 hover:bg-slate-50">Cancel</a>
                </div>
            </form>
        </div>
    </div>
</div>
</div>
@endsection
