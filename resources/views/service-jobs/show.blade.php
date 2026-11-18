@extends('layouts.app')
@section('title', $job->job_number)
@section('page-title', $job->job_number)
@section('content')
@php
	$statusClass = match ($job->status) {
		'intake', 'diagnosing', 'awaiting_parts', 'repairing' => 'bg-sky-50 text-sky-900 ring-sky-100',
		'done' => 'bg-emerald-50 text-emerald-800 ring-emerald-100',
		'returned_to_customer' => 'bg-indigo-50 text-indigo-800 ring-indigo-100',
		'unrepairable' => 'bg-rose-50 text-rose-800 ring-rose-100',
		default => 'bg-slate-100 text-slate-700 ring-slate-200',
	};
@endphp

<div class="mb-4 flex flex-wrap items-center justify-between gap-3">
	<a href="{{ route('service-jobs.index') }}" class="text-sm font-medium text-slate-600 hover:text-slate-900">← Service jobs</a>
	<div class="flex flex-wrap gap-2">
		@can('update', $job)
			<a href="{{ route('service-jobs.edit', $job) }}" class="rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm font-medium text-slate-800 hover:bg-slate-50">Edit</a>
		@endcan
	</div>
</div>

@if (session('status'))
	<div class="mb-4 rounded-lg bg-emerald-50 p-3 text-sm text-emerald-700">{{ session('status') }}</div>
@endif

<div class="grid gap-4 lg:grid-cols-3">
	<div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm ring-1 ring-slate-200/60 lg:col-span-2">
		<div class="flex flex-wrap items-start justify-between gap-3">
			<div>
				<p class="text-xs uppercase tracking-[0.15em] text-slate-500">Service job</p>
				<h2 class="mt-1 font-mono text-lg font-semibold text-slate-900">{{ $job->job_number }}</h2>
			</div>
			<span class="inline-flex rounded-full px-2.5 py-1 text-xs font-medium capitalize ring-1 {{ $statusClass }}">{{ str_replace('_', ' ', $job->status) }}</span>
		</div>
		<dl class="mt-6 grid gap-4 sm:grid-cols-2">
			<div>
				<dt class="text-xs font-medium text-slate-500">Type</dt>
				<dd class="mt-1 text-sm text-slate-900">{{ ucfirst(str_replace('_', ' ', $job->type)) }}</dd>
			</div>
			<div>
				<dt class="text-xs font-medium text-slate-500">Branch</dt>
				<dd class="mt-1 text-sm text-slate-900">{{ $job->branch?->name }}</dd>
			</div>
			<div>
				<dt class="text-xs font-medium text-slate-500">Customer</dt>
				<dd class="mt-1 text-sm text-slate-900">
					@if ($job->customer)
						{{ $job->customer->name }} <span class="text-slate-500">({{ $job->customer->customer_code }})</span>
					@else
						<span class="text-slate-400">—</span>
					@endif
				</dd>
			</div>
			<div>
				<dt class="text-xs font-medium text-slate-500">Assigned to</dt>
				<dd class="mt-1 text-sm text-slate-900">{{ $job->assignee?->displayName() ?? '—' }}</dd>
			</div>
			<div>
				<dt class="text-xs font-medium text-slate-500">Intake</dt>
				<dd class="mt-1 text-sm text-slate-900">{{ $job->intake_date?->toDateString() ?? '—' }}</dd>
			</div>
			<div>
				<dt class="text-xs font-medium text-slate-500">Promised / completed</dt>
				<dd class="mt-1 text-sm text-slate-900">
					{{ $job->promised_date?->toDateString() ?? '—' }} / {{ $job->completed_date?->toDateString() ?? '—' }}
				</dd>
			</div>
			<div>
				<dt class="text-xs font-medium text-slate-500">Repair cost</dt>
				<dd class="mt-1 text-sm font-medium tabular-nums text-slate-900">{{ number_format((float) $job->repair_cost, 2) }}</dd>
			</div>
		</dl>
	</div>
	<div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm ring-1 ring-slate-200/60">
		<p class="text-xs uppercase tracking-[0.15em] text-slate-500">Serial & product</p>
		<dl class="mt-4 space-y-3 text-sm">
			<div>
				<dt class="text-xs text-slate-500">Serial</dt>
				<dd class="mt-1 font-mono text-slate-900">{{ $job->serialItem?->serial_number ?? '—' }}</dd>
			</div>
			<div>
				<dt class="text-xs text-slate-500">Manufacturer serial</dt>
				<dd class="mt-1 font-mono text-slate-800">{{ $job->serialItem?->manufacturer_serial ?: '—' }}</dd>
			</div>
			<div>
				<dt class="text-xs text-slate-500">Serial status</dt>
				<dd class="mt-1 capitalize text-slate-800">{{ str_replace('_', ' ', (string) ($job->serialItem?->status ?? '')) ?: '—' }}</dd>
			</div>
			<div>
				<dt class="text-xs text-slate-500">Product</dt>
				<dd class="mt-1 text-slate-900">{{ $job->serialItem?->product?->name ?? '—' }}</dd>
			</div>
			<div>
				<dt class="text-xs text-slate-500">SKU</dt>
				<dd class="mt-1 font-mono text-xs text-slate-700">{{ $job->serialItem?->product?->sku ?? '—' }}</dd>
			</div>
		</dl>
	</div>
</div>

<div class="mt-4 space-y-4 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm ring-1 ring-slate-200/60">
	<div>
		<h3 class="text-xs font-semibold uppercase tracking-[0.12em] text-slate-500">Complaint</h3>
		<p class="mt-2 whitespace-pre-wrap text-sm text-slate-800">{{ $job->complaint }}</p>
	</div>
	@if ($job->diagnosis)
		<div>
			<h3 class="text-xs font-semibold uppercase tracking-[0.12em] text-slate-500">Diagnosis</h3>
			<p class="mt-2 whitespace-pre-wrap text-sm text-slate-800">{{ $job->diagnosis }}</p>
		</div>
	@endif
	@if ($job->work_done)
		<div>
			<h3 class="text-xs font-semibold uppercase tracking-[0.12em] text-slate-500">Work done</h3>
			<p class="mt-2 whitespace-pre-wrap text-sm text-slate-800">{{ $job->work_done }}</p>
		</div>
	@endif
</div>
@endsection
