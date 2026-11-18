@extends('layouts.app')
@section('title', 'Profit loss sheet')
@section('page-title', 'Profit loss sheet')
@section('content')
@php
    $exportQuery = array_filter([
        'date_from' => $filters['date_from'],
        'date_to' => $filters['date_to'],
        'branch_id' => $filters['branch_id'],
    ], static fn ($v) => $v !== null && $v !== '');
@endphp
<div class="space-y-4">
    <p class="flex justify-end">
        <a href="{{ route('reports.index', ['report' => 'profit-loss-sheet']) }}" class="text-sm font-medium text-emerald-600 hover:text-emerald-700">← Back to reports</a>
    </p>
    <p class="text-sm text-slate-600">
        Sales from posted invoices, cost of goods from sale-issue stock movements, and operating expenses from posted manual expense journals.
        <span class="font-medium">{{ $branchLabel }}</span> · {{ $dateRangeLabel }}
    </p>

    <form method="get" action="{{ route('reports.profit-loss-sheet') }}" class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm ring-1 ring-slate-200">
        @include('reports.partials.financial-report-filter-fields', ['filters' => $filters, 'filterBranches' => $filterBranches])
        <div class="mt-4 flex flex-wrap items-center gap-2 border-t border-slate-100 pt-3">
            <span class="text-xs font-medium text-slate-500">Export</span>
            <a target="_blank" rel="noopener noreferrer" href="{{ route('reports.print', array_merge(['slug' => 'profit-loss-sheet'], $exportQuery)) }}" class="rounded-lg border border-slate-300 bg-white px-3 py-1.5 text-xs font-medium text-slate-800 hover:bg-slate-50">Print</a>
        </div>
        <div class="mt-4 flex flex-wrap gap-2">
            <button type="submit" class="rounded-lg bg-slate-800 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-700">Apply</button>
            <a href="{{ route('reports.profit-loss-sheet') }}" class="rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50">Reset</a>
        </div>
    </form>

    <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
        <x-status-card label="Sales">{{ number_format($sales, 2) }}</x-status-card>
        <x-status-card label="Cost of goods sold">{{ number_format($cogs, 2) }}</x-status-card>
        <x-status-card label="Gross profit">
            <span class="{{ $grossProfit >= 0 ? 'text-emerald-700' : 'text-rose-700' }}">{{ number_format($grossProfit, 2) }}</span>
        </x-status-card>
        <x-status-card label="Net profit">
            <span class="{{ $netProfit >= 0 ? 'text-emerald-700' : 'text-rose-700' }}">{{ number_format($netProfit, 2) }}</span>
        </x-status-card>
    </div>

    <div class="overflow-x-auto overflow-y-hidden rounded-2xl bg-white shadow-sm ring-1 ring-slate-200">
        <table class="min-w-full text-sm whitespace-nowrap">
            <thead class="bg-slate-50 text-left text-xs font-semibold uppercase tracking-wide text-slate-600">
                <tr>
                    <th class="px-4 py-3">Section</th>
                    <th class="px-4 py-3">Line</th>
                    <th class="px-4 py-3 text-right">Amount</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                <tr class="bg-slate-50/60">
                    <td class="px-4 py-3 font-semibold text-slate-800" colspan="3">Incomes</td>
                </tr>
                <tr>
                    <td class="px-4 py-3"></td>
                    <td class="px-4 py-3">Sales</td>
                    <td class="px-4 py-3 text-right tabular-nums">{{ number_format($sales, 2) }}</td>
                </tr>
                <tr class="bg-slate-50/60">
                    <td class="px-4 py-3 font-semibold text-slate-800" colspan="3">Cost of sales</td>
                </tr>
                <tr>
                    <td class="px-4 py-3"></td>
                    <td class="px-4 py-3">Cost of goods sold</td>
                    <td class="px-4 py-3 text-right tabular-nums">{{ number_format($cogs, 2) }}</td>
                </tr>
                <tr class="font-semibold">
                    <td class="px-4 py-3" colspan="2">Gross profit</td>
                    <td class="px-4 py-3 text-right tabular-nums">{{ number_format($grossProfit, 2) }}</td>
                </tr>
                <tr class="bg-slate-50/60">
                    <td class="px-4 py-3 font-semibold text-slate-800" colspan="3">Expenses</td>
                </tr>
                @forelse ($expenses as $expense)
                    <tr>
                        <td class="px-4 py-3"></td>
                        <td class="px-4 py-3">{{ $expense->account_name }}</td>
                        <td class="px-4 py-3 text-right tabular-nums">{{ number_format((float) $expense->amount, 2) }}</td>
                    </tr>
                @empty
                    <tr>
                        <td class="px-4 py-3 text-slate-500" colspan="3">No operating expenses in this period.</td>
                    </tr>
                @endforelse
                <tr class="font-semibold">
                    <td class="px-4 py-3" colspan="2">Net profit</td>
                    <td class="px-4 py-3 text-right tabular-nums">{{ number_format($netProfit, 2) }}</td>
                </tr>
            </tbody>
        </table>
    </div>
</div>
@endsection
