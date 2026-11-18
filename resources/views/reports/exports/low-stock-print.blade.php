@extends('reports.exports.print-layout')

@section('report-table')
	<table class="data">
		<thead>
			<tr>
				<th class="num">#</th>
				<th>SKU</th>
				<th>Product</th>
				<th>Location</th>
				<th class="num">Reorder</th>
				<th class="num">On hand</th>
				<th class="num">Reserved</th>
				<th class="num">Available</th>
			</tr>
		</thead>
		<tbody>
			@foreach ($rows as $row)
				<tr>
					<td class="num">{{ $loop->iteration }}</td>
					<td>{{ $row->product_sku }}</td>
					<td>{{ $row->product_name }}</td>
					<td>{{ $row->location_name }}</td>
					<td class="num">{{ number_format((int) $row->reorder_level) }}</td>
					<td class="num">{{ number_format((int) $row->on_hand_qty) }}</td>
					<td class="num">{{ number_format((int) $row->reserved_qty) }}</td>
					<td class="num">{{ number_format((int) $row->available_qty) }}</td>
				</tr>
			@endforeach
		</tbody>
	</table>
@endsection
