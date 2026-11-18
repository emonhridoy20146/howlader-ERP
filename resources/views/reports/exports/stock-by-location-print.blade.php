@extends('reports.exports.print-layout')

@push('print-extra-styles')
	<style>
		body { color: #000; }
		table.data { margin-top: 4px; }
		table.data th {
			background: #fff;
			color: #111;
			border: 1px solid #000;
			font-weight: 700;
			font-size: 7px;
			letter-spacing: 0.06em;
			text-transform: uppercase;
		}
		table.data td {
			border: 1px solid #000;
			color: #000;
			vertical-align: top;
		}
		table.data td.sku {
			font-size: 8px;
			font-weight: 400;
			color: #333;
		}
		table.data td.product {
			font-size: 10px;
			font-weight: 600;
			color: #000;
		}
		table.data tr.line td.num {
			font-size: 9px;
			font-weight: 500;
		}
		table.data tr.loc-group td {
			background: #e8e8e8;
			color: #000;
			font-size: 13px;
			font-weight: 700;
			letter-spacing: 0.01em;
			padding: 10px 4px 6px;
			border: none;
			border-bottom: 2px solid #000;
		}
		table.data tbody tr:first-child.loc-group td { padding-top: 8px; }
		table.data tr.cat-group td {
			background: #fff;
			color: #000;
			font-size: 11px;
			font-weight: 700;
			letter-spacing: 0.01em;
			padding: 12px 4px 4px;
			border: none;
			border-bottom: 1.5px solid #000;
		}
		table.data tr.cat-group .cat-meta {
			display: inline-block;
			margin-left: 8px;
			font-size: 8px;
			font-weight: 500;
			letter-spacing: 0.04em;
			text-transform: uppercase;
			color: #444;
		}
		table.data tr.cat-subtotal td {
			background: #fafafa;
			font-size: 9px;
			font-weight: 700;
			padding: 6px 4px 8px;
			border-color: #000;
			border-top: 1px solid #000;
		}
		table.data tr.cat-subtotal .subtotal-label {
			font-size: 7px;
			font-weight: 700;
			letter-spacing: 0.08em;
			text-transform: uppercase;
			color: #222;
		}
		table.data tr.loc-subtotal td {
			background: #f0f0f0;
			font-size: 9px;
			font-weight: 700;
			padding: 6px 4px 8px;
			border-color: #000;
			border-top: 2px solid #000;
		}
		table.data tr.loc-subtotal .subtotal-label {
			font-size: 7px;
			font-weight: 700;
			letter-spacing: 0.08em;
			text-transform: uppercase;
			color: #222;
		}
		table.data th.idx,
		table.data td.idx { width: 24px; text-align: right; }
		table.data th.num,
		table.data td.num {
			text-align: right;
			font-variant-numeric: tabular-nums;
		}
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
		$kpis = $kpis ?? [];
		$uniqueProductCount = (int) ($kpis['products'] ?? $rows->pluck('product_id')->unique()->count());
		$totalOnHand = (int) ($kpis['on_hand'] ?? $rows->sum(fn ($r) => (int) $r->on_hand_qty));
		$lineNo = 0;

		$groupedByLocation = $rows->groupBy('location_id');
	@endphp
	<table class="data">
		<thead>
			<tr>
				<th class="idx num">#</th>
				<th>SKU</th>
				<th>Product</th>
				<th class="num">On hand</th>
				<th class="num">Reserved</th>
				<th class="num">Available</th>
				<th>Type</th>
			</tr>
		</thead>
		<tbody>
			@foreach ($groupedByLocation as $locationRows)
				@php
					$loc = $locationRows->first();
					$locationLabel = ! empty($loc->location_code)
						? $loc->location_code . ' — ' . ($loc->location_name ?? '')
						: ($loc->location_name ?? '—');

					$locOnHand = (int) $locationRows->sum(fn ($r) => (int) $r->on_hand_qty);
					$locReserved = (int) $locationRows->sum(fn ($r) => (int) $r->reserved_qty);
					$locAvailable = (int) $locationRows->sum(fn ($r) => (int) $r->available_qty);

					$groupedByCategory = $locationRows
						->sortBy(fn ($row) => mb_strtolower((string) ($row->category_name ?: 'Uncategorized')))
						->groupBy(fn ($row) => (string) ($row->category_id ?? '0'));
				@endphp
				<tr class="loc-group">
					<td colspan="7">{{ $locationLabel }}</td>
				</tr>
				@foreach ($groupedByCategory as $categoryRows)
					@php
						$cat = $categoryRows->first();
						$categoryName = $cat->category_name ?: 'Uncategorized';
						$catOnHand = (int) $categoryRows->sum(fn ($r) => (int) $r->on_hand_qty);
						$catReserved = (int) $categoryRows->sum(fn ($r) => (int) $r->reserved_qty);
						$catAvailable = (int) $categoryRows->sum(fn ($r) => (int) $r->available_qty);
						$sortedRows = $categoryRows->sortBy(fn ($r) => (string) $r->product_sku)->values();
					@endphp
					<tr class="cat-group">
						<td colspan="7">
							{{ $categoryName }}
							<span class="cat-meta">{{ $sortedRows->count() }} {{ $sortedRows->count() === 1 ? 'product' : 'products' }}</span>
						</td>
					</tr>
					@foreach ($sortedRows as $row)
						@php $lineNo++; @endphp
						<tr class="line">
							<td class="idx num">{{ $lineNo }}</td>
							<td class="sku">{{ $row->product_sku }}</td>
							<td class="product"><a href="{{ route('products.show', $row->product_id) }}" target="_blank" rel="noopener" style="color:inherit;text-decoration:none;">{{ $row->product_name }}</a></td>
							<td class="num">{{ number_format((int) $row->on_hand_qty) }}</td>
							<td class="num">{{ number_format((int) $row->reserved_qty) }}</td>
							<td class="num">{{ number_format((int) $row->available_qty) }}</td>
							<td>{{ (int) $row->is_serialized === 1 ? 'Serialized' : 'Non-serialized' }}</td>
						</tr>
					@endforeach
					<tr class="cat-subtotal">
						<td colspan="3"><span class="subtotal-label">Category total</span></td>
						<td class="num">{{ number_format($catOnHand) }}</td>
						<td class="num">{{ number_format($catReserved) }}</td>
						<td class="num">{{ number_format($catAvailable) }}</td>
						<td></td>
					</tr>
				@endforeach
				<tr class="loc-subtotal">
					<td colspan="3"><span class="subtotal-label">Location total</span></td>
					<td class="num">{{ number_format($locOnHand) }}</td>
					<td class="num">{{ number_format($locReserved) }}</td>
					<td class="num">{{ number_format($locAvailable) }}</td>
					<td></td>
				</tr>
			@endforeach
		</tbody>
	</table>

	<table class="summary-box">
		<tbody>
			<tr>
				<td>Products (distinct)</td>
				<td class="num">{{ number_format($uniqueProductCount) }}</td>
			</tr>
			<tr>
				<td>Total on hand (units)</td>
				<td class="num">{{ number_format($totalOnHand) }}</td>
			</tr>
		</tbody>
	</table>
@endsection
