@extends('layouts.app')
@section('title', 'Income & expenditure statement')
@section('page-title', 'Income & expenditure statement')
@section('content')
@php
    $exportQuery = array_filter([
        'date_from' => $filters['date_from'],
        'date_to' => $filters['date_to'],
        'branch_id' => $filters['branch_id'],
    ], static fn ($v) => $v !== null && $v !== '');
    $periodLabel = \Illuminate\Support\Carbon::parse($periodFrom)->format('d-M-Y').' to '.\Illuminate\Support\Carbon::parse($periodTo)->format('d-M-Y');
@endphp
<div class="space-y-4">
    <p class="flex justify-end">
        <a href="{{ route('reports.index', ['report' => 'income-expenditure-statement']) }}" class="text-sm font-medium text-emerald-600 hover:text-emerald-700">← Back to reports</a>
    </p>
    <p class="text-sm text-slate-600">
        Posted journal lines grouped by expense and income ledger accounts.
        <span class="font-medium">{{ $branchLabel }}</span> · {{ $periodLabel }}
    </p>

    <form method="get" action="{{ route('reports.income-expenditure-statement') }}" class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm ring-1 ring-slate-200">
        @include('reports.partials.financial-report-filter-fields', ['filters' => $filters, 'filterBranches' => $filterBranches])
        <div class="mt-4 flex flex-wrap items-center gap-2 border-t border-slate-100 pt-3">
            <span class="text-xs font-medium text-slate-500">Export</span>
            <a target="_blank" rel="noopener noreferrer" href="{{ route('reports.print', array_merge(['slug' => 'income-expenditure-statement'], $exportQuery)) }}" class="rounded-lg border border-slate-300 bg-white px-3 py-1.5 text-xs font-medium text-slate-800 hover:bg-slate-50">Print</a>
        </div>
        <div class="mt-4 flex flex-wrap gap-2">
            <button type="submit" class="rounded-lg bg-slate-800 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-700">Apply</button>
            <a href="{{ route('reports.income-expenditure-statement') }}" class="rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50">Reset</a>
        </div>
    </form>

    <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
        <x-status-card label="Expenditure (period)">{{ number_format($expenditureMonthTotal, 2) }}</x-status-card>
        <x-status-card label="Income (period)">{{ number_format($incomeMonthTotal, 2) }}</x-status-card>
        <x-status-card label="Expenditure (YTD)">{{ number_format($expenditureYearTotal, 2) }}</x-status-card>
        <x-status-card label="Income (YTD)">{{ number_format($incomeYearTotal, 2) }}</x-status-card>
    </div>

    <div class="grid gap-4 lg:grid-cols-2">
        <div class="overflow-x-auto overflow-y-hidden rounded-2xl bg-white shadow-sm ring-1 ring-slate-200">
            <div class="border-b border-slate-200 bg-slate-50 px-4 py-3 text-sm font-semibold text-slate-800">Expenditure</div>
            <table class="min-w-full text-sm whitespace-nowrap">
                <thead class="bg-white text-left text-xs font-semibold uppercase tracking-wide text-slate-600">
                    <tr>
                        <th class="px-4 py-3">Account</th>
                        <th class="px-4 py-3 text-right">Period</th>
                        <th class="px-4 py-3 text-right">Year to date</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse ($expenditureRows as $row)
                        <tr>
                            <td class="px-4 py-3">{{ $row->account_code }}, {{ $row->account_name }}</td>
                            <td class="px-4 py-3 text-right tabular-nums">{{ number_format((float) $row->month_amount, 2) }}</td>
                            <td class="px-4 py-3 text-right tabular-nums">{{ number_format((float) $row->year_amount, 2) }}</td>
                        </tr>
                    @empty
                        <tr><td class="px-4 py-8 text-center text-slate-500" colspan="3">No expenditure in scope.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="overflow-x-auto overflow-y-hidden rounded-2xl bg-white shadow-sm ring-1 ring-slate-200">
            <div class="border-b border-slate-200 bg-slate-50 px-4 py-3 text-sm font-semibold text-slate-800">Income</div>
            <table class="min-w-full text-sm whitespace-nowrap">
                <thead class="bg-white text-left text-xs font-semibold uppercase tracking-wide text-slate-600">
                    <tr>
                        <th class="px-4 py-3">Account</th>
                        <th class="px-4 py-3 text-right">Period</th>
                        <th class="px-4 py-3 text-right">Year to date</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse ($incomeRows as $row)
                        <tr>
                            <td class="px-4 py-3">{{ $row->account_code }}, {{ $row->account_name }}</td>
                            <td class="px-4 py-3 text-right tabular-nums">{{ number_format((float) $row->month_amount, 2) }}</td>
                            <td class="px-4 py-3 text-right tabular-nums">{{ number_format((float) $row->year_amount, 2) }}</td>
                        </tr>
                    @empty
                        <tr><td class="px-4 py-8 text-center text-slate-500" colspan="3">No income in scope.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
@endsection
