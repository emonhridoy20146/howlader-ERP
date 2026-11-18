@extends('reports.exports.print-layout')

@section('report-table')
	<div style="margin-bottom:10px;padding:6px 8px;border:1px solid #94a3b8;background:#f8fafc;font-size:9px;">
		<strong>{{ number_format($kpis['listed']) }}</strong> products ·
		<strong>{{ number_format($kpis['on_hand']) }}</strong> on hand ·
		<strong>{{ number_format($kpis['available']) }}</strong> available
	</div>
	<table class="data">
		<thead>
			<tr>
				<th class="num">#</th>
				<th>Product</th>
				<th>Model</th>
				<th>Brand</th>
				<th>Category</th>
				<th class="num">Sale price</th>
				<th class="num">On hand</th>
				<th class="num">Reserved</th>
				<th class="num">Available</th>
			</tr>
		</thead>
		<tbody>
			@forelse ($products as $index => $product)
				@php
					$inventory = $product->inventory_summary ?? ['on_hand_qty' => 0, 'reserved_qty' => 0, 'available_qty' => 0];
				@endphp
				<tr>
					<td class="num">{{ $index + 1 }}</td>
					<td>{{ $product->name }}</td>
					<td>{{ $product->model_number ?: '—' }}</td>
					<td>{{ $product->brand?->name ?: '—' }}</td>
					<td>{{ $product->category?->name ?: '—' }}</td>
					<td class="num">{{ number_format((float) $product->default_sale_price, 2) }}</td>
					<td class="num">{{ number_format((int) $inventory['on_hand_qty']) }}</td>
					<td class="num">{{ number_format((int) $inventory['reserved_qty']) }}</td>
					<td class="num">{{ number_format((int) $inventory['available_qty']) }}</td>
				</tr>
			@empty
				<tr>
					<td colspan="9">No products match the current filters.</td>
				</tr>
			@endforelse
		</tbody>
	</table>
@endsection
