@extends('layouts.app')
@section('title', 'Customer Journal')
@section('page-title', 'Customer Journal')
@section('content')
<div class="space-y-4">
	<p class="text-sm text-slate-600">View the accounts receivable ledger for a specific customer. Each entry shows invoices posted (debit) and payments received / write-offs (credit) with a running balance.</p>

	<x-filter-drawer>
<form method="get" action="{{ route('reports.customer-journal') }}" class="flex flex-col gap-4">
		@include('reports.partials.customer-journal-filter-fields', ['filters' => $filters, 'filterCustomers' => $customers])
		<x-index-filter-actions :reset-url="route('reports.customer-journal')" />
	</form>
</x-filter-drawer>

	@if ($selectedCustomer)
		<div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
			<div class="flex flex-wrap items-start justify-between gap-3">
				<div>
					<p class="text-xs font-medium uppercase tracking-wider text-slate-500">Customer</p>
					<p class="mt-1 text-lg font-semibold text-slate-900">{{ $selectedCustomer->name }}</p>
					<p class="text-sm text-slate-600">{{ $selectedCustomer->customer_code }}{{ $selectedCustomer->phone ? ' · '.$selectedCustomer->phone : '' }}</p>
				</div>
				<div class="text-right text-sm">
					@if ($filters['date_from'] !== '' || $filters['date_to'] !== '')
						<p class="text-xs text-slate-500">Period: {{ $filters['date_from'] ?: '…' }} → {{ $filters['date_to'] ?: '…' }}</p>
					@else
						<p class="text-xs text-slate-500">All time</p>
					@endif
				</div>
			</div>
		</div>

		<div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
			<x-status-card label="Entries">
				{{ number_format($kpis['entries']) }}
			</x-status-card>
			<x-status-card label="Total Debit (invoiced)">
				<span class="text-rose-700">{{ number_format($kpis['total_debit'], 2) }}</span>
			</x-status-card>
			<x-status-card label="Total Credit (received)">
				<span class="text-emerald-700">{{ number_format($kpis['total_credit'], 2) }}</span>
			</x-status-card>
			<x-status-card label="Closing Balance">
				<span class="{{ $kpis['closing_balance'] > 0 ? 'text-rose-700' : 'text-emerald-700' }}">{{ number_format($kpis['closing_balance'], 2) }}</span>
			</x-status-card>
		</div>

		<div class="overflow-x-auto overflow-y-hidden rounded-2xl bg-white shadow-sm ring-1 ring-slate-200">
			<table class="min-w-full text-sm whitespace-nowrap">
				<thead class="bg-slate-50 border-b border-slate-200 text-left text-xs font-semibold uppercase tracking-wider text-slate-700">
					<tr>
						<th class="px-4 py-3">Date</th>
						<th class="px-4 py-3">Event</th>
						<th class="px-4 py-3">Reference</th>
						<th class="px-4 py-3 text-right">Debit</th>
						<th class="px-4 py-3 text-right">Credit</th>
						<th class="px-4 py-3 text-right">Balance</th>
					</tr>
				</thead>
				<tbody>
					@if (isset($kpis['opening_balance']) && $kpis['opening_balance'] != 0)
						<tr class="border-b border-slate-200 bg-slate-50/80">
							<td class="px-4 py-3 text-slate-600">{{ $filters['date_from'] }}</td>
							<td class="px-4 py-3 font-medium text-slate-800" colspan="4">Opening balance (carried forward)</td>
							<td class="px-4 py-3 text-right tabular-nums font-semibold {{ $kpis['opening_balance'] > 0 ? 'text-rose-700' : 'text-emerald-700' }}">{{ number_format($kpis['opening_balance'], 2) }}</td>
						</tr>
					@endif
					@forelse ($rows as $entry)
						<tr class="border-b border-slate-100 transition-colors hover:bg-slate-50/70 last:border-0">
							<td class="px-4 py-3 tabular-nums text-slate-700">{{ \Illuminate\Support\Carbon::parse($entry->event_time)->format('Y-m-d H:i') }}</td>
							<td class="px-4 py-3">
								@php
									$eventLabels = [
										'invoice_posted' => 'Invoice posted',
										'payment_allocated' => 'Payment received',
										'credit_note' => 'Credit note',
										'late_fee' => 'Late fee',
										'writeoff' => 'Write-off',
									];
								@endphp
								<span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium
									@if ($entry->event_type === 'invoice_posted') bg-rose-50 text-rose-700
									@elseif ($entry->event_type === 'payment_allocated') bg-emerald-50 text-emerald-700
									@elseif ($entry->event_type === 'writeoff') bg-amber-50 text-amber-700
									@elseif ($entry->event_type === 'late_fee') bg-orange-50 text-orange-700
									@else bg-slate-100 text-slate-700
									@endif
								">{{ $eventLabels[$entry->event_type] ?? ucfirst(str_replace('_', ' ', $entry->event_type)) }}</span>
							</td>
							<td class="px-4 py-3 text-xs text-slate-600">
								@if ($entry->reference_type === 'SalesInvoice')
									<a href="{{ route('sales.invoices.show', $entry->reference_id) }}" class="text-blue-600 hover:underline">Invoice #{{ $entry->reference_id }}</a>
								@elseif ($entry->reference_type === 'Payment')
									Payment #{{ $entry->reference_id }}
								@elseif ($entry->reference_type === 'InvoiceDebtWriteoff')
									Writeoff #{{ $entry->reference_id }}
								@else
									{{ \Illuminate\Support\Str::afterLast($entry->reference_type, '\\') }} #{{ $entry->reference_id }}
								@endif
							</td>
							<td class="px-4 py-3 text-right tabular-nums {{ $entry->debit_amount > 0 ? 'font-semibold text-rose-700' : 'text-slate-400' }}">{{ $entry->debit_amount > 0 ? number_format($entry->debit_amount, 2) : '—' }}</td>
							<td class="px-4 py-3 text-right tabular-nums {{ $entry->credit_amount > 0 ? 'font-semibold text-emerald-700' : 'text-slate-400' }}">{{ $entry->credit_amount > 0 ? number_format($entry->credit_amount, 2) : '—' }}</td>
							<td class="px-4 py-3 text-right tabular-nums font-semibold {{ $entry->running_balance > 0 ? 'text-rose-700' : 'text-emerald-700' }}">{{ number_format($entry->running_balance, 2) }}</td>
						</tr>
					@empty
						<tr><td colspan="6" class="px-4 py-12 text-center text-sm font-medium text-slate-500 bg-slate-50/50">No journal entries found for the selected period.</td></tr>
					@endforelse
				</tbody>
				@if ($rows->count() > 0)
					<tfoot class="border-t-2 border-slate-200 bg-slate-50/90">
						<tr>
							<td colspan="3" class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-wider text-slate-600">Totals</td>
							<td class="px-4 py-3 text-right text-sm font-bold tabular-nums text-rose-700">{{ number_format($kpis['total_debit'], 2) }}</td>
							<td class="px-4 py-3 text-right text-sm font-bold tabular-nums text-emerald-700">{{ number_format($kpis['total_credit'], 2) }}</td>
							<td class="px-4 py-3 text-right text-sm font-bold tabular-nums {{ $kpis['closing_balance'] > 0 ? 'text-rose-700' : 'text-emerald-700' }}">{{ number_format($kpis['closing_balance'], 2) }}</td>
						</tr>
					</tfoot>
				@endif
			</table>
		</div>
	@elseif ($filters['customer_id'] === '')
		<div class="rounded-2xl border border-slate-200 bg-slate-50 px-6 py-12 text-center shadow-sm">
			<p class="text-sm font-medium text-slate-600">Select a customer and optionally set a date range, then apply the filter to view their journal.</p>
		</div>
	@endif
</div>
@endsection
