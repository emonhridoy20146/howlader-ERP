@extends('layouts.app')
@section('title', 'Credit Invoices')
@section('page-title', 'Credit Invoices')
@section('content')

@if (session('status'))
	<div class="mb-4 rounded-lg bg-emerald-50 p-3 text-sm text-emerald-700">{{ session('status') }}</div>
@endif

<x-filter-drawer>
<form method="get" action="{{ route('credit.index') }}" class="flex flex-col gap-4">
	<div class="flex flex-col gap-4">
		<input type="text" name="q" value="{{ $filters['q'] ?? '' }}" placeholder="Invoice #, customer name, code or phone" class="rounded-lg border border-slate-300 px-3 py-2 text-sm">
		<select name="payment_status" class="rounded-lg border border-slate-300 px-3 py-2 text-sm">
			<option value="">All payment status</option>
			@foreach (['unpaid', 'partial', 'paid'] as $ps)
				<option value="{{ $ps }}" @selected(($filters['payment_status'] ?? '') === $ps)>{{ ucfirst($ps) }}</option>
			@endforeach
		</select>
	</div>
	<label class="flex cursor-pointer items-center gap-2 text-sm font-medium text-slate-700">
		<input type="checkbox" name="overdue" value="1" @checked(($filters['overdue'] ?? '') === '1') style="width:1rem;height:1rem;padding:0;" class="rounded border-slate-300 text-slate-900 focus:ring-slate-500 shrink-0">
		Show overdue only
	</label>
	<x-index-filter-actions :reset-url="route('credit.index')" />
</form>
</x-filter-drawer>

<div class="mb-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
	<x-status-card label="Total credit invoices">
		<span class="tabular-nums">{{ $kpis['total'] }}</span>
	</x-status-card>
	<x-status-card label="Unpaid">
		<span class="tabular-nums">{{ $kpis['unpaid'] }}</span>
	</x-status-card>
	<x-status-card label="Overdue">
		<span class="tabular-nums">{{ $kpis['overdue'] }}</span>
	</x-status-card>
	<x-status-card label="Total due">
		<span class="tabular-nums">{{ number_format($kpis['total_due'], 2) }}</span>
	</x-status-card>
	<x-status-card label="Matching filter">
		<span class="tabular-nums">{{ $kpis['filtered'] }}</span>
	</x-status-card>
</div>

