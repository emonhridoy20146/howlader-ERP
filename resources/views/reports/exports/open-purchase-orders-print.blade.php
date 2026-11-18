@extends('reports.exports.print-layout')

@section('report-table')
	<table class="data">
		<thead>
			<tr>
				<th class="num">#</th>
				<th>PO</th>
				<th>Ordered</th>
				<th>Supplier</th>
				<th>Destination</th>
				<th>Status</th>
				<th class="num">Total</th>
				<th class="num">Settled</th>
				<th class="num">Due</th>
			</tr>
		</thead>
		<tbody>
			@foreach ($rows as $po)
				<tr>
					<td class="num">{{ $loop->iteration }}</td>
					<td>{{ $po->po_number }}</td>
					<td>{{ $po->ordered_at?->toDateString() }}</td>
					<td>{{ $po->supplier?->name }}</td>
					<td>{{ $po->destinationLocation?->name }}</td>
					<td>{{ $po->status }}</td>
					<td class="num">{{ number_format((float) $po->total_amount, 2) }}</td>
					<td class="num">{{ number_format($po->amountPaid(), 2) }}</td>
					<td class="num">{{ number_format($po->amountDue(), 2) }}</td>
				</tr>
			@endforeach
		</tbody>
	</table>
@endsection
