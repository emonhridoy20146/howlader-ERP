@extends('layouts.app')
@section('title', 'Payment methods')
@section('page-title', 'Payment methods')
@php
    $listQuery = request()->only(['q', 'location_id', 'type', 'active']);
    $drawerMode = request('drawer');
    $isEdit = $drawerMode === 'edit' && $editingPaymentMethod;
    $isDrawerOpen = in_array((string) $drawerMode, ['create', 'edit'], true) || $errors->any();
    if ($drawerMode === 'edit' && ! $editingPaymentMethod) {
        $isDrawerOpen = $errors->any();
    }
    $method = $isEdit ? $editingPaymentMethod : new \App\Models\PaymentMethod();
@endphp
@section('content')
<div x-data="{ drawerOpen: @js($isDrawerOpen) }">
<div class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
    <div class="max-w-2xl text-sm text-slate-600">
        <p class="text-slate-800">Methods customers can use at checkout. Map each method to a <strong>wallet</strong> per branch so customer payments post to the general ledger (double-entry).</p>
        <p class="mt-2">@can('viewAny', \App\Models\PaymentMethodWalletMap::class)<a href="{{ route('payment-method-wallet-maps.index') }}" class="font-medium text-slate-900 underline decoration-slate-300 underline-offset-2 hover:decoration-slate-600">Payment → wallet mappings</a>@endcan @can('viewAny', \App\Models\Wallet::class)<span class="text-slate-400">·</span> <a href="{{ route('wallets.index') }}" class="font-medium text-slate-900 underline decoration-slate-300 underline-offset-2 hover:decoration-slate-600">Wallets</a>@endcan</p>
    </div>
    <div class="flex shrink-0 flex-col gap-2 sm:flex-row sm:flex-wrap">
        @can('create', \App\Models\PaymentMethod::class)
        <a href="{{ route('payment-methods.index', array_merge($listQuery, ['drawer' => 'create'])) }}" class="inline-flex justify-center rounded-lg bg-slate-900 px-4 py-2.5 text-sm font-medium text-white shadow-sm hover:bg-slate-800 sm:py-2">New payment method</a>
        @endcan
    </div>
</div>

@if (session('status'))
    <div class="mb-4 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">{{ session('status') }}</div>
@endif

<x-filter-drawer>
<form method="get" action="{{ route('payment-methods.index') }}" class="flex flex-col gap-4">
    <p class="mb-3 text-xs font-semibold uppercase tracking-wide text-slate-500">Filters</p>
    <div class="flex flex-col gap-4">
        <input type="search" name="q" value="{{ $filters['q'] ?? '' }}" placeholder="Search name, code, account…" class="rounded-xl border border-slate-200 bg-slate-50/50 px-3 py-2.5 text-sm ring-slate-900/5 placeholder:text-slate-400 focus:border-slate-400 focus:bg-white focus:outline-none focus:ring-2 focus:ring-slate-200 ">
        <select name="location_id" class="rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm focus:border-slate-400 focus:outline-none focus:ring-2 focus:ring-slate-200">
            <option value="">All branches</option>
            <option value="global" @selected(($filters['location_id'] ?? '') === 'global')>Global only</option>
            @foreach($locations as $location)
                <option value="{{ $location->id }}" @selected((string) ($filters['location_id'] ?? '') === (string) $location->id)>{{ $location->name }}</option>
            @endforeach
        </select>
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
    </div>
    <div class="mt-3 flex flex-wrap gap-2">
        <button type="submit" class="rounded-lg bg-slate-800 px-4 py-2 text-sm font-medium text-white hover:bg-slate-700">Apply</button>
        <a href="{{ route('payment-methods.index') }}" class="rounded-lg border border-slate-200 bg-white px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50">Reset</a>
    </div>
</form>
</x-filter-drawer>

