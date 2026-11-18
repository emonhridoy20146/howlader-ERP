@extends('reports.exports.print-layout')

@section('report-table')
	<table class="data">
		<thead>
			<tr>
				<th class="num">#</th>
				<th>Supplier</th>
				<th>Phone</th>
				<th class="num">POs</th>
				<th class="num">Due</th>
				<th class="num">Paid</th>
			</tr>
		</thead>
		<tbody>
			@foreach ($rows as $r)
				<tr>
					<td class="num">{{ $loop->iteration }}</td>
					<td>{{ $r->name }}</td>
					<td>{{ $r->phone }}</td>
					<td class="num">{{ (int) $r->po_count }}</td>
					<td class="num">{{ number_format((float) $r->total_due, 2) }}</td>
					<td class="num">{{ number_format((float) $r->total_paid, 2) }}</td>
				</tr>
			@endforeach
		</tbody>
		<tfoot>
			<tr>
				<th colspan="4">Totals</th>
				<th class="num">{{ number_format((float) ($totalDue ?? 0), 2) }}</th>
				<th class="num">{{ number_format((float) ($totalPaid ?? 0), 2) }}</th>
			</tr>
		</tfoot>
	</table>
@endsection
