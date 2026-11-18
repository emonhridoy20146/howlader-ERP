@php
	$summaryBySource = $summaryBySource ?? collect();
	$sourceSummaryTotals = [
		'line_count' => (int) $summaryBySource->sum('line_count'),
		'debit' => (float) $summaryBySource->sum('debit'),
		'credit' => (float) $summaryBySource->sum('credit'),
		'net' => (float) $summaryBySource->sum('net'),
	];
@endphp

<div class="overflow-x-auto overflow-y-hidden rounded-2xl bg-white shadow-sm ring-1 ring-slate-200">
	<div class="border-b border-slate-200 bg-slate-50 px-4 py-3">
		<h2 class="text-sm font-semibold text-slate-900">Summary by source</h2>
		<p class="mt-0.5 text-xs text-slate-500">All transactions in this report, grouped by journal source.</p>
	</div>
	<table class="min-w-full text-sm whitespace-nowrap">
		<thead class="border-b border-slate-200 bg-slate-50/80 text-left text-xs font-semibold uppercase tracking-wider text-slate-700">
			<tr>
				<th class="px-4 py-3">Source</th>
				<th class="px-4 py-3 text-right">Lines</th>
				<th class="px-4 py-3 text-right">Debit</th>
				<th class="px-4 py-3 text-right">Credit</th>
				<th class="px-4 py-3 text-right">Net</th>
			</tr>
		</thead>
		<tbody>
			@forelse ($summaryBySource as $row)
				<tr class="border-b border-slate-100 last:border-0">
					<td class="px-4 py-3 font-medium text-slate-800">{{ $row['label'] }}</td>
					<td class="px-4 py-3 text-right tabular-nums">{{ number_format((int) $row['line_count']) }}</td>
					<td class="px-4 py-3 text-right tabular-nums">{{ number_format((float) $row['debit'], 2) }}</td>
					<td class="px-4 py-3 text-right tabular-nums">{{ number_format((float) $row['credit'], 2) }}</td>
					<td class="px-4 py-3 text-right tabular-nums {{ (float) $row['net'] >= 0 ? 'text-emerald-700' : 'text-rose-700' }}">{{ number_format((float) $row['net'], 2) }}</td>
				</tr>
			@empty
				<tr>
					<td colspan="5" class="px-4 py-8 text-center text-sm text-slate-500">No data for the current filters.</td>
				</tr>
			@endforelse
		</tbody>
		@if ($summaryBySource->isNotEmpty())
			<tfoot class="border-t border-slate-200 bg-slate-50 font-semibold text-slate-800">
				<tr>
					<td class="px-4 py-3">Total</td>
					<td class="px-4 py-3 text-right tabular-nums">{{ number_format($sourceSummaryTotals['line_count']) }}</td>
					<td class="px-4 py-3 text-right tabular-nums">{{ number_format($sourceSummaryTotals['debit'], 2) }}</td>
					<td class="px-4 py-3 text-right tabular-nums">{{ number_format($sourceSummaryTotals['credit'], 2) }}</td>
					<td class="px-4 py-3 text-right tabular-nums {{ $sourceSummaryTotals['net'] >= 0 ? 'text-emerald-700' : 'text-rose-700' }}">{{ number_format($sourceSummaryTotals['net'], 2) }}</td>
				</tr>
			</tfoot>
		@endif
	</table>
</div>
