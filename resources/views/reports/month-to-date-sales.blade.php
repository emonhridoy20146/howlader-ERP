@extends('layouts.app')
@section('title', 'Month to Date Sales')
@section('page-title', 'Month to Date Sales')
@section('content')
<div class="space-y-4">
	<p class="text-sm text-slate-600">Every inbound payment received in the selected period, one row per payment date. Includes collections on earlier invoices and settled period sales without payment allocations. Invoice status does not filter rows — only branch, search, sale type, and optional payment status filters apply.</p>

	<x-filter-drawer>
	<form method="get" action="{{ route('reports.month-to-date-sales') }}" class="flex flex-col gap-4">
		@include('reports.partials.sales-filter-fields', ['filters' => $filters, 'filterBranches' => $filterBranches])
		<div class="flex flex-wrap items-center gap-2 border-t border-slate-100 pt-3">
			<span class="text-xs font-medium text-slate-500">Export</span>
			<a target="_blank" rel="noopener noreferrer" href="{{ route('reports.print', array_merge(['slug' => 'month-to-date-sales'], request()->only(['q', 'date_from', 'date_to', 'branch_id', 'payment_status', 'sale_type']))) }}" class="rounded-lg border border-slate-300 bg-white px-3 py-1.5 text-xs font-medium text-slate-800 hover:bg-slate-50">Print</a>
			<a href="{{ route('reports.export', array_merge(['slug' => 'month-to-date-sales', 'format' => 'xlsx'], request()->only(['q', 'date_from', 'date_to', 'branch_id', 'payment_status', 'sale_type']))) }}" class="rounded-lg border border-slate-300 bg-white px-3 py-1.5 text-xs font-medium text-slate-800 hover:bg-slate-50">Excel</a>
		</div>
		<x-index-filter-actions :reset-url="route('reports.month-to-date-sales')" />
	</form>
</x-filter-drawer>

	<p class="rounded-xl border border-slate-200 bg-slate-50 px-4 py-2.5 text-xs leading-relaxed text-slate-600">
		<span class="font-semibold text-slate-700">Report summary:</span>
		{{ $filterSummary }}
	</p>

	@include('reports.partials.month-to-date-sales-type-summary', ['summaryByType' => $summaryByType ?? collect()])

	@php
		$branchSummaries = collect($summaryByBranch ?? [])->keyBy('branch_id');
		$pageRows = $rows instanceof \Illuminate\Pagination\AbstractPaginator ? $rows->getCollection() : collect($rows ?? []);
		$groupedPageRows = $pageRows->groupBy(fn (object $invoice): string => (string) ($invoice->branch_id ?? 0));
	@endphp

	@if ($pageRows->isEmpty())
		<div class="rounded-2xl border border-dashed border-slate-300 bg-slate-50 px-4 py-12 text-center text-sm font-medium text-slate-500">
			No payments in this period match the current filters.
		</div>
	@else
		<div class="space-y-4">
			@foreach ($groupedPageRows as $branchId => $branchRows)
				@php
					$branchSummary = $branchSummaries->get((int) $branchId, [
						'branch_name' => $branchRows->first()?->branch?->name ?? 'Unassigned',
						'branch_code' => $branchRows->first()?->branch?->code ?? '',
						'invoices' => $branchRows->pluck('id')->unique()->count(),
						'payment_lines' => $branchRows->count(),
						'grand_total' => $branchRows->sum(fn ($invoice) => (float) $invoice->grand_total),
						'amount_paid' => $branchRows->sum(fn ($invoice) => (float) ($invoice->cash_at_transaction ?? $invoice->cash_paid_total ?? max(0, (float) $invoice->amount_paid - (float) ($invoice->settlement_discount_total ?? 0)))),
						'settlement_discount' => $branchRows->sum(fn ($invoice) => (float) ($invoice->discount_at_transaction ?? $invoice->settlement_discount_total ?? 0)),
						'period_cash' => $branchRows->sum(fn ($invoice) => (float) ($invoice->period_cash ?? $invoice->period_paid ?? 0)),
						'period_discount' => $branchRows->sum(fn ($invoice) => (float) ($invoice->period_discount ?? 0)),
						'period_paid' => $branchRows->sum(fn ($invoice) => (float) ($invoice->period_paid ?? 0)),
						'amount_due' => $branchRows->sum(fn ($invoice) => (float) ($invoice->due_at_transaction ?? $invoice->amount_due)),
						'by_type' => [],
					]);
				@endphp
				@include('reports.partials.month-to-date-sales-branch-section', [
					'branchSummary' => $branchSummary,
					'branchRows' => $branchRows->sortBy(fn (object $invoice): string => (string) ($invoice->period_payment_dates ?? ''))->values(),
					'pageLabel' => $rows instanceof \Illuminate\Pagination\AbstractPaginator && $rows->hasPages()
						? $branchRows->count().' payment lines on this page'
						: null,
				])
			@endforeach
		</div>
	@endif

	@if ($rows instanceof \Illuminate\Pagination\AbstractPaginator)
		<div>{{ $rows->links() }}</div>
	@endif
</div>
@endsection
