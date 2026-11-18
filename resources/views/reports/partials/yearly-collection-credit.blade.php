{{-- Credit customers, branch-wise (screen). Expects $credit, $months, $years, $money, $dash. --}}
@php $ccols = 8 + count($months); $csl = 0; $plural = fn ($w, $n) => \Illuminate\Support\Str::plural($w, $n); @endphp
<h2 class="mt-6 text-base font-semibold text-slate-800">Credit customers</h2>
<p class="text-xs text-slate-500">Credit-sale invoices still unpaid. Each month shows the amount received (top) and the total still unpaid at month end (bottom). <span class="rounded px-1 font-semibold text-rose-700 ring-1 ring-rose-600">◆ DUE</span> marks the credit due-date month when it passed without being cleared.</p>
<div class="ycs-wrap rounded-2xl bg-white shadow-sm ring-1 ring-slate-200">
	<table class="ycs min-w-full">
		<thead>
			<tr>
				<th rowspan="2" style="min-width:44px">SL</th>
				<th rowspan="2" style="min-width:160px">Customer</th>
				<th rowspan="2" style="min-width:110px">Invoice</th>
				<th rowspan="2">Invoice date</th>
				<th rowspan="2">Credit due date</th>
				<th rowspan="2">Total payable</th>
				<th rowspan="2">Total paid</th>
				<th rowspan="2">Total due</th>
				@foreach ($years as $year => $count)<th colspan="{{ $count }}" class="yr">{{ $year }}</th>@endforeach
			</tr>
			<tr>
				@foreach ($months as $m)<th class="m {{ $m['month'] === 1 ? 'yr' : '' }}" title="{{ $m['label'] }} {{ $m['year'] }}">{{ $m['month'] }}</th>@endforeach
			</tr>
		</thead>
		@forelse ($credit['groups'] as $cg)
			<tbody>
				<tr class="branch"><td colspan="{{ $ccols }}">Branch: {{ $cg['name'] }} — Credit customers — {{ count($cg['customers']) }} {{ $plural('customer', count($cg['customers'])) }} · {{ $cg['invoices'] }} {{ $plural('invoice', $cg['invoices']) }}</td></tr>
			</tbody>
			@foreach ($cg['customers'] as $customer)
				@php $csl++; @endphp
				<tbody>
					<tr class="cust">
						<td colspan="{{ $ccols }}">
							<a class="text-indigo-700 hover:underline" href="{{ route('customers.show', $customer['id']) }}">{{ $customer['code'] }} — {{ $customer['name'] }}</a>
							@if ($customer['phone']) · {{ $customer['phone'] }} @endif
						</td>
					</tr>
					@foreach ($customer['invoices'] as $row)
						<tr>
							<td class="num">{{ $loop->first ? $csl : '' }}</td>
							<td>{{ $loop->first ? $customer['name'] : '' }}</td>
							<td><a class="text-indigo-700 hover:underline" href="{{ route('sales.invoices.show', $row['invoice_id']) }}">{{ $row['number'] }}</a></td>
							<td>{{ $row['date'] }}</td>
							<td class="{{ $row['overdue'] ? 'bg-rose-50 font-semibold text-rose-700' : '' }}">{{ $row['due_date'] ?? '—' }}</td>
							<td class="num">{{ $money($row['payable']) }}</td>
							<td class="num text-emerald-700">{{ $money($row['paid']) }}</td>
							<td class="num text-rose-700">{{ $money($row['due']) }}</td>
							@foreach ($months as $m)
								@php $c = $row['months'][$m['key']]; @endphp
								<td class="m {{ $m['month'] === 1 ? 'yr' : '' }} {{ $c['paid'] > 0 ? 'bg-emerald-50' : '' }}" @if ($c['due_open']) style="box-shadow: inset 0 0 0 2px #e11d48" title="Credit due month — not cleared" @endif>
									@if ($c['paid'] > 0 || $c['arrears'] > 0)
										<span class="two text-emerald-700">{{ $c['paid'] > 0 ? '✓ '.$money($c['paid']) : '–' }}</span>
										<span class="two text-rose-700">{{ $dash($c['arrears']) }}</span>
									@endif
									@if ($c['due_open'])<span class="two text-[10px] font-bold text-rose-700">◆ DUE</span>@endif
								</td>
							@endforeach
						</tr>
					@endforeach
					<tr class="tot">
						<td colspan="5" class="num">Customer total</td>
						<td class="num">{{ $money($customer['payable']) }}</td>
						<td class="num text-emerald-700">{{ $money($customer['paid']) }}</td>
						<td class="num text-rose-700">{{ $money($customer['due']) }}</td>
						@foreach ($months as $m)
							@php $cm = $customer['months'][$m['key']]; @endphp
							<td class="m {{ $m['month'] === 1 ? 'yr' : '' }}">
								@if ($cm['paid'] > 0 || $cm['arrears'] > 0)
									<span class="two text-emerald-700">{{ $dash($cm['paid']) }}</span>
									<span class="two text-rose-700">{{ $dash($cm['arrears']) }}</span>
								@endif
							</td>
						@endforeach
					</tr>
				</tbody>
			@endforeach
			<tbody>
				<tr class="btot">
					<td colspan="5" class="num">Credit total — {{ $cg['name'] }}</td>
					<td class="num">{{ $money($cg['payable']) }}</td>
					<td class="num text-emerald-700">{{ $money($cg['paid']) }}</td>
					<td class="num text-rose-700">{{ $money($cg['due']) }}</td>
					@foreach ($months as $m)
						@php $cm = $cg['months'][$m['key']]; @endphp
						<td class="m {{ $m['month'] === 1 ? 'yr' : '' }}">
							@if ($cm['paid'] > 0 || $cm['arrears'] > 0)
								<span class="two text-emerald-700">{{ $dash($cm['paid']) }}</span>
								<span class="two text-rose-700">{{ $dash($cm['arrears']) }}</span>
							@endif
						</td>
					@endforeach
				</tr>
			</tbody>
		@empty
			<tbody><tr><td colspan="{{ $ccols }}" class="px-4 py-8 text-center text-sm font-medium text-slate-500">No unpaid credit invoices match these filters.</td></tr></tbody>
		@endforelse
		@if (count($credit['groups']))
			@php $cgt = $credit['grand']; @endphp
			<tfoot>
				<tr class="grand">
					<td colspan="5" class="num">Credit total ({{ $cgt['customers'] }} customers · {{ $cgt['invoices'] }} invoices)</td>
					<td class="num">{{ $money($cgt['payable']) }}</td>
					<td class="num text-emerald-700">{{ $money($cgt['paid']) }}</td>
					<td class="num text-rose-700">{{ $money($cgt['due']) }}</td>
					@foreach ($months as $m)
						@php $cm = $cgt['months'][$m['key']]; @endphp
						<td class="m {{ $m['month'] === 1 ? 'yr' : '' }}">
							@if ($cm['paid'] > 0 || $cm['arrears'] > 0)
								<span class="two text-emerald-700">{{ $dash($cm['paid']) }}</span>
								<span class="two text-rose-700">{{ $dash($cm['arrears']) }}</span>
							@endif
						</td>
					@endforeach
				</tr>
			</tfoot>
		@endif
	</table>
</div>
