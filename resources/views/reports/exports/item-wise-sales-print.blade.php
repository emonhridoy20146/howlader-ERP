@extends('reports.exports.print-layout')

@section('report-table')
	@php
		function bdFmt(float $n, int $d = 2): string {
			$neg = $n < 0; $n = abs($n);
			$dec = $d > 0 ? ('.' . substr(number_format($n, $d, '.', ''), -$d)) : '';
			$int = (string)(int)$n;
			if (strlen($int) <= 3) { $fmt = $int; }
			else { $fmt = strrev(implode(',', str_split(strrev(substr($int, 0, -3)), 2))) . ',' . substr($int, -3); }
			return ($neg ? '-' : '') . $fmt . $dec;
		}
	@endphp
	<style>
		.text-right { text-align: right; }
		.font-medium { font-weight: bold; }
	</style>
	<table class="data">
		<thead>
			<tr>
				<th>SL</th>
				<th>SKU</th>
				<th>Product name</th>
				<th class="num">Total quantity</th>
				<th class="num">Total revenue</th>
			</tr>
		</thead>
		<tbody>
			@php $grandTotal = 0; @endphp
			@foreach ($rows as $index => $row)
				@php $grandTotal += (float) $row->total_revenue; @endphp
				<tr>
					<td>{{ $index + 1 }}</td>
					<td>{{ $row->sku }}</td>
					<td class="font-medium">{{ $row->name }}</td>
					<td class="num">{{ bdFmt((float) $row->total_qty, 0) }}</td>
					<td class="num">BDT {{ bdFmt((float) $row->total_revenue) }}</td>
				</tr>
			@endforeach
			@if ($rows->isEmpty())
				<tr>
					<td colspan="5" style="text-align: center; padding: 20px;">No items found.</td>
				</tr>
			@else
				<tr style="font-weight: bold; border-top: 2px solid #000;">
					<td colspan="4" class="text-right">Total Revenue:</td>
					<td class="num">BDT {{ bdFmt($grandTotal) }}</td>
				</tr>
			@endif
		</tbody>
	</table>
@endsection
