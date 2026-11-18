@extends('layouts.app')
@section('title', 'Overdue receivables')
@section('page-title', 'Overdue receivables')
@section('content')
@php
	$section = $filters['section'] ?? 'all';
	$showCredit = $section === 'all' || $section === 'credit';
	$showOverdue = $section === 'all' || $section === 'overdue';
	$showMatured = $section === 'all' || $section === 'matured';
	$exportQuery = array_filter([
		'q' => $filters['q'],
		'branch_id' => $filters['branch_id'],
		'section' => $section !== 'all' ? $section : null,
	]);
@endphp
<div class="space-y-6">
	<div class="rounded-2xl border border-amber-200/80 bg-amber-50/50 px-4 py-3 text-sm text-slate-700">
		Filter by <span class="font-medium">type</span> (credit, overdue installments, or matured plans) and <span class="font-medium">branch</span>. Results are grouped by branch; month counts are whole calendar months since the due date.
	</div>

	<form method="get" action="{{ route('reports.credit-overdue') }}" class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm ring-1 ring-slate-200">
		@include('reports.partials.credit-overdue-filter-fields', [
			'filters' => $filters,
			'filterBranches' => $filterBranches,
		])
		<div class="mt-4 flex flex-wrap gap-2">
			<button type="submit" class="rounded-lg bg-slate-800 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-700">Apply</button>
			<a href="{{ route('reports.credit-overdue') }}" class="rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50">Reset</a>
		</div>
	</form>

	<div class="flex flex-wrap items-center justify-between gap-3">
		<div class="grid flex-1 gap-3 sm:grid-cols-2 xl:grid-cols-3">
			@if ($showCredit)
				<x-status-card label="Overdue credit invoices">
					<span class="text-amber-800">{{ number_format($kpis['invoices']) }}</span>
				</x-status-card>
				<x-status-card label="Credit total due">
					<span class="text-rose-700">{{ number_format($kpis['amount_due'], 2) }}</span>
				</x-status-card>
			@endif
			@if ($showOverdue)
				<x-status-card label="Overdue installment schedules">
					<span class="text-amber-800">{{ number_format($kpis['installment_schedules']) }}</span>
				</x-status-card>
				<x-status-card label="Installment remaining">
					<span class="text-rose-700">{{ number_format($kpis['installment_remaining'], 2) }}</span>
				</x-status-card>
			@endif
			@if ($showMatured)
				<x-status-card label="Matured plans">
					<span class="text-amber-800">{{ number_format($kpis['matured_invoices']) }}</span>
				</x-status-card>
				<x-status-card label="Matured plans due">
					<span class="text-rose-700">{{ number_format($kpis['matured_amount_due'], 2) }}</span>
				</x-status-card>
			@endif
		</div>
		<div class="flex shrink-0 flex-wrap items-center gap-2">
			<a target="_blank" rel="noopener noreferrer" href="{{ route('reports.print', array_merge(['slug' => 'credit-overdue'], $exportQuery)) }}" class="rounded-lg border border-slate-300 bg-white px-3 py-2 text-xs font-semibold text-slate-800 shadow-sm hover:bg-slate-50">Print</a>
			<a href="{{ route('reports.export', array_merge(['slug' => 'credit-overdue', 'format' => 'xlsx'], $exportQuery)) }}" class="rounded-lg border border-slate-300 bg-white px-3 py-2 text-xs font-semibold text-slate-800 shadow-sm hover:bg-slate-50">Excel</a>
		</div>
	</div>

	@include('reports.partials.credit-overdue-by-branch', [
		'creditRows' => $showCredit ? $rows : collect(),
		'installmentRows' => $showOverdue ? $installmentRows : collect(),
		'maturedRows' => $showMatured ? $maturedRows : collect(),
		'printMode' => false,
	])

	@if (($showCredit && $rows->hasPages()) || ($showOverdue && $installmentRows->hasPages()) || ($showMatured && $maturedRows->hasPages()))
		<div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm ring-1 ring-slate-200">
			<p class="mb-3 text-xs font-semibold uppercase tracking-wide text-slate-500">Pagination</p>
			<div class="grid gap-4 lg:grid-cols-3">
				@if ($showCredit && $rows->hasPages())
					<div>
						<p class="mb-1 text-xs font-medium text-slate-600">Credit invoices</p>
						{{ $rows->links() }}
					</div>
				@endif
				@if ($showOverdue && $installmentRows->hasPages())
					<div>
						<p class="mb-1 text-xs font-medium text-slate-600">Installment schedules</p>
						{{ $installmentRows->links() }}
					</div>
				@endif
				@if ($showMatured && $maturedRows->hasPages())
					<div>
						<p class="mb-1 text-xs font-medium text-slate-600">Matured plans</p>
						{{ $maturedRows->appends(request()->except('matured_page'))->links() }}
					</div>
				@endif
			</div>
		</div>
	@endif
</div>
@endsection
