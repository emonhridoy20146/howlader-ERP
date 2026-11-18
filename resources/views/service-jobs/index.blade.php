@extends('layouts.app')
@section('title', 'Service jobs')
@section('page-title', 'Service jobs')
@section('content')
<div class="mb-4 flex flex-col gap-2 sm:flex-row sm:flex-wrap sm:items-center sm:justify-end">
	@can('create', \App\Models\ServiceJob::class)
		<a href="{{ route('service-jobs.create') }}" class="inline-flex justify-center rounded-lg bg-slate-900 px-4 py-2.5 text-sm font-medium text-white shadow-sm hover:bg-slate-800 sm:py-2">New service job</a>
	@endcan
</div>

@if (session('status'))
	<div class="mb-4 rounded-lg bg-emerald-50 p-3 text-sm text-emerald-700">{{ session('status') }}</div>
@endif

<x-filter-drawer>
<form method="get" action="{{ route('service-jobs.index') }}" class="flex flex-col gap-4">
	<div class="flex flex-col gap-4">
		<input type="text" name="q" value="{{ $filters['q'] ?? '' }}" placeholder="Job #, complaint, serial, product…" class="rounded-lg border border-slate-300 px-3 py-2 text-sm ">
		<select name="type" class="rounded-lg border border-slate-300 px-3 py-2 text-sm">
			<option value="">All types</option>
			@foreach (['warranty_repair', 'paid_repair', 'inspection'] as $type)
				<option value="{{ $type }}" @selected(($filters['type'] ?? '') === $type)>{{ ucfirst(str_replace('_', ' ', $type)) }}</option>
			@endforeach
		</select>
		<select name="status" class="rounded-lg border border-slate-300 px-3 py-2 text-sm">
			<option value="">All status</option>
			@foreach (['intake', 'diagnosing', 'awaiting_parts', 'repairing', 'done', 'returned_to_customer', 'unrepairable'] as $status)
				<option value="{{ $status }}" @selected(($filters['status'] ?? '') === $status)>{{ ucfirst(str_replace('_', ' ', $status)) }}</option>
			@endforeach
		</select>
	</div>
	<x-index-filter-actions :reset-url="route('service-jobs.index')" />
</form>
</x-filter-drawer>

<div class="mb-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
	<x-status-card label="Total jobs">
			<span class="tabular-nums">{{ $kpis['total'] }}</span>
		</x-status-card>
	<x-status-card label="In progress">
			<span class="tabular-nums">{{ $kpis['in_progress'] }}</span>
		</x-status-card>
	<x-status-card label="Done">
			<span class="tabular-nums">{{ $kpis['done'] }}</span>
		</x-status-card>
	<x-status-card label="Returned">
			<span class="tabular-nums">{{ $kpis['returned'] }}</span>
		</x-status-card>
	<x-status-card label="Matching filter">
			<span class="tabular-nums">{{ $kpis['filtered'] }}</span>
		</x-status-card>
</div>

<x-index-table-shell title="Service jobs" :meta="$jobs->total().' in this list'">
	<table class="index-data-table min-w-[52rem] sm:min-w-full whitespace-nowrap">
		<thead class="bg-slate-50 border-b border-slate-200 text-left text-xs font-semibold uppercase tracking-wider text-slate-700">
			<tr>
				<th class="px-4 py-3">Job</th>
				<th class="px-4 py-3">Serial / product</th>
				<th class="px-4 py-3">Branch</th>
				<th class="px-4 py-3">Type</th>
				<th class="px-4 py-3">Status</th>
				<th class="px-4 py-3">Intake</th>
				<th class="px-4 py-3 text-right">Actions</th>
			</tr>
		</thead>
		<tbody>
			@forelse ($jobs as $job)
				@php
					$statusClass = match ($job->status) {
						'intake', 'diagnosing', 'awaiting_parts', 'repairing' => 'bg-sky-50 text-sky-900 ring-sky-100',
						'done' => 'bg-emerald-50 text-emerald-800 ring-emerald-100',
						'returned_to_customer' => 'bg-indigo-50 text-indigo-800 ring-indigo-100',
						'unrepairable' => 'bg-rose-50 text-rose-800 ring-rose-100',
						default => 'bg-slate-100 text-slate-700 ring-slate-200',
					};
				@endphp
				<tr class="border-b border-slate-100 transition-colors hover:bg-slate-50/70 last:border-0">
					<td class="px-4 py-3 font-mono text-xs font-medium text-slate-900">{{ $job->job_number }}</td>
					<td class="px-4 py-3">
						<div class="font-mono text-xs text-slate-800">{{ $job->serialItem?->serial_number ?? '—' }}</div>
						<div class="mt-0.5 max-w-[14rem] truncate text-xs text-slate-500" title="{{ $job->serialItem?->product?->name }}">{{ $job->serialItem?->product?->name ?? '' }}</div>
					</td>
					<td class="px-4 py-3 text-slate-700">
						<span class="text-sm text-slate-800">{{ $job->branch?->name }}</span>
					</td>
					<td class="px-4 py-3">{{ ucfirst(str_replace('_', ' ', $job->type)) }}</td>
					<td class="px-4 py-3">
						<span class="inline-flex rounded-full px-2 py-0.5 text-xs font-medium capitalize ring-1 {{ $statusClass }}">{{ str_replace('_', ' ', $job->status) }}</span>
					</td>
					<td class="px-4 py-3 tabular-nums text-slate-700">{{ $job->intake_date?->toDateString() }}</td>
					<td class="px-4 py-3 text-right">
						@canany(['view', 'update'], $job)
						<x-index-row-actions-menu>
							@can('view', $job)
								<a href="{{ route('service-jobs.show', $job) }}">View</a>
							@endcan
							@can('update', $job)
								<a href="{{ route('service-jobs.edit', $job) }}">Edit</a>
							@endcan
						</x-index-row-actions-menu>
						@endcanany
					</td>
				</tr>
			@empty
				<tr>
					<td colspan="7" class="px-4 py-12 text-center text-sm font-medium text-slate-500 bg-slate-50/50">No service jobs found.</td>
				</tr>
			@endforelse
		</tbody>
	</table>
</x-index-table-shell>
	<x-pagination-limit-selector />
<div class="mt-4">{{ $jobs->links() }}</div>
@endsection
