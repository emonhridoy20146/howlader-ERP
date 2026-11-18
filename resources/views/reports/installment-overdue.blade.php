@extends('layouts.app')
@section('title', 'Installment Overdue')
@section('page-title', 'Installment Overdue')
@section('content')

<div class="space-y-4">
	<form method="GET" action="{{ route('reports.installment-overdue') }}" class="rounded-2xl bg-white shadow-sm ring-1 ring-slate-200 px-4 py-3">
		@include('reports.partials.installment-overdue-filter-fields', ['filters' => $filters, 'filterBranches' => $filterBranches])
	</form>

	@if ($rows->total() > 0)
		<p class="text-sm text-slate-600">
			Schedules marked overdue, ordered by due date. Results follow your branch context (same scoping as other reports).
			{{ number_format($rows->total()) }} matching.
		</p>
	@else
		<p class="text-sm text-slate-600">Schedules marked overdue, ordered by due date. Results follow your branch context (same scoping as other reports).</p>
	@endif

	<div class="flex flex-wrap items-center gap-2">
		<span class="text-xs font-medium text-slate-500">Export</span>
		<a target="_blank" rel="noopener noreferrer" href="{{ route('reports.print', ['slug' => 'installment-overdue'] + request()->query()) }}" class="rounded-lg border border-slate-300 bg-white px-3 py-1.5 text-xs font-medium text-slate-800 hover:bg-slate-50">Print</a>
		<a href="{{ route('reports.export', ['slug' => 'installment-overdue', 'format' => 'xlsx'] + request()->query()) }}" class="rounded-lg border border-slate-300 bg-white px-3 py-1.5 text-xs font-medium text-slate-800 hover:bg-slate-50">Excel</a>
	</div>

	<div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
		<x-status-card label="Overdue installments">
			<span class="text-amber-800">{{ number_format($kpis['schedules']) }}</span>
		</x-status-card>
		<x-status-card label="Total outstanding">
			<span class="text-rose-700">{{ number_format($kpis['remaining'], 2) }}</span>
		</x-status-card>
		<x-status-card label="Due + late fee">
			{{ number_format($kpis['due_and_late_fee'], 2) }}
		</x-status-card>
		<x-status-card label="Total paid">
			<span class="text-emerald-700">{{ number_format($kpis['paid'], 2) }}</span>
		</x-status-card>
	</div>

	<div class="overflow-x-auto overflow-y-hidden rounded-2xl bg-white shadow-sm ring-1 ring-slate-200">
		<table class="min-w-full text-sm whitespace-nowrap">
				<thead class="bg-slate-50 border-b border-slate-200 text-left text-xs font-semibold uppercase tracking-wider text-slate-700">
					<tr>
						<th class="px-4 py-3 text-right">SL</th>
						<th class="px-4 py-3">Invoice</th>
						<th class="px-4 py-3">Branch</th>
						<th class="px-4 py-3">Customer</th>
						<th class="px-4 py-3">Plan</th>
						<th class="px-4 py-3 text-right">Ins. No.</th>
						<th class="px-4 py-3">Due date</th>
						<th class="px-4 py-3 text-right">Days late</th>
						<th class="px-4 py-3 text-right">Total</th>
						<th class="px-4 py-3 text-right">Paid</th>
						<th class="px-4 py-3 text-right">Late fee</th>
						<th class="px-4 py-3 text-right">Remaining</th>
						<th class="px-4 py-3 text-right">Actions</th>
					</tr>
				</thead>
				<tbody>
					@forelse($rows as $i => $row)
						@php
							$plan = $row->installmentPlan;
							$invoice = $plan?->salesInvoice;
							$customer = $invoice?->customer;
							$branch = $invoice?->branch;
							$daysLate = $row->due_date && $row->due_date->isPast()
								? (int) $row->due_date->diffInDays(\Illuminate\Support\Carbon::today())
								: 0;
							$remaining = $row->remainingDue();
							$years  = (int) floor($daysLate / 365);
							$months = (int) floor(($daysLate % 365) / 30);
							$days   = $daysLate % 30;
							$lateParts = [];
							if ($years > 0)  $lateParts[] = $years.($years === 1 ? ' year' : ' years');
							if ($months > 0) $lateParts[] = $months.($months === 1 ? ' month' : ' months');
							if ($days > 0 || $daysLate === 0) $lateParts[] = $days.($days === 1 ? ' day' : ' days');
							$lateLine1 = implode(' ', array_slice($lateParts, 0, 2));
							$lateLine2 = count($lateParts) > 2 ? $lateParts[2] : null;
						@endphp
						<tr class="border-b border-slate-100 transition-colors hover:bg-slate-50/70 last:border-0">
							<td class="px-4 py-3 text-right tabular-nums text-slate-500">{{ $rows->firstItem() + $i }}</td>
							<td class="px-4 py-3">
								@if ($invoice)
									<p class="font-mono text-xs font-medium text-slate-800">{{ $invoice->invoice_number }}</p>
									<p class="text-xs text-slate-500">{{ $invoice->invoice_date?->toDateString() }}</p>
								@else
									<span class="text-slate-400">—</span>
								@endif
							</td>
							<td class="px-4 py-3">
								@if ($branch)
									<p class="font-medium text-slate-800">{{ $branch->name }}</p>
								@else
									<span class="text-slate-400">—</span>
								@endif
							</td>
							<td class="px-4 py-3">
								@if ($customer)
									<p class="font-medium text-slate-800">{{ $customer->name }}</p>
									<p class="text-xs text-slate-500">{{ $customer->phone ?: '—' }}</p>
								@else
									<span class="text-slate-400">—</span>
								@endif
							</td>
							<td class="px-4 py-3 whitespace-nowrap">
								@if ($plan)
									@can('view', $plan)
										<a href="{{ route('installments.show', $plan) }}" class="font-medium text-slate-800 hover:text-slate-950 hover:underline">#{{ $plan->id }}</a>
									@else
										<span class="font-medium text-slate-800">#{{ $plan->id }}</span>
									@endcan
								@else
									<span class="text-slate-400">—</span>
								@endif
							</td>
							<td class="px-4 py-3 text-right tabular-nums">{{ $row->installment_no }}</td>
							<td class="px-4 py-3 whitespace-nowrap">{{ $row->due_date?->toDateString() }}</td>
							<td class="px-4 py-3 text-right tabular-nums font-medium text-amber-800 whitespace-normal">
								<span class="block leading-tight">{{ $lateLine1 }}</span>
								@if ($lateLine2)
									<span class="block text-xs text-amber-600 leading-tight">{{ $lateLine2 }}</span>
								@endif
							</td>
							<td class="px-4 py-3 text-right tabular-nums">{{ number_format((float) $invoice?->grand_total, 2) }}</td>
							<td class="px-4 py-3 text-right tabular-nums text-emerald-700">{{ number_format((float) $invoice?->amount_paid, 2) }}</td>
							<td class="px-4 py-3 text-right tabular-nums">{{ number_format((float) $row->late_fee_amount, 2) }}</td>
							<td class="px-4 py-3 text-right tabular-nums font-semibold text-rose-700">{{ number_format((float) $invoice?->amount_due, 2) }}</td>
							<td class="px-4 py-3 text-right whitespace-nowrap">
								@if ($invoice)
									@can('view', $invoice)
										<a href="{{ route('sales.invoices.show', $invoice) }}" class="rounded border border-slate-300 px-2 py-1 text-xs">Invoice</a>
									@endcan
								@endif
								@if ($plan)
									@can('view', $plan)
										<a href="{{ route('installments.show', $plan) }}" class="ml-1 rounded border border-slate-300 px-2 py-1 text-xs">Plan</a>
									@endcan
								@endif
							</td>
						</tr>
					@empty
						<tr>
							<td colspan="14" class="px-4 py-12 text-center text-sm font-medium text-slate-500 bg-slate-50/50">No overdue schedules.</td>
						</tr>
					@endforelse
				</tbody>
			</table>
		</div>
</div>
<x-pagination-limit-selector />
<div class="mt-4">{{ $rows->links() }}</div>
@endsection
