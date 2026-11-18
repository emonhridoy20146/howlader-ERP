@php
	$summaryByType = $summaryByType ?? collect();
	$typeSummaryTotals = [
		'invoices' => (int) $summaryByType->sum('invoices'),
		'grand_total' => (float) $summaryByType->sum('grand_total'),
		'amount_paid' => (float) $summaryByType->sum('amount_paid'),
		'settlement_discount' => (float) $summaryByType->sum('settlement_discount'),
		'writeoff' => (float) $summaryByType->sum('writeoff'),
		'period_cash' => (float) $summaryByType->sum('period_cash'),
		'period_discount' => (float) $summaryByType->sum('period_discount'),
		'period_paid' => (float) $summaryByType->sum('period_paid'),
		'amount_due' => (float) $summaryByType->sum('amount_due'),
	];
	$showWriteoffColumn = $typeSummaryTotals['writeoff'] > 0.009;
@endphp

<div class="overflow-x-auto overflow-y-hidden rounded-2xl bg-white shadow-sm ring-1 ring-slate-200">
	<div class="border-b border-slate-200 bg-slate-50 px-4 py-3">
		<h2 class="text-sm font-semibold text-slate-900">Collection summary by type</h2>
		<p class="mt-0.5 text-xs text-slate-500">All rows in this report, grouped by invoice sale type. Due = Total − Paid (cash) − Settlement discount − Write-offs.</p>
	</div>
	<table class="min-w-full text-sm whitespace-nowrap">
		<thead class="border-b border-slate-200 bg-slate-50/80 text-left text-xs font-semibold uppercase tracking-wider text-slate-700">
			<tr>
				<th class="px-4 py-3">Type</th>
				<th class="px-4 py-3 text-right">Invoices</th>
				<th class="px-4 py-3 text-right">Total</th>
				<th class="px-4 py-3 text-right">Paid (cash)</th>
				<th class="px-4 py-3 text-right">Settlement discount</th>
				@if ($showWriteoffColumn)
					<th class="px-4 py-3 text-right">Write-offs</th>
				@endif
				<th class="px-4 py-3 text-right">Cash in period</th>
				<th class="px-4 py-3 text-right">Discount in period</th>
				<th class="px-4 py-3 text-right">Due</th>
			</tr>
		</thead>
		<tbody>
			@forelse ($summaryByType as $row)
				<tr class="border-b border-slate-100 last:border-0">
					<td class="px-4 py-3 font-medium text-slate-800">{{ $row['label'] }}</td>
					<td class="px-4 py-3 text-right tabular-nums">{{ number_format((int) $row['invoices']) }}</td>
					<td class="px-4 py-3 text-right tabular-nums">{{ number_format((float) $row['grand_total'], 2) }}</td>
					<td class="px-4 py-3 text-right tabular-nums">{{ number_format((float) ($row['amount_paid'] ?? 0), 2) }}</td>
					<td class="px-4 py-3 text-right tabular-nums text-indigo-800">{{ (float) ($row['settlement_discount'] ?? 0) > 0 ? number_format((float) $row['settlement_discount'], 2) : '—' }}</td>
					@if ($showWriteoffColumn)
						<td class="px-4 py-3 text-right tabular-nums text-amber-800">{{ (float) ($row['writeoff'] ?? 0) > 0 ? number_format((float) $row['writeoff'], 2) : '—' }}</td>
					@endif
					<td class="px-4 py-3 text-right tabular-nums text-emerald-700">{{ number_format((float) ($row['period_cash'] ?? $row['period_paid'] ?? 0), 2) }}</td>
					<td class="px-4 py-3 text-right tabular-nums text-indigo-800">{{ (float) ($row['period_discount'] ?? 0) > 0 ? number_format((float) $row['period_discount'], 2) : '—' }}</td>
					<td class="px-4 py-3 text-right tabular-nums">{{ number_format((float) $row['amount_due'], 2) }}</td>
				</tr>
			@empty
				<tr>
					<td colspan="{{ $showWriteoffColumn ? 9 : 8 }}" class="px-4 py-8 text-center text-sm text-slate-500">No data for the current filters.</td>
				</tr>
			@endforelse
		</tbody>
		@if ($summaryByType->isNotEmpty())
			<tfoot class="border-t border-slate-200 bg-slate-50 font-semibold text-slate-800">
				<tr>
					<td class="px-4 py-3">Total</td>
					<td class="px-4 py-3 text-right tabular-nums">{{ number_format($typeSummaryTotals['invoices']) }}</td>
					<td class="px-4 py-3 text-right tabular-nums">{{ number_format($typeSummaryTotals['grand_total'], 2) }}</td>
					<td class="px-4 py-3 text-right tabular-nums">{{ number_format($typeSummaryTotals['amount_paid'], 2) }}</td>
					<td class="px-4 py-3 text-right tabular-nums text-indigo-800">{{ $typeSummaryTotals['settlement_discount'] > 0 ? number_format($typeSummaryTotals['settlement_discount'], 2) : '—' }}</td>
					@if ($showWriteoffColumn)
						<td class="px-4 py-3 text-right tabular-nums text-amber-800">{{ $typeSummaryTotals['writeoff'] > 0 ? number_format($typeSummaryTotals['writeoff'], 2) : '—' }}</td>
					@endif
					<td class="px-4 py-3 text-right tabular-nums text-emerald-700">{{ number_format($typeSummaryTotals['period_cash'], 2) }}</td>
					<td class="px-4 py-3 text-right tabular-nums text-indigo-800">{{ $typeSummaryTotals['period_discount'] > 0 ? number_format($typeSummaryTotals['period_discount'], 2) : '—' }}</td>
					<td class="px-4 py-3 text-right tabular-nums">{{ number_format($typeSummaryTotals['amount_due'], 2) }}</td>
				</tr>
			</tfoot>
		@endif
	</table>
</div>
