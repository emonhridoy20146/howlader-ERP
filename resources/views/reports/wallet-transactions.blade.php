@extends('layouts.app')
@section('title', 'Wallet Transactions')
@section('page-title', 'Wallet Transactions')
@section('content')

<div class="sm:flex sm:items-center">
    <div class="sm:flex-auto">
        <h1 class="text-base font-semibold leading-6 text-slate-900">Wallet Transactions</h1>
        <p class="mt-2 text-sm text-slate-700">Transactions for the selected wallet, grouped by location with an opening balance and running total per branch.</p>
    </div>
    <div class="mt-4 sm:ml-16 sm:mt-0 sm:flex-none">
        <a href="{{ route('reports.index', ['report' => 'wallet-transactions']) }}" class="block rounded-md bg-white px-3 py-2 text-center text-sm font-semibold text-slate-900 shadow-sm ring-1 ring-inset ring-slate-300 hover:bg-slate-50">Back to Reports</a>
    </div>
</div>

<form method="get" action="{{ route('reports.wallet-transactions') }}" class="mt-6 rounded-2xl border border-slate-200 bg-white p-4 shadow-sm ring-1 ring-slate-200">
    @include('reports.partials.wallet-transactions-filter-fields', [
        'filters' => $filters,
        'filterWallets' => $filterWallets ?? collect(),
    ])
    <div class="mt-4 flex flex-wrap items-center gap-2 border-t border-slate-100 pt-3">
        <span class="text-xs font-medium text-slate-500">Export</span>
        @php
            $exportQuery = array_filter([
                'wallet_id' => $filters['wallet_id'] ?? '',
                'date_from' => $filters['date_from'] ?? '',
                'date_to' => $filters['date_to'] ?? '',
                'source' => $filters['source'] ?? '',
            ], static fn ($v) => $v !== null && $v !== '');
        @endphp
        @if (($filters['wallet_id'] ?? '') !== '')
            <a target="_blank" rel="noopener noreferrer" href="{{ route('reports.print', array_merge(['slug' => 'wallet-transactions'], $exportQuery)) }}" class="rounded-lg border border-slate-300 bg-white px-3 py-1.5 text-xs font-medium text-slate-800 hover:bg-slate-50">Print</a>
            <a href="{{ route('reports.export', array_merge(['slug' => 'wallet-transactions', 'format' => 'xlsx'], $exportQuery)) }}" class="rounded-lg border border-slate-300 bg-white px-3 py-1.5 text-xs font-medium text-slate-800 hover:bg-slate-50">Excel</a>
        @endif
    </div>
    <div class="mt-4 flex flex-wrap gap-2">
        <button type="submit" class="rounded-lg bg-slate-800 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-700">Apply</button>
        <a href="{{ route('reports.wallet-transactions') }}" class="rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50">Reset</a>
    </div>
</form>

@if ($filters['wallet_id'] === '')
    <div class="mt-8 rounded-md bg-yellow-50 p-4">
        <div class="flex">
            <div class="flex-shrink-0">
                <svg class="h-5 w-5 text-yellow-400" viewBox="0 0 20 20" fill="currentColor">
                    <path fill-rule="evenodd" d="M8.485 2.495c.673-1.167 2.357-1.167 3.03 0l6.28 10.875c.673 1.167-.17 2.625-1.516 2.625H3.72c-1.347 0-2.189-1.458-1.515-2.625L8.485 2.495zM10 5a.75.75 0 01.75.75v3.5a.75.75 0 01-1.5 0v-3.5A.75.75 0 0110 5zm0 9a1 1 0 100-2 1 1 0 000 2z" clip-rule="evenodd" />
                </svg>
            </div>
            <div class="ml-3">
                <h3 class="text-sm font-medium text-yellow-800">No Wallet Selected</h3>
                <div class="mt-2 text-sm text-yellow-700">
                    <p>Select a wallet above to view transactions.</p>
                </div>
            </div>
        </div>
    </div>
@else
    @if (($filters['source'] ?? '') !== '')
        <p class="mt-4 rounded-lg border border-sky-200 bg-sky-50 px-4 py-2.5 text-sm text-sky-900">
            Filtered to <span class="font-medium">{{ \App\Models\JournalEntry::sources()[$filters['source']] ?? $filters['source'] }}</span>.
            Opening balance and running total reflect this source only, per location.
        </p>
    @endif

    @include('reports.partials.wallet-transactions-source-summary', ['summaryBySource' => $summaryBySource ?? collect()])

    @if (! empty($truncated))
        <p class="mt-4 rounded-lg border border-amber-200 bg-amber-50 px-4 py-2.5 text-sm text-amber-900">
            Showing the first {{ number_format(\App\Services\ReportDataService::STOCK_EXPORT_LIMIT) }} of {{ number_format((int) ($totalMatching ?? 0)) }} transactions. Narrow the date range or export to Excel for the full list (up to the same limit).
        </p>
    @endif

    <div class="mt-8">
        @include('reports.partials.wallet-transactions-by-location', [
            'rows' => $rows ?? collect(),
            'summaryByBranch' => $summaryByBranch ?? collect(),
            'openingBalancesByBranch' => $openingBalancesByBranch ?? collect(),
        ])
    </div>
@endif

@endsection
