@extends('reports.exports.print-layout')

@section('report-table')
	<style>
		.text-right { text-align: right; }
		.font-medium { font-weight: bold; }
	</style>
	<table class="data">
		<thead>
			<tr>
				<th class="num">#</th>
				<th>SKU</th>
				<th>Product name</th>
				<th class="num">Qty ordered</th>
				<th class="num">Qty received</th>
				<th class="num">Total amount</th>
			</tr>
		</thead>
		<tbody>
			@foreach ($rows as $row)
				<tr>
					<td class="num">{{ $loop->iteration }}</td>
					<td>{{ $row->sku }}</td>
					<td class="font-medium">{{ $row->name }}</td>
					<td class="num">{{ Number::format($row->total_qty_ordered) }}</td>
					<td class="num">{{ Number::format($row->total_qty_received) }}</td>
					<td class="num">{{ Number::format($row->total_amount, 2) }}</td>
				</tr>
			@endforeach
			@if ($rows->isEmpty())
				<tr>
					<td colspan="6" style="text-align: center; padding: 20px;">No items found.</td>
				</tr>
			@endif
		</tbody>
	</table>
@endsection
