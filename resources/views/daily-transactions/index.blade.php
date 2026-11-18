@extends('layouts.app')
@section('title', 'Daily transactions')
@section('page-title', 'Daily transactions')

@section('content')
@php
    $journalCreateQuery = array_filter(['wallet_id' => request()->query('wallet_id')], fn ($v) => $v !== null && $v !== '');
@endphp

<p class="mb-4 text-sm text-slate-600">
	Date range defaults to <strong>today</strong> ({{ $defaultActivityDate }}). Adjust From / To for another day or period.
	<a href="{{ route('journal-entries.index', request()->query()) }}" class="font-medium text-indigo-600 hover:text-indigo-800">Open full journal</a>
	with the same filters.
</p>

<div class="mb-4 flex flex-col gap-2 sm:flex-row sm:flex-wrap sm:items-center sm:justify-end">
    @can('create', \App\Models\JournalEntry::class)
    <a href="{{ route('journal-entries.create', $journalCreateQuery) }}" class="inline-flex justify-center rounded-lg bg-slate-900 px-4 py-2.5 text-sm font-medium text-white shadow-sm hover:bg-slate-800 sm:py-2">Post journal</a>
    @endcan
</div>

@if (session('status'))
    <div class="mb-4 rounded-lg bg-emerald-50 p-3 text-sm text-emerald-700">{{ session('status') }}</div>
@endif

<x-filter-drawer>
<form method="get" action="{{ route('daily-transactions.index') }}" class="flex flex-col gap-4">
    <div>
        <label class="text-sm font-medium">Branch</label>
        <select name="location_id" class="mt-1 block rounded-lg border border-slate-300 px-3 py-2 text-sm">
            <option value="">All branches</option>
            @foreach($locations as $location)
                <option value="{{ $location->id }}" @selected((string) ($filters['location_id'] ?? '') === (string) $location->id)>{{ $location->name }}@if($location->code) ({{ $location->code }})@endif</option>
            @endforeach
        </select>
    </div>
    <div>
        <label class="text-sm font-medium">Wallet</label>
        <select name="wallet_id" class="mt-1 block min-w-[10rem] rounded-lg border border-slate-300 px-3 py-2 text-sm">
            <option value="">Any wallet</option>
            @foreach($walletsForFilter as $w)
                <option value="{{ $w->id }}" @selected((string) ($filters['wallet_id'] ?? '') === (string) $w->id)>{{ $w->code }} — {{ $w->name }}</option>
            @endforeach
        </select>
        @error('wallet_id')
            <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
        @enderror
    </div>
    <div>
        <label class="text-sm font-medium">Source</label>
        <select name="source" class="mt-1 block rounded-lg border border-slate-300 px-3 py-2 text-sm">
            <option value="">Any</option>
            @foreach($sources as $value => $label)
                <option value="{{ $value }}" @selected(($filters['source'] ?? '') === $value)>{{ $label }}</option>
            @endforeach
        </select>
    </div>
    <div>
        <label class="text-sm font-medium">From</label>
        <input type="date" name="date_from" value="{{ $filters['date_from'] ?? '' }}" class="mt-1 block rounded-lg border border-slate-300 px-3 py-2 text-sm">
        @error('date_from')
            <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
        @enderror
    </div>
    <div>
        <label class="text-sm font-medium">To</label>
        <input type="date" name="date_to" value="{{ $filters['date_to'] ?? '' }}" class="mt-1 block rounded-lg border border-slate-300 px-3 py-2 text-sm">
        @error('date_to')
            <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
        @enderror
    </div>
    <button class="rounded-lg bg-slate-800 px-3 py-2 text-sm text-white">Filter</button>
    <a href="{{ route('daily-transactions.index') }}" class="text-sm text-slate-600 underline">Reset to today</a>
    <a
        href="{{ route('journal-entries.reports.detailed', request()->query()) }}"
        target="_blank"
        rel="noopener noreferrer"
        class="rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm font-medium text-slate-800 shadow-sm hover:bg-slate-50"
    >Detailed report (print)</a>
    <a
        href="{{ route('journal-entries.reports.account-summary', request()->query()) }}"
        target="_blank"
        rel="noopener noreferrer"
        class="rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm font-medium text-slate-800 shadow-sm hover:bg-slate-50"
    >Account summary (print)</a>
