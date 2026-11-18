@extends('reports.exports.print-layout')

@push('print-extra-styles')
	<style>
		body { color: #000; }
		table.data { margin-top: 4px; }
		table.data th {
			background: #fff;
			color: #000;
			border: 1px solid #000;
			font-weight: 700;
			font-size: 8px;
		}
		table.data td {
			border: 1px solid #000;
			color: #000;
			vertical-align: top;
		}
		table.data tr.loc-group td,
		table.data tr.cat-group td {
			background: #f5f5f5;
			font-weight: 700;
			font-size: 9px;
			padding: 6px 4px;
			border: 1px solid #000;
		}
		table.data tr.cat-group td { background: #fafafa; font-size: 8px; }
		table.data th.idx,
		table.data td.idx { width: 24px; text-align: right; }
		table.summary-box {
			width: 100%;
			max-width: 280px;
			margin-top: 14px;
			margin-left: auto;
			border-collapse: collapse;
			font-size: 9px;
		}
		table.summary-box td {
			border: 1px solid #000;
			padding: 6px 8px;
			vertical-align: top;
		}
		table.summary-box td:first-child { font-weight: 700; }
		table.summary-box td.num {
			text-align: right;
			font-weight: 700;
			font-variant-numeric: tabular-nums;
		}
	</style>
@endpush

@section('report-table')
	@php
		$groupedByLocation = $rows->groupBy('location_id');
		$lineNo = 0;
		$totalOnHand = (int) $rows->sum(fn ($r) => (int) $r->on_hand_qty);
		$totalMrpValue = $rows->sum(fn ($r) => (int) $r->on_hand_qty * (float) $r->mrp);
	@endphp
	<table class="data">
		<thead>
			<tr>
				<th class="idx num">#</th>
				<th>SKU</th>
				<th>Product</th>
				<th class="num">On hand</th>
				<th class="num">Available</th>
				<th class="num">MRP</th>
				<th class="num">Max disc. %</th>
				<th class="num">Max disc. amount</th>
				<th class="num">After discount</th>
			</tr>
		</thead>
		<tbody>
			@foreach ($groupedByLocation as $locationRows)
				@php $loc = $locationRows->first(); @endphp
				<tr class="loc-group">
					<td colspan="9">
						@if (! empty($loc->location_code))
							{{ $loc->location_code }} — {{ $loc->location_name }}
						@else
							{{ $loc->location_name ?? '—' }}
						@endif
					</td>
				</tr>
				@foreach ($locationRows->groupBy(fn ($row) => (string) ($row->category_id ?? '0')) as $categoryRows)
					@php $cat = $categoryRows->first(); @endphp
					<tr class="cat-group">
						<td colspan="9">{{ $cat->category_name ?: 'Uncategorized' }}</td>
					</tr>
					@foreach ($categoryRows as $row)
						@php $lineNo++; @endphp
						<tr>
							<td class="idx num">{{ $lineNo }}</td>
							<td>{{ $row->product_sku }}</td>
							<td>{{ $row->product_name }}</td>
							<td class="num">{{ number_format((int) $row->on_hand_qty) }}</td>
							<td class="num">{{ number_format((int) $row->available_qty) }}</td>
							<td class="num">{{ number_format((float) $row->mrp, 2) }}</td>
							<td class="num">{{ number_format((float) $row->max_discount_percent, 2) }}%</td>
							<td class="num">{{ number_format((float) $row->max_discount_amount, 2) }}</td>
							<td class="num">{{ number_format((float) $row->amount_after_discount, 2) }}</td>
						</tr>
					@endforeach
				@endforeach
			@endforeach
		</tbody>
	</table>

	<table class="summary-box">
		<tbody>
			<tr>
				<td>Total on hand (units)</td>
				<td class="num">{{ number_format($totalOnHand) }}</td>
			</tr>
			<tr>
				<td>MRP value (on hand × MRP)</td>
				<td class="num">{{ number_format($totalMrpValue, 2) }}</td>
			</tr>
		</tbody>
	</table>
@endsection
