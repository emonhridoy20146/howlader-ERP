@extends('layouts.app')
@section('title', 'High-risk customers')
@section('page-title', 'High-risk customers')
@section('content')
@php
	$exportQuery = request()->only(['q', 'branch_id', 'grade', 'classification', 'status', 'overdue_min', 'overdue_max', 'min_days_late', 'has_guarantor', 'guarantor', 'sort', 'dir']);
	$sortLink = fn (string $col) => request()->fullUrlWithQuery(['sort' => $col, 'dir' => ($filters['sort'] === $col && $filters['dir'] === 'desc') ? 'asc' : 'desc', 'page' => null]);
@endphp
<div class="space-y-4">
	<p class="text-sm text-slate-600">
		Customers graded {{ implode('/', config('customer_risk.high_risk_grades')) }} or showing a late-payment trend. Figures come from nightly and event-driven risk snapshots.
	</p>

	<x-filter-drawer>
<form method="get" action="{{ route('reports.high-risk-customers') }}" class="flex flex-col gap-4">
		@include('reports.partials.high-risk-customers-filter-fields', ['filters' => $filters, 'filterBranches' => $filterBranches])
		<div class="flex flex-wrap items-center gap-2 border-t border-slate-100 pt-3">
			<span class="text-xs font-medium text-slate-500">Export</span>
			<a target="_blank" rel="noopener noreferrer" href="{{ route('reports.print', array_merge(['slug' => 'high-risk-customers'], $exportQuery)) }}" class="rounded-lg border border-slate-300 bg-white px-3 py-1.5 text-xs font-medium text-slate-800 hover:bg-slate-50">Print</a>
			<a href="{{ route('reports.export', array_merge(['slug' => 'high-risk-customers', 'format' => 'pdf'], $exportQuery)) }}" class="rounded-lg border border-slate-300 bg-white px-3 py-1.5 text-xs font-medium text-slate-800 hover:bg-slate-50">PDF</a>
			<a href="{{ route('reports.export', array_merge(['slug' => 'high-risk-customers', 'format' => 'xlsx'], $exportQuery)) }}" class="rounded-lg border border-slate-300 bg-white px-3 py-1.5 text-xs font-medium text-slate-800 hover:bg-slate-50">Excel</a>
		</div>
		<x-index-filter-actions :reset-url="route('reports.high-risk-customers')" />
	</form>
</x-filter-drawer>

	<div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
		<x-status-card label="Customers">{{ number_format($kpis['customers']) }}</x-status-card>
		<x-status-card label="Total overdue"><span class="text-rose-700">{{ number_format($kpis['total_overdue'], 2) }}</span></x-status-card>
		<x-status-card label="Total outstanding">{{ number_format($kpis['total_outstanding'], 2) }}</x-status-card>
		<x-status-card label="Avg days late">{{ $kpis['avg_days_late'] }}</x-status-card>
	</div>
	<div class="flex flex-wrap gap-2 text-xs">
		@foreach (['A', 'B', 'C', 'D', 'E', 'N/A'] as $g)
			@if (($kpis['by_grade'][$g] ?? 0) > 0)
				<span class="rounded-full px-2.5 py-1 font-semibold {{ \App\Models\CustomerRiskSnapshot::gradeClasses($g) }}">Grade {{ $g }}: {{ $kpis['by_grade'][$g] }}</span>
			@endif
		@endforeach
	</div>

	<div class="overflow-x-auto overflow-y-hidden rounded-2xl bg-white shadow-sm ring-1 ring-slate-200">
		<table class="min-w-full text-sm whitespace-nowrap">
			<thead class="bg-slate-50 border-b border-slate-200 text-left text-xs font-semibold uppercase tracking-wider text-slate-700">
				<tr>
					<th class="px-4 py-3">#</th>
					<th class="px-4 py-3">Code</th>
					<th class="px-4 py-3"><a href="{{ $sortLink('name') }}">Customer</a></th>
					<th class="px-4 py-3">Branch</th>
					<th class="px-4 py-3">Phone</th>
					<th class="px-4 py-3">Grade</th>
					<th class="px-4 py-3 text-right"><a href="{{ $sortLink('score') }}">Score</a></th>
					<th class="px-4 py-3">Pattern</th>
					<th class="px-4 py-3">Status</th>
					<th class="px-4 py-3 text-right">Outstanding</th>
					<th class="px-4 py-3 text-right"><a href="{{ $sortLink('overdue_amount') }}">Overdue</a></th>
					<th class="px-4 py-3 text-right"><a href="{{ $sortLink('max_days_late') }}">Max / avg late</a></th>
					<th class="px-4 py-3 text-right"><a href="{{ $sortLink('late_6m') }}">Lates 6m</a></th>
					<th class="px-4 py-3 text-right">Streak</th>
					<th class="px-4 py-3">Guarantor</th>
					<th class="px-4 py-3">Computed</th>
					<th class="px-4 py-3"></th>
				</tr>
			</thead>
			<tbody>
				@forelse ($rows as $r)
					<tr class="border-b border-slate-100 hover:bg-slate-50/70 last:border-0">
						<td class="px-4 py-3 text-slate-500">{{ $rows->firstItem() + $loop->index }}</td>
						<td class="px-4 py-3 font-mono text-xs">{{ $r->customer_code }}</td>
						<td class="px-4 py-3 font-medium"><a class="text-indigo-700 hover:underline" href="{{ route('customers.risk', $r->customer_id) }}">{{ $r->name }}</a></td>
						<td class="px-4 py-3">{{ $r->branch_name ?? '—' }}</td>
						<td class="px-4 py-3">{{ $r->phone ?: '—' }}</td>
						<td class="px-4 py-3"><x-risk-grade-badge :grade="$r->grade" /></td>
						<td class="px-4 py-3 text-right tabular-nums">{{ $r->score === null ? '—' : number_format((float) $r->score, 1) }}</td>
						<td class="px-4 py-3">{{ ucfirst($r->classification) }}{{ $r->improving ? ' ↗' : '' }}</td>
						<td class="px-4 py-3">{{ ucfirst($r->status) }}</td>
						<td class="px-4 py-3 text-right tabular-nums">{{ number_format((float) $r->outstanding_amount, 2) }}</td>
						<td class="px-4 py-3 text-right tabular-nums font-semibold text-rose-700">{{ number_format((float) $r->overdue_amount, 2) }}</td>
						<td class="px-4 py-3 text-right tabular-nums">{{ $r->max_days_late }} / {{ $r->avg_days_late }}</td>
						<td class="px-4 py-3 text-right tabular-nums">{{ $r->late_6m }}</td>
						<td class="px-4 py-3 text-right tabular-nums">{{ $r->current_streak }}</td>
						<td class="px-4 py-3">{{ $r->guarantor_name ?? ($r->has_guarantor ? 'Per invoice' : '—') }}</td>
						<td class="px-4 py-3 text-xs text-slate-500">{{ $r->computed_at ? \Illuminate\Support\Carbon::parse($r->computed_at)->format('Y-m-d H:i') : '—' }}</td>
						<td class="px-4 py-3"><a href="{{ route('customers.risk', $r->customer_id) }}" class="rounded-lg border border-indigo-200 bg-indigo-50 px-2.5 py-1 text-xs font-medium text-indigo-700 hover:bg-indigo-100">Risk Profile</a></td>
					</tr>
				@empty
					<tr><td colspan="17" class="px-4 py-12 text-center text-sm font-medium text-slate-500 bg-slate-50/50">No customers match the current filters.</td></tr>
				@endforelse
			</tbody>
		</table>
	</div>

	<div>{{ $rows->links() }}</div>
</div>
@endsection
