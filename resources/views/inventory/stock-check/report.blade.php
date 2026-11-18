@extends('reports.exports.print-layout')

@push('print-extra-styles')
	<style>
		body { color: #000; }
		.report-section-title {
			margin: 16px 0 6px;
			font-size: 10px;
			font-weight: 700;
			text-transform: uppercase;
			letter-spacing: 0.06em;
			color: #111827;
		}
		.report-section-title:first-child { margin-top: 0; }
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
			font-size: 9px;
		}
		table.data th.num,
		table.data td.num {
			text-align: right;
			font-variant-numeric: tabular-nums;
		}
		table.summary-box {
			width: 100%;
			max-width: 420px;
			margin-top: 4px;
			margin-bottom: 12px;
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
		.report-note {
			margin-top: 10px;
			font-size: 9px;
			color: #374151;
			line-height: 1.45;
		}
	</style>
@endpush

@section('report-table')
	@php
		$summary = $report['summary'] ?? [];
	@endphp

	<table class="summary-box">
		<tbody>
			<tr>
				<td>Expected units</td>
				<td class="num">{{ number_format((int) ($summary['expected_units'] ?? 0)) }}</td>
			</tr>
			<tr>
				<td>Checked units</td>
				<td class="num">{{ number_format((int) ($summary['checked_units'] ?? 0)) }}</td>
			</tr>
			<tr>
				<td>Missing units</td>
				<td class="num">{{ number_format((int) ($summary['missing_units'] ?? 0)) }}</td>
			</tr>
			<tr>
				<td>Extra scans</td>
				<td class="num">{{ number_format((int) ($summary['extra_count'] ?? 0)) }}</td>
			</tr>
		</tbody>
	</table>

	<p class="report-note">
		Serials: {{ (int) ($summary['serial_checked'] ?? 0) }} checked of {{ (int) ($summary['serial_expected'] ?? 0) }}
		({{ (int) ($summary['serial_missing'] ?? 0) }} missing)
		@if (($summary['product_lines'] ?? 0) > 0)
			· Bulk product lines: {{ (int) $summary['product_lines'] }}
		@endif
	</p>

	@if (count($report['missing_serials'] ?? []) > 0)
		<p class="report-section-title">Missing serials (not scanned)</p>
		<table class="data">
			<thead>
				<tr>
					<th>SKU</th>
					<th>Product</th>
					<th>Label</th>
					<th>Serial</th>
					<th>Status</th>
				</tr>
			</thead>
			<tbody>
				@foreach ($report['missing_serials'] as $row)
					<tr>
						<td>{{ $row['sku'] }}</td>
						<td>{{ $row['name'] }}</td>
						<td>{{ $row['label_code'] ?? '—' }}</td>
						<td>{{ $row['serial_number'] }}</td>
						<td>{{ $row['status_label'] }}</td>
					</tr>
				@endforeach
			</tbody>
		</table>
	@endif

	@if (count($report['missing_products'] ?? []) > 0)
		<p class="report-section-title">Missing or partial bulk stock</p>
		<table class="data">
			<thead>
				<tr>
					<th>SKU</th>
					<th>Product</th>
					<th class="num">Expected</th>
					<th class="num">Checked</th>
					<th class="num">Missing</th>
				</tr>
			</thead>
			<tbody>
				@foreach ($report['missing_products'] as $row)
					<tr>
						<td>{{ $row['sku'] }}</td>
						<td>{{ $row['name'] }}</td>
						<td class="num">{{ number_format((int) $row['expected_qty']) }}</td>
						<td class="num">{{ number_format((int) $row['checked_qty']) }}</td>
						<td class="num">{{ number_format((int) $row['missing_qty']) }}</td>
					</tr>
				@endforeach
			</tbody>
		</table>
	@endif

	@if (count($report['matched_serials'] ?? []) > 0)
		<p class="report-section-title">Checked serials</p>
		<table class="data">
			<thead>
				<tr>
					<th>SKU</th>
					<th>Product</th>
					<th>Label</th>
					<th>Serial</th>
				</tr>
			</thead>
			<tbody>
				@foreach ($report['matched_serials'] as $row)
					<tr>
						<td>{{ $row['sku'] }}</td>
						<td>{{ $row['name'] }}</td>
						<td>{{ $row['label_code'] ?? '—' }}</td>
						<td>{{ $row['serial_number'] }}</td>
					</tr>
				@endforeach
			</tbody>
		</table>
	@endif

	@if (count($report['extras'] ?? []) > 0)
		<p class="report-section-title">Extra scans</p>
		<table class="data">
			<thead>
				<tr>
					<th>Type</th>
					<th>Code</th>
					<th>SKU</th>
					<th>Product</th>
					<th>Note</th>
				</tr>
			</thead>
			<tbody>
				@foreach ($report['extras'] as $row)
					<tr>
						<td>{{ ucfirst($row['kind'] ?? 'item') }}</td>
						<td>{{ $row['label_code'] ?? $row['serial_number'] ?? $row['barcode'] ?? '—' }}</td>
						<td>{{ $row['sku'] ?? '—' }}</td>
						<td>{{ $row['name'] ?? '—' }}</td>
						<td>
							@if (($row['kind'] ?? '') === 'serial')
								{{ $row['status_label'] ?? '' }}
								@if (! empty($row['location']['name']))
									· {{ $row['location']['name'] }}
								@endif
							@else
								No stock at branch
							@endif
						</td>
					</tr>
				@endforeach
			</tbody>
		</table>
	@endif
@endsection