</form>
</x-filter-drawer>

@php
    $tot = $journalTotals ?? ['total_debit' => 0.0, 'total_credit' => 0.0, 'entry_count' => 0];
    $balance = (float) $tot['total_debit'] - (float) $tot['total_credit'];
@endphp
<div class="mb-4 grid gap-3 rounded-xl border border-slate-200 bg-slate-50 p-4 sm:grid-cols-2 lg:grid-cols-4">
    <div>
        <p class="text-xs font-medium uppercase tracking-wide text-slate-500">Entries (filtered)</p>
        <p class="mt-1 text-2xl font-semibold tabular-nums text-slate-900">{{ number_format($tot['entry_count'], 0) }}</p>
    </div>
    <div>
        <p class="text-xs font-medium uppercase tracking-wide text-slate-500">Total debits</p>
        <p class="mt-1 text-2xl font-semibold tabular-nums text-slate-900">{{ number_format($tot['total_debit'], 2) }}</p>
    </div>
    <div>
        <p class="text-xs font-medium uppercase tracking-wide text-slate-500">Total credits</p>
        <p class="mt-1 text-2xl font-semibold tabular-nums text-slate-900">{{ number_format($tot['total_credit'], 2) }}</p>
    </div>
    <div>
        <p class="text-xs font-medium uppercase tracking-wide text-slate-500">Debit − credit</p>
        <p class="mt-1 text-2xl font-semibold tabular-nums {{ abs($balance) < 0.01 ? 'text-emerald-700' : 'text-amber-700' }}">{{ number_format($balance, 2) }}</p>
        <p class="mt-1 text-xs text-slate-500">Same filters as the list below.@if(! empty($filters['wallet_id'] ?? '')) Totals include only lines on the selected wallet’s ledger.@endif</p>
    </div>
</div>

<x-index-table-shell title="Journal activity" :meta="$entries->total().' in this list'">
    <table class="index-data-table min-w-[48rem] sm:min-w-full whitespace-nowrap">
        <thead class="bg-slate-50 border-b border-slate-200 text-left text-xs font-semibold uppercase tracking-wider text-slate-700">
            <tr>
                <th class="px-4 py-3">Document</th>
                <th class="px-4 py-3">Date</th>
                <th class="px-4 py-3">Branch</th>
                <th class="px-4 py-3">Source</th>
                <th class="px-4 py-3">Customer</th>
                <th class="px-4 py-3">Description</th>
                <th class="px-4 py-3 text-right">Actions</th>
            </tr>
        </thead>
        <tbody>
            @forelse($entries as $entry)
                <tr class="border-b border-slate-100 transition-colors hover:bg-slate-50/70 last:border-0">
                    <td class="px-4 py-3 font-mono text-xs">{{ $entry->document_no }}</td>
                    <td class="px-4 py-3">{{ $entry->entry_date->format('Y-m-d') }}</td>
                    <td class="px-4 py-3">{{ $entry->branch?->name }}</td>
                    <td class="px-4 py-3">{{ $sources[$entry->source] ?? $entry->source }}</td>
                    <td class="px-4 py-3 max-w-[14rem] truncate text-slate-800">{{ $entry->linkedCustomer()?->name ?? '—' }}</td>
                    <td class="px-4 py-3 max-w-md truncate">{{ $entry->description }}</td>
                    <td class="px-4 py-3 text-right">
                        @can('view', $entry)
                        <x-index-row-actions-menu>
                            <a href="{{ route('journal-entries.show', $entry) }}">Open</a>
                        </x-index-row-actions-menu>
                        @endcan
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="7" class="px-4 py-12 text-center text-sm font-medium text-slate-500 bg-slate-50/50">No journal entries for this range.</td>
                </tr>
            @endforelse
        </tbody>
    </table>
</x-index-table-shell>
	<x-pagination-limit-selector />

<div class="mt-4">{{ $entries->links() }}</div>
@endsection
