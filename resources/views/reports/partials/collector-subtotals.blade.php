@props(['totals', 'print' => false])
@if (! empty($totals))
	@php $grand = collect($totals)->sum('amount'); $grandCount = collect($totals)->sum('payment_count'); @endphp
	@if ($print)
		<h3 style="font-size:11px;margin:14px 0 6px;">By collector</h3>
		<table class="data" style="max-width:420px;">
			<thead><tr><th>Collected by</th><th class="num">Payments</th><th class="num">Amount</th></tr></thead>
			<tbody>
				@foreach ($totals as $t)
					<tr><td>{{ $t['collector'] }}</td><td class="num">{{ $t['payment_count'] }}</td><td class="num">{{ number_format($t['amount'], 2) }}</td></tr>
				@endforeach
				<tr><td><strong>Total</strong></td><td class="num"><strong>{{ $grandCount }}</strong></td><td class="num"><strong>{{ number_format($grand, 2) }}</strong></td></tr>
			</tbody>
		</table>
	@else
		<div class="overflow-x-auto rounded-2xl bg-white shadow-sm ring-1 ring-slate-200">
			<h3 class="px-4 pt-3 text-sm font-semibold text-slate-800">By collector</h3>
			<table class="min-w-full text-sm" data-collector-subtotals>
				<thead class="border-b border-slate-200 bg-slate-50 text-left text-xs font-semibold uppercase tracking-wider text-slate-700">
					<tr><th class="px-4 py-2">Collected by</th><th class="px-4 py-2 text-right">Payments</th><th class="px-4 py-2 text-right">Amount</th></tr>
				</thead>
				<tbody>
					@foreach ($totals as $t)
						<tr class="border-b border-slate-100"><td class="px-4 py-2">{{ $t['collector'] }}</td><td class="px-4 py-2 text-right tabular-nums">{{ $t['payment_count'] }}</td><td class="px-4 py-2 text-right tabular-nums">{{ number_format($t['amount'], 2) }}</td></tr>
					@endforeach
				</tbody>
				<tfoot class="bg-slate-50 font-semibold"><tr><td class="px-4 py-2">Total</td><td class="px-4 py-2 text-right tabular-nums">{{ $grandCount }}</td><td class="px-4 py-2 text-right tabular-nums">{{ number_format($grand, 2) }}</td></tr></tfoot>
			</table>
		</div>
	@endif
@endif
