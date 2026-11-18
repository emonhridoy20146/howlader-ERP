@php
	$branchRows = $branchRows ?? collect();
	$first = $branchRows->first();
	$branchOnHand = (int) $branchRows->sum(fn ($row) => (int) $row->on_hand_qty);
	$branchReserved = (int) $branchRows->sum(fn ($row) => (int) $row->reserved_qty);
	$branchAvailable = (int) $branchRows->sum(fn ($row) => (int) $row->available_qty);
	$branchStockValue = (float) $branchRows->sum(fn ($row) => (float) ($row->total_stock_value ?? 0));
	$branchCostValue = (float) $branchRows->sum(fn ($row) => (float) ($row->value_at_cost ?? 0));
@endphp

<div class="overflow-x-auto overflow-y-hidden rounded-2xl bg-white shadow-sm ring-1 ring-slate-200">
	<div class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-200 bg-slate-50 px-4 py-3">
		<div>
			<p class="text-sm font-semibold text-slate-800">
				@if (! empty($first->location_code))
					{{ $first->location_code }} — {{ $first->location_name }}
				@else
					{{ $first->location_name ?? 'Unassigned' }}
				@endif
			</p>
			<p class="text-xs text-slate-500">
				{{ $branchRows->count() }} product lines on this page
				· On hand: {{ number_format($branchOnHand) }}
				· Stock value: {{ number_format($branchStockValue, 2) }}
			</p>
		</div>
	</div>

	<div class="overflow-x-auto">
		<table class="min-w-full text-sm whitespace-nowrap">
			<thead class="bg-white text-left text-xs font-semibold uppercase tracking-wider text-slate-700">
				<tr>
					<th class="px-4 py-3">SKU</th>
					<th class="px-4 py-3">Product</th>
					<th class="px-4 py-3">Category</th>
					<th class="px-4 py-3">Brand</th>
					<th class="px-4 py-3 text-right">On hand</th>
					<th class="px-4 py-3 text-right">Reserved</th>
					<th class="px-4 py-3 text-right">Available</th>
					<th class="px-4 py-3 text-right">Stock value</th>
					<th class="px-4 py-3 text-right">Value at cost</th>
				</tr>
			</thead>
			<tbody>
				@foreach ($branchRows as $row)
					<tr class="border-b border-slate-100 transition-colors hover:bg-slate-50/70">
						<td class="px-4 py-3 font-mono text-xs text-slate-800">{{ $row->sku }}</td>
						<td class="px-4 py-3 font-medium text-slate-800">{{ $row->product_name }}</td>
						<td class="px-4 py-3 text-slate-600">{{ $row->category_name ?: 'Uncategorized' }}</td>
						<td class="px-4 py-3 text-slate-600">{{ $row->brand_name ?: '—' }}</td>
						<td class="px-4 py-3 text-right tabular-nums">{{ number_format((int) $row->on_hand_qty) }}</td>
						<td class="px-4 py-3 text-right tabular-nums">{{ number_format((int) $row->reserved_qty) }}</td>
						<td class="px-4 py-3 text-right tabular-nums font-semibold {{ (int) $row->available_qty <= 0 ? 'text-rose-700' : 'text-emerald-700' }}">{{ number_format((int) $row->available_qty) }}</td>
						<td class="px-4 py-3 text-right tabular-nums font-medium text-slate-900">{{ number_format((float) ($row->total_stock_value ?? 0), 2) }}</td>
						<td class="px-4 py-3 text-right tabular-nums text-slate-700">{{ number_format((float) ($row->value_at_cost ?? 0), 2) }}</td>
					</tr>
				@endforeach
			</tbody>
			<tfoot class="border-t-2 border-slate-300 bg-slate-100 font-semibold text-slate-900">
				<tr>
					<td colspan="4" class="px-4 py-3 text-xs uppercase tracking-wide">Branch summary</td>
					<td class="px-4 py-3 text-right tabular-nums">{{ number_format($branchOnHand) }}</td>
					<td class="px-4 py-3 text-right tabular-nums">{{ number_format($branchReserved) }}</td>
					<td class="px-4 py-3 text-right tabular-nums">{{ number_format($branchAvailable) }}</td>
					<td class="px-4 py-3 text-right tabular-nums">{{ number_format($branchStockValue, 2) }}</td>
					<td class="px-4 py-3 text-right tabular-nums">{{ number_format($branchCostValue, 2) }}</td>
				</tr>
			</tfoot>
		</table>
	</div>
</div>
