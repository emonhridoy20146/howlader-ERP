@extends('layouts.app')
@section('title', 'Daily expenses by branch')
@section('page-title', 'Daily expenses by branch')
@section('content')
@php
    $detailTotals = $detailTotals ?? ['amount' => 0.0];
@endphp
<div class="space-y-4">
	<p class="flex justify-end">
		<a href="{{ route('reports.index', ['report' => 'daily-expenses-by-branch']) }}" class="text-sm font-medium text-emerald-600 hover:text-emerald-700">← Back to reports</a>
	</p>
	<p class="text-sm text-slate-600">
		Totals by <span class="font-medium">entry date</span>, <span class="font-medium">branch</span>, and <span class="font-medium">expense ledger account</span> from <span class="font-medium">posted</span> manual expense journals. Cards show distinct journal postings and total debit to expense accounts.
	</p>

	<form method="get" action="{{ route('reports.daily-expenses-by-branch') }}" class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm ring-1 ring-slate-200">
		@include('reports.partials.daily-expenses-by-branch-filter-fields', ['filters' => $filters, 'filterBranches' => $filterBranches, 'filterExpenseAccounts' => $filterExpenseAccounts])
		<div class="mt-4 flex flex-wrap items-center gap-2 border-t border-slate-100 pt-3">
			<span class="text-xs font-medium text-slate-500">Export</span>
			@php
				$exportQuery = array_filter([
					'date_from' => $filters['date_from'],
					'date_to' => $filters['date_to'],
					'branch_id' => $filters['branch_id'],
					'ledger_account_id' => $filters['ledger_account_id'],
				], static fn ($v) => $v !== null && $v !== '');
			@endphp
			<a target="_blank" rel="noopener noreferrer" href="{{ route('reports.print', array_merge(['slug' => 'daily-expenses-by-branch'], $exportQuery)) }}" class="rounded-lg border border-slate-300 bg-white px-3 py-1.5 text-xs font-medium text-slate-800 hover:bg-slate-50">Print</a>
			<a href="{{ route('reports.export', array_merge(['slug' => 'daily-expenses-by-branch', 'format' => 'xlsx'], $exportQuery)) }}" class="rounded-lg border border-slate-300 bg-white px-3 py-1.5 text-xs font-medium text-slate-800 hover:bg-slate-50">Excel</a>
		</div>
		<div class="mt-4 flex flex-wrap gap-2">
			<button type="submit" class="rounded-lg bg-slate-800 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-700">Apply</button>
			<a href="{{ route('reports.daily-expenses-by-branch') }}" class="rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50">Reset</a>
		</div>
	</form>

	<div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
		<x-status-card label="Detail rows">
			{{ number_format($kpis['detail_rows']) }}
		</x-status-card>
		<x-status-card label="Distinct postings">
			{{ number_format($kpis['distinct_entries']) }}
		</x-status-card>
		<x-status-card label="Total expense">
			<span class="text-rose-700">{{ number_format($kpis['total_amount'], 2) }}</span>
		</x-status-card>
	</div>

	<div class="overflow-x-auto overflow-y-hidden rounded-2xl bg-white shadow-sm ring-1 ring-slate-200">
		<table class="min-w-full text-sm whitespace-nowrap">
			<thead class="border-b border-slate-200 bg-slate-50 text-left text-xs font-semibold uppercase tracking-wider text-slate-700">
				<tr>
					<th class="px-4 py-3">Date</th>
					<th class="px-4 py-3">Expense account</th>
					<th class="px-4 py-3">Code</th>
					<th class="px-4 py-3 text-right">Amount</th>
					<th class="px-4 py-3 text-right">Postings</th>
				</tr>
			</thead>
			<tbody>
				@php $lastBranchId = null; @endphp
				@forelse($rows as $row)
					@if($lastBranchId !== (int) $row->branch_id)
						@php $lastBranchId = (int) $row->branch_id; @endphp
						<tr class="bg-slate-100">
							<td colspan="5" class="px-4 py-2.5 text-sm font-semibold text-slate-900">
								{{ $row->branch_name }}
								@if($row->branch_code)
									<span class="font-normal text-slate-500">({{ $row->branch_code }})</span>
								@endif
							</td>
						</tr>
					@endif
					<tr class="border-b border-slate-100 transition-colors hover:bg-slate-50/70 last:border-0">
						<td class="px-4 py-3 tabular-nums text-slate-800">{{ \Illuminate\Support\Carbon::parse($row->entry_date)->toDateString() }}</td>
						<td class="px-4 py-3 text-slate-800">{{ $row->account_name }}</td>
						<td class="px-4 py-3 font-mono text-xs text-slate-600">{{ $row->account_code }}</td>
						<td class="px-4 py-3 text-right tabular-nums font-medium text-rose-800">{{ number_format((float) $row->amount, 2) }}</td>
						<td class="px-4 py-3 text-right tabular-nums">{{ number_format((int) $row->slice_entry_count) }}</td>
					</tr>
				@empty
					<tr>
						<td colspan="5" class="bg-slate-50/50 px-4 py-12 text-center text-sm font-medium text-slate-500">No expenses match the filters.</td>
					</tr>
				@endforelse
			</tbody>
			@if($rows->count() > 0)
			<tfoot class="border-t-2 border-slate-200 bg-slate-50/80 font-semibold text-slate-800">
				<tr>
					<td class="px-4 py-3" colspan="3">Totals (grid)</td>
					<td class="px-4 py-3 text-right tabular-nums text-rose-800">{{ number_format($detailTotals['amount'], 2) }}</td>
					<td class="px-4 py-3 text-right tabular-nums text-slate-500">—</td>
				</tr>
				<tr class="border-t border-slate-200 text-sm font-normal">
					<td class="px-4 py-3" colspan="3">Distinct postings · Total expense (ledger)</td>
					<td class="px-4 py-3 text-right tabular-nums" colspan="2">
						<span class="tabular-nums">{{ number_format($kpis['distinct_entries']) }}</span>
						<span class="mx-1 text-slate-300">·</span>
						<span class="tabular-nums text-rose-800">{{ number_format($kpis['total_amount'], 2) }}</span>
					</td>
				</tr>
			</tfoot>
			@endif
		</table>
	</div>

	<div>{{ $rows->links() }}</div>
</div>
@endsection
