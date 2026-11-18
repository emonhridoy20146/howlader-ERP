@extends('layouts.app')
@section('title', 'Installment plan #'.$plan->id)
@section('page-title', 'Installment plan #'.$plan->id)
@section('content')
@php
	use Illuminate\Support\Str;
	$invoice = $plan->salesInvoice;
	$customer = $invoice?->customer;
	$branch = $invoice?->branch;
	$planStatusClass = match ($plan->status) {
		'active' => 'bg-emerald-50 text-emerald-800 ring-emerald-100',
		'completed' => 'bg-slate-100 text-slate-700 ring-slate-200',
		'defaulted' => 'bg-rose-50 text-rose-800 ring-rose-100',
		'cancelled' => 'bg-slate-50 text-slate-600 ring-slate-200',
		default => 'bg-slate-100 text-slate-700 ring-slate-200',
	};
@endphp

<div class="mb-4 flex flex-wrap items-center justify-between gap-3">
	<a href="{{ route('installments.index') }}" class="text-sm font-medium text-slate-600 hover:text-slate-900">← Back to installments</a>
	@if ($invoice && $invoice->isPayable() && (float) $invoice->amount_due > 0.009)
		@can('view', $invoice)
			<a href="{{ route('sales.invoices.show', $invoice) }}" class="rounded-lg bg-slate-900 px-4 py-2 text-sm font-medium text-white hover:bg-slate-800">Open invoice to pay</a>
		@endcan
	@endif
</div>

<div class="mb-6 grid gap-4 lg:grid-cols-3">
	<div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm ring-1 ring-slate-200/60 lg:col-span-2">
		<div class="flex flex-wrap items-start justify-between gap-3">
			<div>
				<p class="text-xs uppercase tracking-[0.15em] text-slate-500">Plan</p>
				<p class="mt-1 text-lg font-semibold text-slate-900">#{{ $plan->id }}</p>
			</div>
			<span class="inline-flex rounded-full px-2.5 py-1 text-xs font-medium ring-1 {{ $planStatusClass }}">{{ ucfirst($plan->status) }}</span>
		</div>
		<dl class="mt-6 grid gap-4 sm:grid-cols-2">
			<div>
				<dt class="text-xs font-medium text-slate-500">Invoice</dt>
				<dd class="mt-1 text-sm text-slate-900">
					@if ($invoice)
						@can('view', $invoice)
							<a href="{{ route('sales.invoices.show', $invoice) }}" class="font-medium text-blue-700 hover:text-blue-900">{{ $invoice->invoice_number }}</a>
						@else
							{{ $invoice->invoice_number }}
						@endcan
						<span class="mt-0.5 block text-xs text-slate-500">{{ $invoice->invoice_date?->toDateString() }} · {{ ucfirst(str_replace('_', ' ', (string) $invoice->status)) }}</span>
					@else
						<span class="text-slate-400">—</span>
					@endif
				</dd>
			</div>
			<div>
				<dt class="text-xs font-medium text-slate-500">Customer</dt>
				<dd class="mt-1 text-sm text-slate-900">
					@if ($customer)
						<span class="font-medium">{{ $customer->name }}</span>
						<span class="mt-0.5 block font-mono text-xs text-slate-500">{{ $customer->customer_code }}</span>
					@else
						<span class="text-slate-400">—</span>
					@endif
				</dd>
			</div>
			<div>
				<dt class="text-xs font-medium text-slate-500">Branch</dt>
				<dd class="mt-1 text-sm text-slate-900">
					@if ($branch)
						{{ $branch->name }}
					@else
						<span class="text-slate-400">—</span>
					@endif
				</dd>
			</div>
			<div>
				<dt class="text-xs font-medium text-slate-500">Penalties</dt>
				<dd class="mt-1 text-sm text-slate-900">
					{{ $plan->penalty_enabled ? 'Enabled' : 'Disabled' }}
					@if ($plan->penalty_enabled && $plan->penalty_rate !== null)
						<span class="text-slate-500">({{ $plan->penalty_rate }})</span>
					@endif
				</dd>
			</div>
		</dl>
	</div>
	<div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm ring-1 ring-slate-200/60">
		<p class="text-xs uppercase tracking-[0.15em] text-slate-500">Amounts</p>
		<ul class="mt-4 space-y-3 text-sm">
			<li class="flex justify-between gap-2"><span class="text-slate-600">Invoice total</span><span class="font-medium tabular-nums text-slate-900">{{ number_format((float) $plan->total_amount, 2) }}</span></li>
			<li class="flex justify-between gap-2"><span class="text-slate-600">Down payment</span><span class="tabular-nums text-slate-800">{{ number_format((float) $plan->down_payment, 2) }}</span></li>
			<li class="flex justify-between gap-2"><span class="text-slate-600">Financed</span><span class="tabular-nums text-slate-800">{{ number_format((float) $plan->financed_amount, 2) }}</span></li>
			<li class="flex justify-between gap-2 border-t border-slate-100 pt-3"><span class="font-medium text-slate-800">Remaining</span><span class="font-semibold tabular-nums text-slate-900">{{ number_format((float) $plan->remaining_amount, 2) }}</span></li>
		</ul>
		@if ($nextUnpaid)
			<p class="mt-4 rounded-lg bg-sky-50 p-3 text-xs text-sky-900 ring-1 ring-sky-100">
				<span class="font-semibold">Next due:</span> installment #{{ $nextUnpaid->installment_no }} on {{ $nextUnpaid->due_date?->toDateString() }}
				<span class="mt-1 block text-sky-800">Remaining on this installment: {{ number_format($nextUnpaid->remainingDue(), 2) }}</span>
			</p>
		@elseif ($plan->schedules->isNotEmpty())
			<p class="mt-4 text-xs text-slate-500">All scheduled installments are settled.</p>
		@endif
	</div>