<x-index-table-shell title="Payment methods" :meta="$methods->total().' in this list'">
        <table class="index-data-table min-w-[56rem] sm:min-w-full text-sm whitespace-nowrap">
            <thead class="border-b border-slate-200 bg-slate-50/80 text-left text-xs font-semibold uppercase tracking-wide text-slate-600">
                <tr>
                    <th class="whitespace-nowrap px-4 py-3">Code</th>
                    <th class="min-w-[8rem] px-4 py-3">Name</th>
                    <th class="whitespace-nowrap px-4 py-3">Type</th>
                    <th class="whitespace-nowrap px-4 py-3">Branch</th>
                    <th class="min-w-[10rem] px-4 py-3">Identifier</th>
                    <th class="min-w-[12rem] px-4 py-3">Ledger wallet</th>
                    <th class="whitespace-nowrap px-4 py-3">Status</th>
                    <th class="whitespace-nowrap px-4 py-3 text-right">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
            @forelse($methods as $rowMethod)
                @php
                    $maps = $rowMethod->walletMaps->sortBy(fn ($m) => $m->location?->name ?? '');
                @endphp
                <tr class="hover:bg-slate-50/80">
                    <td class="whitespace-nowrap px-4 py-3 font-mono text-xs text-slate-800">{{ $rowMethod->code }}</td>
                    <td class="px-4 py-3 font-medium text-slate-900">{{ $rowMethod->name }}</td>
                    <td class="whitespace-nowrap px-4 py-3 text-slate-700">{{ $types[$rowMethod->type] ?? ucfirst(str_replace('_', ' ', $rowMethod->type)) }}</td>
                    <td class="whitespace-nowrap px-4 py-3 text-slate-600">
                        @if($rowMethod->location)
                            <span class="rounded-md bg-slate-100 px-2 py-0.5 font-mono text-xs">{{ $rowMethod->location->code }}</span>
                            <span class="sr-only">{{ $rowMethod->location->name }}</span>
                        @else
                            <span class="rounded-md bg-indigo-50 px-2 py-0.5 text-xs font-medium text-indigo-800">All branches</span>
                        @endif
                    </td>
                    <td class="max-w-[14rem] truncate px-4 py-3 font-mono text-xs text-slate-600" title="{{ $rowMethod->account_number ?: $rowMethod->mobile_number ?: $rowMethod->terminal_id ?: '' }}">
                        {{ $rowMethod->account_number ?: ($rowMethod->mobile_number ?: ($rowMethod->terminal_id ?: '—')) }}
                    </td>
                    <td class="px-4 py-3 align-top">
                        @if($maps->isEmpty())
                            <span class="inline-flex items-center gap-1.5 rounded-lg border border-amber-200 bg-amber-50 px-2 py-1 text-xs font-medium text-amber-900">Not mapped</span>
                            @can('create', \App\Models\PaymentMethodWalletMap::class)
                                @if($rowMethod->location_id)
                                    <a href="{{ route('payment-method-wallet-maps.index', array_filter(['drawer' => 'create', 'location_id' => $rowMethod->location_id, 'payment_method_id' => $rowMethod->id])) }}" class="mt-1 block text-xs font-medium text-slate-700 underline decoration-slate-300 hover:text-slate-900">Link wallet →</a>
                                @else
                                    <a href="{{ route('payment-method-wallet-maps.index', array_filter(['drawer' => 'create', 'payment_method_id' => $rowMethod->id])) }}" class="mt-1 block text-xs font-medium text-slate-700 underline decoration-slate-300 hover:text-slate-900">Link per branch →</a>
                                @endif
                            @endcan
                        @else
                            <ul class="space-y-1 text-xs text-slate-700">
                                @foreach($maps as $map)
                                    <li class="flex flex-wrap items-baseline gap-x-1.5">
                                        <span class="text-slate-700">{{ $map->location?->name }}</span>
                                        <span class="text-slate-400">→</span>
                                        <span class="font-medium text-slate-900">{{ $map->wallet?->code }}</span>
                                        @can('update', $map)
                                            <a href="{{ route('payment-method-wallet-maps.index', ['drawer' => 'edit', 'edit' => $map->id]) }}" class="text-slate-500 underline decoration-slate-200 hover:text-slate-800">Edit</a>
                                        @endcan
                                    </li>
                                @endforeach
                            </ul>
                            @can('create', \App\Models\PaymentMethodWalletMap::class)
                                <a href="{{ route('payment-method-wallet-maps.index', array_filter(['drawer' => 'create', 'payment_method_id' => $rowMethod->id])) }}" class="mt-1 inline-block text-xs font-medium text-slate-700 underline decoration-slate-300 hover:text-slate-900">Add branch…</a>
                            @endcan
                        @endif
                    </td>
                    <td class="whitespace-nowrap px-4 py-3">
                        @if($rowMethod->is_active)
                            <span class="inline-flex rounded-full bg-emerald-100 px-2 py-0.5 text-xs font-medium text-emerald-800">Active</span>
                        @else
                            <span class="inline-flex rounded-full bg-slate-100 px-2 py-0.5 text-xs font-medium text-slate-600">Inactive</span>
                        @endif
                    </td>
                    <td class="whitespace-nowrap px-4 py-3 text-right">
                        @canany(['update', 'delete'], $rowMethod)
                        <x-index-row-actions-menu>
                            @can('update', $rowMethod)
                            <a href="{{ route('payment-methods.index', array_merge($listQuery, ['drawer' => 'edit', 'edit' => $rowMethod->id])) }}">Edit</a>
                            @endcan
                            @can('delete', $rowMethod)
                            <form action="{{ route('payment-methods.destroy', $rowMethod) }}" method="post" data-confirm="Delete this payment method?">
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
                    <td colspan="8" class="px-4 py-12 text-center text-slate-500">No payment methods match your filters.</td>
                </tr>
            @endforelse
            </tbody>
        </table>
