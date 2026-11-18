@php
	$branchSummary = is_array($branchSummary) ? $branchSummary : [];
	$branchRows = $branchRows ?? collect();
	$pageLabel = $pageLabel ?? null;
@endphp

<div class="overflow-hidden rounded-2xl bg-white shadow-sm ring-1 ring-slate-200">
	<div class="border-b border-slate-200 bg-gradient-to-r from-slate-50 to-white px-4 py-4">
		<div class="flex flex-wrap items-start justify-between gap-3">
			<div>
				<p class="text-base font-semibold text-slate-900">{{ $branchSummary['branch_name'] ?? 'Unassigned' }}</p>
				<p class="mt-0.5 text-xs text-slate-500">
					@if(! empty($branchSummary['branch_code']))
						{{ $branchSummary['branch_code'] }} ·
					@endif
					{{ number_format((int) ($branchSummary['payment_lines'] ?? $branchRows->count())) }} payments
					· {{ number_format((int) ($branchSummary['invoices'] ?? $branchRows->pluck('id')->unique()->count())) }} invoices
					@if($pageLabel)
						· {{ $pageLabel }}
					@endif
				</p>
			</div>
			<div class="grid grid-cols-2 gap-x-4 gap-y-1 text-right text-xs sm:grid-cols-3 lg:grid-cols-6">
				<div>
					<p class="text-slate-500">Total</p>
					<p class="font-semibold tabular-nums text-slate-900">{{ number_format((float) ($branchSummary['grand_total'] ?? 0), 2) }}</p>
				</div>
				<div>
					<p class="text-slate-500">Paid (cash)</p>
					<p class="font-semibold tabular-nums text-slate-900">{{ number_format((float) ($branchSummary['amount_paid'] ?? 0), 2) }}</p>
				</div>
				<div>
					<p class="text-indigo-800">Settlement discount</p>
					<p class="font-semibold tabular-nums text-indigo-900">{{ (float) ($branchSummary['settlement_discount'] ?? 0) > 0 ? number_format((float) $branchSummary['settlement_discount'], 2) : '—' }}</p>
				</div>
				<div>
					<p class="text-emerald-700">Cash in period</p>
					<p class="font-semibold tabular-nums text-emerald-800">{{ number_format((float) ($branchSummary['period_cash'] ?? $branchSummary['period_paid'] ?? 0), 2) }}</p>
				</div>
				<div>
					<p class="text-indigo-800">Discount in period</p>
					<p class="font-semibold tabular-nums text-indigo-900">{{ (float) ($branchSummary['period_discount'] ?? 0) > 0 ? number_format((float) $branchSummary['period_discount'], 2) : '—' }}</p>
				</div>
				<div>
					<p class="text-amber-800">Due</p>
					<p class="font-semibold tabular-nums text-amber-900">{{ number_format((float) ($branchSummary['amount_due'] ?? 0), 2) }}</p>
				</div>
			</div>
		</div>
	</div>

	<div class="overflow-x-auto">
		<table class="min-w-full text-sm whitespace-nowrap">
			<thead class="border-b border-slate-200 bg-white text-left text-xs font-semibold uppercase tracking-wider text-slate-600">
				<tr>
					<th class="px-4 py-3">Invoice</th>
					<th class="px-4 py-3">Payment date</th>
					<th class="px-4 py-3">Customer</th>
					<th class="px-4 py-3">Type</th>
					<th class="px-4 py-3">Invoice status</th>
					<th class="px-4 py-3 text-right">Total</th>
					<th class="px-4 py-3 text-right">Paid (cash)</th>
					<th class="px-4 py-3 text-right">Settlement discount</th>
					<th class="px-4 py-3 text-right">Cash in period</th>
					<th class="px-4 py-3 text-right">Discount in period</th>
					<th class="px-4 py-3 text-right">Due after payment</th>
					<th class="px-4 py-3">Payment status</th>
					<th class="px-4 py-3 text-right">Actions</th>
				</tr>
			</thead>
			<tbody>
				@foreach ($branchRows as $invoice)
					@php
						$rowCashPaid = (float) ($invoice->cash_at_transaction ?? $invoice->cash_paid_total ?? max(0, (float) $invoice->amount_paid - (float) ($invoice->settlement_discount_total ?? 0)));
						$rowDiscount = (float) ($invoice->discount_at_transaction ?? $invoice->settlement_discount_total ?? 0);
						$rowPeriodCash = (float) ($invoice->period_cash ?? $invoice->period_paid ?? 0);
						$rowPeriodDiscount = (float) ($invoice->period_discount ?? 0);
					@endphp
					<tr class="border-b border-slate-100 transition-colors hover:bg-slate-50/70 last:border-0 {{ ! empty($invoice->is_previous_invoice_collection) ? 'bg-sky-50/40' : '' }}">
						<td class="px-4 py-3 font-mono text-xs font-medium text-slate-800">{{ $invoice->invoice_number }}</td>
						<td class="px-4 py-3 whitespace-nowrap">{{ $invoice->period_payment_dates ?: '—' }}</td>
						<td class="px-4 py-3">
							@if($invoice->customer)
								<p class="font-medium text-slate-800">{{ $invoice->customer->name }}</p>
								<p class="text-xs text-slate-500">{{ $invoice->customer->phone }}</p>
							@else
								<span class="text-slate-400">—</span>
							@endif
						</td>
						<td class="px-4 py-3">{{ ucfirst($invoice->sale_type) }}</td>
						<td class="px-4 py-3">{{ ucfirst(str_replace('_', ' ', $invoice->status)) }}</td>
						<td class="px-4 py-3 text-right tabular-nums">{{ number_format($invoice->grand_total, 2) }}</td>
						<td class="px-4 py-3 text-right tabular-nums">{{ number_format($rowCashPaid, 2) }}</td>
						<td class="px-4 py-3 text-right tabular-nums text-indigo-800">{{ $rowDiscount > 0.009 ? number_format($rowDiscount, 2) : '—' }}</td>
						<td class="px-4 py-3 text-right tabular-nums text-emerald-700">{{ number_format($rowPeriodCash, 2) }}</td>
						<td class="px-4 py-3 text-right tabular-nums text-indigo-800">{{ $rowPeriodDiscount > 0.009 ? number_format($rowPeriodDiscount, 2) : '—' }}</td>
						<td class="px-4 py-3 text-right tabular-nums">{{ number_format((float) ($invoice->due_at_transaction ?? $invoice->amount_due), 2) }}</td>
						<td class="px-4 py-3">{{ ucfirst(str_replace('_', ' ', $invoice->payment_status)) }}</td>
						<td class="px-4 py-3 text-right">
							<a href="{{ route('sales.invoices.show', $invoice) }}" class="rounded border border-slate-300 px-2 py-1 text-xs">View</a>
						</td>
					</tr>
				@endforeach
			</tbody>
		</table>
	</div>
</div>