<x-index-table-shell title="Credit invoices" :meta="$invoices->total().' in this list'">
	<table class="index-data-table min-w-[52rem] sm:min-w-full whitespace-nowrap">
		<thead class="bg-slate-50 border-b border-slate-200 text-left text-xs font-semibold uppercase tracking-wider text-slate-700">
			<tr>
				<th class="px-4 py-3">Invoice</th>
				<th class="px-4 py-3">Invoice Date</th>
				<th class="px-4 py-3">Customer</th>
				<th class="px-4 py-3">Branch</th>
				<th class="px-4 py-3 text-right">Total</th>
				<th class="px-4 py-3 text-right">Balance Due</th>
				<th class="px-4 py-3">Credit Due Date</th>
				<th class="px-4 py-3">Payment Status</th>
				<th class="px-4 py-3 text-right">Actions</th>
			</tr>
		</thead>
		<tbody>
			@forelse ($invoices as $invoice)
				@php
					$customer    = $invoice->customer;
					$branch      = $invoice->branch;
					$dueDate     = $invoice->credit_due_date;
					$unpaid      = $invoice->payment_status !== 'paid';
					$today       = \Illuminate\Support\Carbon::today();

					// strictly before today = overdue
					$isOverdue   = $unpaid && $dueDate && $dueDate->startOfDay()->lt($today);
					// exactly today = due today
					$isDueToday  = $unpaid && $dueDate && $dueDate->isSameDay($today);

					$overdueDays = $isOverdue ? (int) $dueDate->diffInDays($today) : 0;

					$paymentBadge = match ($invoice->payment_status) {
						'paid'    => 'bg-emerald-50 text-emerald-800 ring-emerald-100',
						'partial' => 'bg-amber-50 text-amber-800 ring-amber-100',
						'unpaid'  => 'bg-rose-50 text-rose-800 ring-rose-100',
						default   => 'bg-slate-100 text-slate-700 ring-slate-200',
					};

					$rowClass = $isOverdue
						? 'bg-rose-50/60 hover:bg-rose-50'
						: ($isDueToday ? 'bg-amber-50/60 hover:bg-amber-50' : 'hover:bg-slate-50/70');
				@endphp
				<tr class="border-b border-slate-100 transition-colors last:border-0 {{ $rowClass }}">
					<td class="px-4 py-3 font-mono text-sm">
						<a href="{{ route('sales.invoices.show', $invoice) }}" class="font-semibold text-blue-700 hover:text-blue-900">
							{{ $invoice->invoice_number ?? '—' }}
						</a>
					</td>
					<td class="px-4 py-3 text-slate-700 tabular-nums">
						{{ $invoice->invoice_date?->toDateString() ?? '—' }}
					</td>
					<td class="px-4 py-3">
						@if ($customer)
							<div class="font-medium text-slate-900">{{ $customer->name }}</div>
							<div class="text-xs text-slate-500">{{ $customer->customer_code }}</div>
						@else
							<span class="text-slate-400">—</span>
						@endif
					</td>
					<td class="px-4 py-3 text-slate-700">{{ $branch?->name ?? '—' }}</td>
					<td class="px-4 py-3 text-right tabular-nums">{{ number_format((float) $invoice->grand_total, 2) }}</td>
					<td class="px-4 py-3 text-right font-medium tabular-nums {{ $invoice->amount_due > 0 ? 'text-rose-700' : 'text-slate-900' }}">
						{{ number_format((float) $invoice->amount_due, 2) }}
					</td>
					<td class="px-4 py-3">
						@if ($dueDate)
							@if ($isOverdue)
								<div class="flex flex-col gap-0.5">
									<span class="inline-flex w-fit rounded-full bg-rose-100 px-2 py-0.5 text-xs font-semibold text-rose-700 ring-1 ring-rose-200">⚠ Date Overdue</span>
									<span class="text-xs text-rose-600 tabular-nums">{{ $dueDate->toDateString() }} · {{ $overdueDays }}d ago</span>
								</div>
							@elseif ($isDueToday)
								<div class="flex flex-col gap-0.5">
									<span class="inline-flex w-fit rounded-full bg-amber-100 px-2 py-0.5 text-xs font-semibold text-amber-800 ring-1 ring-amber-200">⏰ Due Today</span>
									<span class="text-xs text-amber-700 tabular-nums">{{ $dueDate->toDateString() }}</span>
								</div>
							@else
								<span class="text-sm tabular-nums text-slate-700">{{ $dueDate->toDateString() }}</span>
							@endif
						@else
							<span class="text-xs text-slate-400">—</span>
						@endif
					</td>
					<td class="px-4 py-3">
						<span class="inline-flex rounded-full px-2 py-0.5 text-xs font-medium ring-1 {{ $paymentBadge }}">
							{{ ucfirst($invoice->payment_status) }}
						</span>
					</td>
					<td class="px-4 py-3 text-right">
						<x-index-row-actions-menu>
							<a href="{{ route('sales.invoices.show', $invoice) }}">View</a>
						</x-index-row-actions-menu>
					</td>
				</tr>
			@empty
				<tr>
					<td colspan="9" class="px-4 py-12 text-center text-sm font-medium text-slate-500 bg-slate-50/50">No credit invoices found.</td>
				</tr>
			@endforelse
		</tbody>
	</table>
</x-index-table-shell>
<x-pagination-limit-selector />
<div class="mt-4">{{ $invoices->links() }}</div>
@endsection
