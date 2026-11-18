@extends('layouts.app')
@section('title', 'Customer payments by branch')
@section('page-title', 'Customer payments by branch')
@section('content')
<div class="space-y-4">
	<p class="flex justify-end">
		<a href="{{ route('reports.index', ['report' => 'customer-payment-by-branch']) }}" class="text-sm font-medium text-emerald-600 hover:text-emerald-700">← Back to reports</a>
	</p>
	<p class="text-sm text-slate-600">
		Customer receipts (<span class="font-medium">inbound payments</span>) allocated to invoices, grouped by <span class="font-medium">invoice branch</span> and filtered by <span class="font-medium">payment date</span>.
	</p>

	<form method="get" action="{{ route('reports.customer-payment-by-branch') }}" class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm ring-1 ring-slate-200">
		@include('reports.partials.customer-payment-by-branch-filter-fields', ['filters' => $filters, 'filterBranches' => $filterBranches])
		<div class="mt-4 flex flex-wrap items-center gap-2 border-t border-slate-100 pt-3">
			<span class="text-xs font-medium text-slate-500">Export</span>
			@php
				$exportQuery = array_filter([
					'date_from' => $filters['date_from'],
					'date_to' => $filters['date_to'],
					'branch_id' => $filters['branch_id'],
					'collector_id' => $filters['collector_id'] ?? '',
				], static fn ($v) => $v !== null && $v !== '');
			@endphp
			<a target="_blank" rel="noopener noreferrer" href="{{ route('reports.print', array_merge(['slug' => 'customer-payment-by-branch'], $exportQuery)) }}" class="rounded-lg border border-slate-300 bg-white px-3 py-1.5 text-xs font-medium text-slate-800 hover:bg-slate-50">Print</a>
			<a href="{{ route('reports.export', array_merge(['slug' => 'customer-payment-by-branch', 'format' => 'xlsx'], $exportQuery)) }}" class="rounded-lg border border-slate-300 bg-white px-3 py-1.5 text-xs font-medium text-slate-800 hover:bg-slate-50">Excel</a>
		</div>
		<div class="mt-4 flex flex-wrap gap-2">
			<button type="submit" class="rounded-lg bg-slate-800 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-700">Apply</button>
			<a href="{{ route('reports.customer-payment-by-branch') }}" class="rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50">Reset</a>
		</div>
	</form>

	<div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
		<x-status-card label="Allocation lines">
			{{ number_format($kpis['line_count']) }}
		</x-status-card>
		<x-status-card label="Payments">
			{{ number_format($kpis['payment_count']) }}
		</x-status-card>
		<x-status-card label="Total allocated">
			<span class="text-emerald-700">{{ number_format($kpis['total_allocated'], 2) }}</span>
		</x-status-card>
		<x-status-card label="Settlement discount">
			{{ number_format($kpis['total_discount'], 2) }}
		</x-status-card>
	</div>

	<div class="overflow-x-auto overflow-y-hidden rounded-2xl bg-white shadow-sm ring-1 ring-slate-200">
		<table class="min-w-full text-sm whitespace-nowrap">
			<thead class="border-b border-slate-200 bg-slate-50 text-left text-xs font-semibold uppercase tracking-wider text-slate-700">
				<tr>
					<th class="px-4 py-3">Date</th>
					<th class="px-4 py-3">Payment #</th>
					<th class="px-4 py-3">Customer</th>
					<th class="px-4 py-3">Invoice #</th>
					<th class="px-4 py-3 text-right">Allocated</th>
					<th class="px-4 py-3 text-right">Discount</th>
					<th class="px-4 py-3">Method</th>
					<th class="px-4 py-3">Reference</th>
					<th class="px-4 py-3">Ref employee</th>
					<th class="px-4 py-3">Collected by</th>
				</tr>
			</thead>
			<tbody>
				@php $lastBranchId = null; @endphp
				@forelse($rows as $row)
					@if($lastBranchId !== (int) $row->branch_id)
						@php $lastBranchId = (int) $row->branch_id; @endphp
						<tr class="bg-slate-100">
							<td colspan="10" class="px-4 py-2.5 text-sm font-semibold text-slate-900">
								{{ $row->branch_name }}
								@if($row->branch_code)
									<span class="font-normal text-slate-500">({{ $row->branch_code }})</span>
								@endif
							</td>
						</tr>
					@endif
					<tr class="border-b border-slate-100 transition-colors hover:bg-slate-50/70 last:border-0">
						<td class="px-4 py-3 tabular-nums text-slate-800">{{ $row->payment_date }}</td>
						<td class="px-4 py-3 font-medium text-slate-800">{{ $row->payment_number }}</td>
						<td class="px-4 py-3 text-slate-800">
							<span class="text-xs text-slate-500">{{ $row->customer_code }}</span>
							<span class="mx-1 text-slate-300">·</span>
							{{ $row->customer_name }}
						</td>
						<td class="px-4 py-3 text-slate-800">{{ $row->invoice_number }}</td>
						<td class="px-4 py-3 text-right tabular-nums font-medium text-emerald-700">{{ number_format((float) $row->allocated_amount, 2) }}</td>
						<td class="px-4 py-3 text-right tabular-nums text-slate-600">{{ (float) $row->settlement_discount > 0 ? number_format((float) $row->settlement_discount, 2) : '—' }}</td>
						<td class="px-4 py-3 text-slate-700">{{ $row->payment_method_label ?: '—' }}</td>
						<td class="px-4 py-3 text-slate-600">{{ $row->reference_no ?: '—' }}</td>
						<td class="px-4 py-3 text-slate-600">
							@if ($row->payment_referrer_code)
								{{ \App\Models\Employee::labelFromParts($row->payment_referrer_code, $row->payment_referrer_first_name, $row->payment_referrer_last_name) }}
							@else
								—
							@endif
						</td>
						<td class="px-4 py-3 text-slate-700">{{ $row->recorded_by_name ?: 'Unknown' }}</td>
					</tr>
				@empty
					<tr>
						<td colspan="10" class="bg-slate-50/50 px-4 py-12 text-center text-sm font-medium text-slate-500">No customer payments match the filters.</td>
					</tr>
				@endforelse
			</tbody>
			@if($rows->count() > 0)
			<tfoot class="border-t-2 border-slate-200 bg-slate-50/80 font-semibold text-slate-800">
				<tr>
					<td class="px-4 py-3" colspan="4">Totals</td>
					<td class="px-4 py-3 text-right tabular-nums text-emerald-700">{{ number_format($kpis['total_allocated'], 2) }}</td>
					<td class="px-4 py-3 text-right tabular-nums">{{ number_format($kpis['total_discount'], 2) }}</td>
					<td colspan="4"></td>
				</tr>
			</tfoot>
			@endif
		</table>
	</div>

	@include('reports.partials.collector-subtotals', ['totals' => $collectorTotals ?? []])

	<div>{{ $rows->links() }}</div>
</div>
@endsection
