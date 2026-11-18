@extends('layouts.app')
@section('title', 'Journal')
@section('page-title', 'Journal entries')
@section('content')
@php
    $journalCreateQuery = array_filter(['wallet_id' => request()->query('wallet_id')], fn ($v) => $v !== null && $v !== '');
    $journalPostLinks = collect([
        ['label' => 'Operating expense', 'kind' => 'expense', 'can' => auth()->user()?->can('createExpense', \App\Models\JournalEntry::class)],
        ['label' => 'Other income', 'kind' => 'income', 'can' => auth()->user()?->can('createIncome', \App\Models\JournalEntry::class)],
        ['label' => 'Wallet transfer', 'kind' => 'transfer', 'can' => auth()->user()?->can('createTransfer', \App\Models\JournalEntry::class)],
        ['label' => 'Opening balance', 'kind' => 'opening', 'can' => auth()->user()?->can('createWalletOpeningBalance', \App\Models\JournalEntry::class)],
    ])->filter(fn (array $row) => $row['can'])->values();
@endphp
<div class="mb-4 flex flex-col gap-2 sm:flex-row sm:flex-wrap sm:items-center sm:justify-end">
    @if ($journalPostLinks->isNotEmpty())
        @if ($journalPostLinks->count() === 1)
            @php $link = $journalPostLinks->first(); @endphp
            <a href="{{ route('journal-entries.create', array_merge($journalCreateQuery, ['kind' => $link['kind']])) }}" class="inline-flex justify-center rounded-lg bg-slate-900 px-4 py-2.5 text-sm font-medium text-white shadow-sm hover:bg-slate-800 sm:py-2">Post {{ strtolower($link['label']) }}</a>
        @else
            <div class="relative inline-flex" x-data="{ open: false }">
                <button type="button" @click="open = !open" @click.outside="open = false" class="inline-flex items-center gap-1.5 rounded-lg bg-slate-900 px-4 py-2.5 text-sm font-medium text-white shadow-sm hover:bg-slate-800 sm:py-2">
                    Post journal
                    <svg class="h-4 w-4 opacity-80" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 0 1 1.06.02L10 10.94l3.71-3.71a.75.75 0 1 1 1.08 1.04l-4.25 4.25a.75.75 0 0 1-1.08 0L5.21 8.27a.75.75 0 0 1 .02-1.06Z" clip-rule="evenodd" /></svg>
                </button>
                <div x-show="open" x-cloak class="absolute right-0 top-full z-20 mt-1 min-w-[12rem] overflow-hidden rounded-lg border border-slate-200 bg-white py-1 shadow-lg">
                    @foreach ($journalPostLinks as $link)
                        <a href="{{ route('journal-entries.create', array_merge($journalCreateQuery, ['kind' => $link['kind']])) }}" class="block px-4 py-2 text-sm text-slate-700 hover:bg-slate-50">{{ $link['label'] }}</a>
                    @endforeach
                </div>
            </div>
        @endif
    @endif
</div>

@if (session('status'))
    <div class="mb-4 rounded-lg bg-emerald-50 p-3 text-sm text-emerald-700">{{ session('status') }}</div>
@endif
@if (session('error'))
    <div class="mb-4 rounded-lg bg-red-50 p-3 text-sm text-red-700">{{ session('error') }}</div>
@endif

<x-filter-drawer>
<form method="get" action="{{ route('journal-entries.index') }}" class="flex flex-col gap-4">
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
    <a href="{{ route('journal-entries.index') }}" class="text-sm text-slate-600 underline">Reset</a>
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
        <p class="mt-1 text-xs text-slate-500">Same filters as the list below (use dates to limit the period).@if(! empty($filters['wallet_id'] ?? '')) Totals include only lines on the selected wallet’s ledger.@endif</p>
    </div>
</div>

