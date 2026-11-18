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
		table.data td.sku,
		table.data td.brand {
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
		table.data tr.cat-group td {
			background: #fff;
			color: #000;
			font-size: 12px;
			font-weight: 700;
			letter-spacing: 0.01em;
			padding: 14px 4px 4px;
			border: none;
			border-bottom: 1.5px solid #000;
		}
		table.data tbody tr:first-child.cat-group td { padding-top: 8px; }
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
		table.data tr.cat-subtotal td.num {
			font-size: 10px;
		}
		table.summary-box caption {
			caption-side: top;
			text-align: left;
			font-size: 8px;
			font-weight: 700;
			letter-spacing: 0.08em;
			text-transform: uppercase;
			padding: 0 0 6px;
		}
		table.summary-box td { font-size: 9px; font-weight: 500; }
		table.summary-box td.num { font-size: 11px; }
		table.data th.idx,
		table.data td.idx { width: 24px; text-align: right; }
		table.data th.num,
		table.data td.num {
			text-align: right;
			font-variant-numeric: tabular-nums;
		}
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
	@php
		$kpis = $kpis ?? [];
		$groupedByCategory = $rows
			->sortBy(fn ($row) => mb_strtolower((string) ($row->category_name ?: 'Uncategorized')))
			->groupBy(fn ($row) => (string) ($row->category_id ?? '0'));
		$lineNo = 0;
		$totalStockValue = (float) ($kpis['total_stock_value'] ?? $rows->sum(fn ($r) => (float) ($r->total_stock_value ?? 0)));
		$totalCostValue = (float) ($kpis['value_at_cost'] ?? $rows->sum(fn ($r) => (float) ($r->value_at_cost ?? 0)));
	@endphp
	<table class="data">
		<thead>
			<tr>
				<th class="idx num">#</th>
				<th>SKU</th>
				<th>Product</th>
				<th>Brand</th>
				<th class="num">On hand</th>
				<th class="num">Reserved</th>
				<th class="num">Available</th>
				<th class="num">Stock value</th>
				<th class="num">Value at cost</th>
			</tr>
		</thead>
		<tbody>
			@foreach ($groupedByCategory as $categoryRows)
				@php
					$cat = $categoryRows->first();
					$categoryName = $cat->category_name ?: 'Uncategorized';
					$productRows = $categoryRows
						->groupBy(fn ($row) => (string) ($row->product_id ?? $row->sku ?? ''))
						->map(function ($lines) {
							$first = $lines->first();

							return (object) [
								'sku' => $first->sku ?? '',
								'product_name' => $first->product_name ?? '',
								'brand_name' => $first->brand_name ?: '—',
								'on_hand_qty' => (int) $lines->sum(fn ($row) => (int) $row->on_hand_qty),
								'reserved_qty' => (int) $lines->sum(fn ($row) => (int) $row->reserved_qty),
								'available_qty' => (int) $lines->sum(fn ($row) => (int) $row->available_qty),
								'total_stock_value' => (float) $lines->sum(fn ($row) => (float) ($row->total_stock_value ?? 0)),
								'value_at_cost' => (float) $lines->sum(fn ($row) => (float) ($row->value_at_cost ?? 0)),
							];
						})
						->sortBy(fn ($row) => (string) $row->sku)
						->values();
					$categoryOnHand = (int) $productRows->sum('on_hand_qty');
					$categoryReserved = (int) $productRows->sum('reserved_qty');
					$categoryAvailable = (int) $productRows->sum('available_qty');
					$categoryStockValue = (float) $productRows->sum('total_stock_value');
					$categoryCostValue = (float) $productRows->sum('value_at_cost');
				@endphp
				<tr class="cat-group">
					<td colspan="9">
						{{ $categoryName }}
						<span class="cat-meta">{{ $productRows->count() }} {{ $productRows->count() === 1 ? 'product' : 'products' }}</span>
					</td>
				</tr>
				@foreach ($productRows as $row)
					@php $lineNo++; @endphp
					<tr class="line">
						<td class="idx num">{{ $lineNo }}</td>
						<td class="sku">{{ $row->sku }}</td>
						<td class="product">{{ $row->product_name }}</td>
						<td class="brand">{{ $row->brand_name }}</td>
						<td class="num">{{ number_format($row->on_hand_qty) }}</td>
						<td class="num">{{ number_format($row->reserved_qty) }}</td>
						<td class="num">{{ number_format($row->available_qty) }}</td>
						<td class="num">{{ number_format($row->total_stock_value, 2) }}</td>
						<td class="num">{{ number_format($row->value_at_cost, 2) }}</td>
					</tr>
				@endforeach
				<tr class="cat-subtotal">
					<td colspan="4"><span class="subtotal-label">Category total</span></td>
					<td class="num">{{ number_format($categoryOnHand) }}</td>
					<td class="num">{{ number_format($categoryReserved) }}</td>
					<td class="num">{{ number_format($categoryAvailable) }}</td>
					<td class="num">{{ number_format($categoryStockValue, 2) }}</td>
					<td class="num">{{ number_format($categoryCostValue, 2) }}</td>
				</tr>
			@endforeach
		</tbody>
	</table>

	<table class="summary-box">
		<caption>Report total</caption>
		<tbody>
			<tr>
				<td>Total on hand (units)</td>
				<td class="num">{{ number_format((int) ($kpis['on_hand'] ?? $rows->sum(fn ($r) => (int) $r->on_hand_qty))) }}</td>
			</tr>
			<tr>
				<td>Total stock value (on hand × default sale price)</td>
				<td class="num">{{ number_format($totalStockValue, 2) }}</td>
			</tr>
			<tr>
				<td>Value at cost (on hand × default purchase price)</td>
				<td class="num">{{ number_format($totalCostValue, 2) }}</td>
			</tr>
		</tbody>
	</table>
@endsection