</div>

@if ($plan->schedules->isEmpty())
	<div class="rounded-xl border border-dashed border-slate-200 bg-white p-8 text-center text-sm text-slate-500">No schedule rows on this plan yet.</div>
@else
	<div class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm ring-1 ring-slate-200/60">
		<div class="border-b border-slate-200 px-4 py-3">
			<h2 class="text-sm font-semibold text-slate-800">Schedule</h2>
			<p class="mt-1 text-xs text-slate-500">Payments are recorded from the sales invoice. Use “Record payment” above to allocate to the next installment or a specific row when eligible.</p>
		</div>
		<table class="min-w-full text-sm whitespace-nowrap">
				<thead class="bg-slate-50 border-b border-slate-200 text-left text-xs font-semibold uppercase tracking-wider text-slate-700">
					<tr>
						<th class="px-4 py-3">#</th>
						<th class="px-4 py-3">Due Date & Overdue Info</th>
						<th class="px-4 py-3 text-right">Installment Amt</th>
						<th class="px-4 py-3 text-right">Late Fee</th>
						<th class="px-4 py-3 text-right">Paid</th>
						<th class="px-4 py-3 text-right">Balance Due</th>
						<th class="px-4 py-3">Payment Status</th>
						<th class="px-4 py-3 text-right">Action</th>
					</tr>
				</thead>
				<tbody>
					@foreach ($plan->schedules as $schedule)
						@php
							$canTarget = $targetableSchedules->contains(fn ($s) => (int) $s->id === (int) $schedule->id);
							$showPayHint = $invoice && $invoice->isPayable() && (float) $invoice->amount_due > 0.009 && $schedule->remainingDue() >= 0.01;
							$isDueToday = $schedule->remainingDue() >= 0.01 && $schedule->due_date && $schedule->due_date->isToday();
							$isOverdue = $schedule->remainingDue() >= 0.01 && $schedule->due_date && $schedule->due_date->isPast() && ! $isDueToday;
							$daysOverdue = $isOverdue ? (int) $schedule->due_date->diffInDays(now()) : 0;
							$scheduleStatusClass = match ($schedule->status) {
								'paid' => 'bg-emerald-50 text-emerald-700 ring-emerald-100',
								'partially_paid' => 'bg-sky-50 text-sky-700 ring-sky-100',
								'overdue' => 'bg-rose-100 text-rose-700 ring-rose-200',
								'pending' => 'bg-slate-100 text-slate-600 ring-slate-200',
								default => 'bg-slate-100 text-slate-600 ring-slate-200',
							};
						@endphp
						<tr class="border-b border-slate-100 transition-colors last:border-0 {{ $isOverdue ? 'bg-rose-50/70 hover:bg-rose-50' : ($isDueToday ? 'bg-amber-50/70 hover:bg-amber-50' : 'hover:bg-slate-50/70') }}">
							<td class="px-4 py-3 font-medium">{{ $schedule->installment_no }}</td>
							<td class="px-4 py-3">
								<span class="tabular-nums {{ $isOverdue ? 'font-semibold text-rose-700' : ($isDueToday ? 'font-semibold text-amber-700' : 'text-slate-800') }}">{{ $schedule->due_date?->toDateString() }}</span>
								@if ($isDueToday)
									<div class="mt-1">
										<span class="inline-flex w-fit items-center gap-1 rounded-full bg-amber-100 px-2 py-0.5 text-xs font-semibold text-amber-700 ring-1 ring-amber-200">⏰ Due today</span>
									</div>
								@elseif ($isOverdue)
									<div class="mt-1 flex flex-col gap-0.5">
										<span class="inline-flex w-fit items-center gap-1 rounded-full bg-rose-100 px-2 py-0.5 text-xs font-semibold text-rose-700 ring-1 ring-rose-200">
											⚠ {{ $daysOverdue }} {{ Str::plural('day', $daysOverdue) }} overdue
										</span>
										<span class="text-xs text-rose-500">Was due {{ $schedule->due_date->diffForHumans() }}</span>
									</div>
								@elseif ($schedule->remainingDue() >= 0.01 && $schedule->due_date)
									<div class="mt-0.5 text-xs text-slate-400">Due {{ $schedule->due_date->diffForHumans() }}</div>
								@endif
							</td>
							<td class="px-4 py-3 text-right tabular-nums">{{ number_format((float) $schedule->due_amount, 2) }}</td>
							<td class="px-4 py-3 text-right tabular-nums">{{ number_format((float) $schedule->late_fee_amount, 2) }}</td>
							<td class="px-4 py-3 text-right tabular-nums">{{ number_format((float) $schedule->paid_amount, 2) }}</td>
							<td class="px-4 py-3 text-right font-medium tabular-nums text-slate-900">{{ number_format($schedule->remainingDue(), 2) }}</td>
							<td class="px-4 py-3">
								<span class="inline-flex rounded-full px-2 py-0.5 text-xs font-medium ring-1 {{ $scheduleStatusClass }}">{{ ucfirst(str_replace('_', ' ', (string) $schedule->status)) }}</span>
							</td>
							<td class="px-4 py-3 text-right">
								@if ($showPayHint && $canTarget)
									@can('view', $invoice)
										<a href="{{ route('sales.invoices.show', $invoice) }}" class="text-xs font-medium text-blue-700 hover:text-blue-900">Open invoice</a>
									@else
										<span class="text-xs text-slate-400">—</span>
									@endcan
								@elseif ($schedule->remainingDue() < 0.01)
									<span class="text-xs text-slate-400">—</span>
								@elseif ($showPayHint)
									<span class="text-xs text-slate-400">Earlier installments first</span>
								@else
									<span class="text-xs text-slate-400">—</span>
								@endif
							</td>
						</tr>
					@endforeach
				</tbody>
			</table>
		</div>
	</div>
@endif
@endsection