@if (! empty($filters['wallet_id'] ?? ''))
<p class="-mt-2 mb-3 max-w-3xl text-xs text-slate-500">Debit and credit columns reflect only lines posted to the selected wallet’s ledger (same scope as the summary totals above).</p>
@endif
<x-index-table-shell title="Journal entries" :meta="$entries->total().' in this list'">
    <table class="index-data-table min-w-[48rem] sm:min-w-full whitespace-nowrap">
        <thead class="bg-slate-50 border-b border-slate-200 text-left text-xs font-semibold uppercase tracking-wider text-slate-700">
            <tr>
                <th class="px-4 py-3">Document</th>
                <th class="px-4 py-3">Date</th>
                <th class="px-4 py-3">Branch</th>
                <th class="px-4 py-3">Source</th>
                <th class="px-4 py-3">Customer</th>
                <th class="px-4 py-3">Description</th>
                <th class="px-4 py-3 text-right">Debit</th>
                <th class="px-4 py-3 text-right">Credit</th>
                <th class="px-4 py-3 text-right">Actions</th>
            </tr>
        </thead>
        <tbody>
            @forelse($entries as $entry)
                <tr class="border-b border-slate-100 transition-colors hover:bg-slate-50/70 last:border-0">
                    <td class="px-4 py-3 font-mono text-xs">
                        {{ $entry->document_no }}
                        @if ($entry->status === 'draft')
                            <span class="ml-2 inline-flex items-center rounded-full bg-amber-50 px-2 py-0.5 text-xs font-medium text-amber-700 ring-1 ring-inset ring-amber-600/20">Draft</span>
                        @endif
                    </td>
                    <td class="px-4 py-3">{{ $entry->entry_date->format('Y-m-d') }}</td>
                    <td class="px-4 py-3">{{ $entry->branch?->name }}</td>
                    <td class="px-4 py-3">{{ $sources[$entry->source] ?? $entry->source }}</td>
                    <td class="px-4 py-3 max-w-[14rem] truncate text-slate-800">{{ $entry->linkedCustomer()?->name ?? '—' }}</td>
                    <td class="px-4 py-3 max-w-md truncate">{{ $entry->description }}</td>
                    <td class="px-4 py-3 text-right tabular-nums">{{ number_format((float) ($entry->lines_debit_sum ?? 0), 2) }}</td>
                    <td class="px-4 py-3 text-right tabular-nums">{{ number_format((float) ($entry->lines_credit_sum ?? 0), 2) }}</td>
                    <td class="px-4 py-3 text-right">
                        @can('view', $entry)
                        <x-index-row-actions-menu>
                            <a href="{{ route('journal-entries.show', $entry) }}" class="index-actions-menu-item">Open</a>
                            @if ($entry->isEditableManualEntry())
                                @can('update', $entry)
                                    <a href="{{ route('journal-entries.edit', array_filter(['journal_entry' => $entry, 'wallet_id' => request()->query('wallet_id')], fn ($v) => $v !== null && $v !== '')) }}" class="index-actions-menu-item">Edit</a>
                                @endcan
                                @can('delete', $entry)
                                    <form action="{{ route('journal-entries.destroy', $entry) }}" method="post" data-confirm="Delete this journal entry? Wallet balances will be updated.">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="index-actions-menu-item index-actions-menu-item--danger w-full">Delete</button>
                                    </form>
                                @endcan
                            @elseif ($entry->status === 'draft')
                                @can('delete', $entry)
                                    <form action="{{ route('journal-entries.destroy', $entry) }}" method="post" data-confirm="Delete this draft journal entry?">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="index-actions-menu-item index-actions-menu-item--danger w-full">Delete</button>
                                    </form>
                                @endcan
                            @endif
                            @can('delete-payment-allocation')
                                @php $linkedPaymentRemoval = $entry->linkedPaymentRemovalTarget(); @endphp
                                @if ($linkedPaymentRemoval)
                                    <form
                                        action="{{ route('journal-entries.linked-payment.destroy', $entry) }}"
                                        method="post"
                                        data-confirm="Remove this payment? Customer balance, invoice totals, and all related journal entries will be reversed."
                                    >
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="index-actions-menu-item index-actions-menu-item--danger w-full">Remove payment</button>
                                    </form>
                                @endif
                            @endcan
                        </x-index-row-actions-menu>
                        @endcan
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="9" class="px-4 py-12 text-center text-sm font-medium text-slate-500 bg-slate-50/50">No journal entries.</td>
                </tr>
            @endforelse
        </tbody>
    </table>
</x-index-table-shell>
	<x-pagination-limit-selector />

<div class="mt-4">{{ $entries->links() }}</div>
@endsection
