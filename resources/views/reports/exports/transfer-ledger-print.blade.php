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
			font-size: 8px;
		}
		table.data th.idx,
		table.data td.idx { width: 24px; text-align: right; }
		table.summary-box {
			width: 100%;
			max-width: 320px;
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
	@php $lineNo = 0; @endphp
	<table class="data">
		<thead>
			<tr>
				<th class="idx num">#</th>
				<th>Date/time</th>
				<th>Transfer</th>
				<th>Status</th>
				<th>Movement</th>
				<th>Source</th>
				<th>Destination</th>
				<th>Ledger branch</th>
				<th>Product</th>
				<th>Serial</th>
				<th class="num">Qty</th>
				<th>Actor</th>
			</tr>
		</thead>
		<tbody>
			@foreach ($rows as $row)
				@php
					$lineNo++;
					$delta = (int) $row->quantity_delta;
					$movementLabel = match ($row->movement_type) {
						'transfer_issue' => 'Issue (out)',
						'transfer_receive' => 'Receive (in)',
						default => \Illuminate\Support\Str::of($row->movement_type)->replace('_', ' ')->title(),
					};
				@endphp
				<tr>
					<td class="idx num">{{ $lineNo }}</td>
					<td>{{ \Illuminate\Support\Carbon::parse($row->event_time)->format('Y-m-d H:i') }}</td>
					<td>{{ $row->transfer_number }}</td>
					<td>{{ ucfirst(str_replace('_', ' ', $row->transfer_status)) }}</td>
					<td>{{ $movementLabel }}</td>
					<td>{{ $row->source_location_name }}</td>
					<td>{{ $row->destination_location_name }}</td>
					<td>{{ $row->ledger_location_name }}</td>
					<td>{{ $row->product_sku }} — {{ $row->product_name }}</td>
					<td>{{ $row->serial_number ?: '—' }}</td>
					<td class="num">{{ $delta >= 0 ? '+' : '' }}{{ number_format($delta) }}</td>
					<td>{{ $row->actor_name ?: '—' }}</td>
				</tr>
			@endforeach
		</tbody>
	</table>

	<table class="summary-box">
		<tbody>
			<tr>
				<td>Ledger entries</td>
				<td class="num">{{ number_format($kpis['ledger_entries']) }}</td>
			</tr>
			<tr>
				<td>Transfers</td>
				<td class="num">{{ number_format($kpis['transfers']) }}</td>
			</tr>
			<tr>
				<td>Units issued</td>
				<td class="num">{{ number_format($kpis['units_issued']) }}</td>
			</tr>
			<tr>
				<td>Units received</td>
				<td class="num">{{ number_format($kpis['units_received']) }}</td>
			</tr>
		</tbody>
	</table>
@endsection