</x-index-table-shell>
	<x-pagination-limit-selector />

<div class="mt-6">{{ $methods->links() }}</div>

<div x-cloak x-show="drawerOpen" class="fixed inset-0 z-40">
    <a href="{{ route('payment-methods.index', $listQuery) }}" class="absolute inset-0 bg-slate-900/40" aria-label="Close"></a>
    <div class="absolute inset-y-0 right-0 w-full max-w-2xl overflow-y-auto bg-white shadow-2xl">
        <div class="sticky top-0 flex items-center justify-between border-b border-slate-200 bg-white px-6 py-4">
            <h2 class="text-lg font-semibold text-slate-900">{{ $isEdit ? 'Edit payment method' : 'New payment method' }}</h2>
            <a href="{{ route('payment-methods.index', $listQuery) }}" class="text-sm text-slate-600 hover:text-slate-900">Close</a>
        </div>
        <div class="p-6">
            <form action="{{ $isEdit ? route('payment-methods.update', $method) : route('payment-methods.store') }}" method="post" class="space-y-4">
                @csrf
                @if ($isEdit)
                    @method('PUT')
                @endif
                @include('payment-methods._form-fields', ['method' => $method, 'locations' => $locations, 'types' => $types])
                @if ($isEdit)
                    @include('payment-methods._wallet-routing', ['method' => $method])
                @endif
                <div class="flex flex-wrap gap-2 border-t border-slate-200 pt-6">
                    <button type="submit" class="rounded-lg bg-slate-900 px-5 py-2.5 text-sm font-medium text-white hover:bg-slate-800">{{ $isEdit ? 'Save changes' : 'Save' }}</button>
                    <a href="{{ route('payment-methods.index', $listQuery) }}" class="rounded-lg border border-slate-300 bg-white px-5 py-2.5 text-sm font-medium text-slate-700 hover:bg-slate-50">Cancel</a>
                </div>
            </form>
        </div>
    </div>
</div>
</div>
@endsection
