{{-- Credit customers for one branch (print / PDF). Expects $cg (credit group), $months, $years, $money, $dash, $plural. --}}
@php $ccols = 8 + count($months); $csl = 0; @endphp
<p class="section-title" style="margin-top:10px;">Credit customers</p>
<table class="ycs">
	<thead>
		<tr>
			<th rowspan="2">SL</th><th rowspan="2">Customer</th><th rowspan="2">Invoice</th><th rowspan="2">Inv. date</th><th rowspan="2">Credit due date</th>
			<th rowspan="2">Total payable</th><th rowspan="2">Total paid</th><th rowspan="2">Total due</th>
			@foreach ($years as $year => $count)<th colspan="{{ $count }}" class="yr">{{ $year }}</th>@endforeach
		</tr>
		<tr>
			@foreach ($months as $m)<th class="{{ $m['month'] === 1 ? 'yr' : '' }}">{{ $m['month'] }}</th>@endforeach
		</tr>
	</thead>
	@foreach ($cg['customers'] as $customer)
		@php $csl++; @endphp
		<tbody class="cust">
			<tr class="ch"><td colspan="{{ $ccols }}">{{ $customer['code'] }} — {{ $customer['name'] }}@if ($customer['phone']) · {{ $customer['phone'] }}@endif</td></tr>
			@foreach ($customer['invoices'] as $row)
				<tr>
					<td class="n">{{ $loop->first ? $csl : '' }}</td>
					<td>{{ $loop->first ? $customer['name'] : '' }}</td>
					<td>{{ $row['number'] }}</td>
					<td>{{ $row['date'] }}</td>
					<td @if ($row['overdue']) class="st-missed" @endif>{{ $row['due_date'] ?? '—' }}</td>
					<td class="n">{{ $money($row['payable']) }}</td><td class="n">{{ $money($row['paid']) }}</td><td class="n">{{ $money($row['due']) }}</td>
					@foreach ($months as $m)
						@php $c = $row['months'][$m['key']]; @endphp
						<td class="m {{ $m['month'] === 1 ? 'yr' : '' }} {{ $c['paid'] > 0 ? 'st-paid' : '' }} {{ $c['due_open'] ? 'last-open' : '' }}">
							@if ($c['paid'] > 0 || $c['arrears'] > 0){{ $c['paid'] > 0 ? '✓ '.$money($c['paid']) : '–' }}<br><span class="arr">{{ $dash($c['arrears']) }}</span>@endif
							@if ($c['due_open'])<br><b>◆ DUE</b>@endif
						</td>
					@endforeach
				</tr>
			@endforeach
			<tr class="tot">
				<td colspan="5" class="n">Customer total (paid / total unpaid till month)</td>
				<td class="n">{{ $money($customer['payable']) }}</td><td class="n">{{ $money($customer['paid']) }}</td><td class="n">{{ $money($customer['due']) }}</td>
				@foreach ($months as $m)
					@php $cm = $customer['months'][$m['key']]; @endphp
					<td class="m {{ $m['month'] === 1 ? 'yr' : '' }} {{ $cm['due_open'] ? 'last-open' : '' }}">@if ($cm['paid'] > 0 || $cm['arrears'] > 0){{ $dash($cm['paid']) }}<br><span class="arr">{{ $dash($cm['arrears']) }}</span>@endif</td>
				@endforeach
			</tr>
		</tbody>
	@endforeach
	<tbody class="cust">
		<tr class="btot">
			<td colspan="5" class="n">Credit total — {{ $cg['name'] }} (paid / total unpaid till month)</td>
			<td class="n">{{ $money($cg['payable']) }}</td><td class="n">{{ $money($cg['paid']) }}</td><td class="n">{{ $money($cg['due']) }}</td>
			@foreach ($months as $m)
				@php $cm = $cg['months'][$m['key']]; @endphp
				<td class="m {{ $m['month'] === 1 ? 'yr' : '' }}">@if ($cm['paid'] > 0 || $cm['arrears'] > 0){{ $dash($cm['paid']) }}<br><span class="arr">{{ $dash($cm['arrears']) }}</span>@endif</td>
			@endforeach
		</tr>
	</tbody>
</table>
<table class="summary">
	<caption>Credit summary — {{ $cg['name'] }}</caption>
	<thead><tr><th>Customers</th><th>Invoices</th><th>Total payable</th><th>Total paid</th><th>Total due</th></tr></thead>
	<tbody><tr>
		<td class="n">{{ count($cg['customers']) }}</td><td class="n">{{ $cg['invoices'] }}</td><td class="n">{{ $money($cg['payable']) }}</td><td class="n">{{ $money($cg['paid']) }}</td><td class="n">{{ $money($cg['due']) }}</td>
	</tr></tbody>
</table>
