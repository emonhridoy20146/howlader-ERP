@extends('layouts.app')
@section('title', 'Purchase Orders')
@section('page-title', 'Purchase Orders')
@section('content')
	<div class="mb-4 flex flex-col gap-2 sm:flex-row sm:flex-wrap sm:items-center sm:justify-end">
		<a href="{{ route('purchase-orders.create') }}" class="inline-flex justify-center rounded-lg bg-slate-900 px-4 py-2.5 text-sm font-medium text-white shadow-sm hover:bg-slate-800 sm:py-2">New PO</a>
	</div>

	@if (session('status'))
		<div class="mb-4 rounded-lg bg-emerald-50 p-3 text-sm text-emerald-700">{{ session('status') }}</div>
	@endif

	@php
		$filterSupplierOptions = $filterSuppliers->map(fn ($supplier) => ['value' => (string) $supplier->id, 'label' => $supplier->name])->values()->all();
		$filterWarehouseOptions = $filterLocations->map(fn ($location) => ['value' => (string) $location->id, 'label' => $location->name])->values()->all();
	@endphp

	<x-filter-drawer>
<form method="get" action="{{ route('purchase-orders.index') }}" class="flex flex-col gap-4">
		<div class="flex flex-col gap-4">
			<div class="flex flex-col gap-1  ">
				<label for="po-filter-q" class="text-xs font-medium text-slate-600">Search</label>
				<input id="po-filter-q" type="text" name="q" value="{{ $filters['q'] ?? '' }}" placeholder="PO #, supplier, notes, product…" class="rounded-lg border border-slate-300 px-3 py-2 text-sm">
			</div>
			<div class="flex flex-col gap-1">
				<label for="po-filter-status" class="text-xs font-medium text-slate-600">Status</label>
				<select id="po-filter-status" name="status" class="rounded-lg border border-slate-300 px-3 py-2 text-sm">
					<option value="">All status</option>
					@foreach(['draft', 'approved', 'partially_received', 'received', 'cancelled'] as $status)
						<option value="{{ $status }}" @selected(($filters['status'] ?? '') === $status)>{{ ucfirst(str_replace('_', ' ', $status)) }}</option>
					@endforeach
				</select>
			</div>
			<div class="flex flex-col gap-1">
				<label for="po-filter-supplier" class="text-xs font-medium text-slate-600">Supplier</label>
				<x-searchable-select
					name="supplier_id"
					:options="$filterSupplierOptions"
					:selected="$filters['supplier_id'] ?? ''"
					placeholder="All suppliers"
				/>
			</div>
			<div class="flex flex-col gap-1">
				<label for="po-filter-location" class="text-xs font-medium text-slate-600">Warehouse</label>
				<x-searchable-select
					name="location_id"
					:options="$filterWarehouseOptions"
					:selected="$filters['location_id'] ?? ''"
					placeholder="All warehouses"
				/>
			</div>
			<div class="flex flex-col gap-1">
				<label for="po-filter-date-from" class="text-xs font-medium text-slate-600">Ordered from</label>
				<input id="po-filter-date-from" type="date" name="date_from" value="{{ $filters['date_from'] ?? '' }}" class="rounded-lg border border-slate-300 px-3 py-2 text-sm">
			</div>
			<div class="flex flex-col gap-1">
				<label for="po-filter-date-to" class="text-xs font-medium text-slate-600">Ordered to</label>
				<input id="po-filter-date-to" type="date" name="date_to" value="{{ $filters['date_to'] ?? '' }}" class="rounded-lg border border-slate-300 px-3 py-2 text-sm">
			</div>
		</div>
		<div class="flex flex-col gap-4">
			<div class="flex flex-col gap-1">
				<label for="po-filter-expected-from" class="text-xs font-medium text-slate-600">Expected from</label>
				<input id="po-filter-expected-from" type="date" name="expected_from" value="{{ $filters['expected_from'] ?? '' }}" class="rounded-lg border border-slate-300 px-3 py-2 text-sm">
			</div>
			<div class="flex flex-col gap-1">
				<label for="po-filter-expected-to" class="text-xs font-medium text-slate-600">Expected to</label>
				<input id="po-filter-expected-to" type="date" name="expected_to" value="{{ $filters['expected_to'] ?? '' }}" class="rounded-lg border border-slate-300 px-3 py-2 text-sm">
			</div>
			<div class="flex flex-col gap-1">
				<label for="po-filter-total-min" class="text-xs font-medium text-slate-600">Grand total min</label>
				<input id="po-filter-total-min" type="number" name="total_min" value="{{ $filters['total_min'] ?? '' }}" step="0.01" min="0" class="rounded-lg border border-slate-300 px-3 py-2 text-sm">
			</div>
			<div class="flex flex-col gap-1">
				<label for="po-filter-total-max" class="text-xs font-medium text-slate-600">Grand total max</label>
				<input id="po-filter-total-max" type="number" name="total_max" value="{{ $filters['total_max'] ?? '' }}" step="0.01" min="0" class="rounded-lg border border-slate-300 px-3 py-2 text-sm">
			</div>
			<div class="flex flex-col gap-1">
				<label for="po-filter-sort" class="text-xs font-medium text-slate-600">Sort by</label>
				<select id="po-filter-sort" name="sort" class="rounded-lg border border-slate-300 px-3 py-2 text-sm">
					<option value="ordered_at" @selected(($filters['sort'] ?? 'ordered_at') === 'ordered_at')>Ordered date</option>
					<option value="expected_at" @selected(($filters['sort'] ?? '') === 'expected_at')>Expected date</option>
					<option value="total_amount" @selected(($filters['sort'] ?? '') === 'total_amount')>Grand total</option>
					<option value="po_number" @selected(($filters['sort'] ?? '') === 'po_number')>PO number</option>
					<option value="status" @selected(($filters['sort'] ?? '') === 'status')>Status</option>
					<option value="created_at" @selected(($filters['sort'] ?? '') === 'created_at')>Created</option>
				</select>
			</div>
			<div class="flex flex-col gap-1">
				<label for="po-filter-dir" class="text-xs font-medium text-slate-600">Direction</label>
				<select id="po-filter-dir" name="dir" class="rounded-lg border border-slate-300 px-3 py-2 text-sm">
					<option value="desc" @selected(($filters['dir'] ?? 'desc') === 'desc')>Descending</option>
					<option value="asc" @selected(($filters['dir'] ?? '') === 'asc')>Ascending</option>
				</select>
			</div>
		</div>
		<p class="text-xs text-slate-500">Date range filters apply to <strong>ordered</strong> date. Search matches PO number, supplier, notes, and line products (name, SKU, model, barcode).</p>
		<x-index-filter-actions :reset-url="route('purchase-orders.index')" />
	</form>
</x-filter-drawer>

	<div class="mb-4 grid gap-3 sm:grid-cols-5">
		<x-status-card label="Total PO">
			{{ $kpis['total'] }}
		</x-status-card>
		<x-status-card label="Draft">
			{{ $kpis['draft'] }}
		</x-status-card>
		<x-status-card label="Open">
			{{ $kpis['open'] }}
		</x-status-card>
		<x-status-card label="Filtered">
			{{ $kpis['filtered'] }}
		</x-status-card>
		<x-status-card label="Filtered Amount">
			{{ number_format($kpis['filtered_amount'], 2) }}
		</x-status-card>
	</div>

	<x-index-table-shell title="Purchase orders" :meta="$purchaseOrders->total().' records'">
		<table class="index-data-table min-w-[56rem] sm:min-w-full whitespace-nowrap">
				<thead class="bg-slate-50/80 text-left text-[11px] font-semibold uppercase tracking-wide text-slate-600">
					<tr>
						<th class="px-4 py-3 sm:px-6">PO No</th>
						<th class="px-4 py-3">Supplier</th>
						<th class="px-4 py-3">Warehouse</th>
						<th class="px-4 py-3">Status</th>
						<th class="px-4 py-3">Ordered</th>
						<th class="px-4 py-3">Expected</th>
						<th class="px-4 py-3 text-right">Grand total</th>
						<th class="px-4 py-3 text-right">Paid</th>
						<th class="px-4 py-3 text-right">Due</th>
						<th class="px-4 py-3 text-right sm:px-6">Actions</th>
					</tr>
				</thead>
				<tbody class="divide-y divide-slate-100">
					@forelse($purchaseOrders as $po)
						<tr class="transition hover:bg-slate-50/70">
							<td class="px-4 py-4 sm:px-6 font-mono text-xs font-semibold text-slate-700">{{ $po->po_number }}</td>
							<td class="px-4 py-4"><a href="{{ route('suppliers.show', $po->supplier) }}" class="text-slate-800 hover:underline hover:text-base transition-all">{{ $po->supplier->name }}</a></td>
							<td class="px-4 py-4 text-slate-700">{{ $po->destinationLocation?->name }}</td>
							<td class="px-4 py-4">
								<span class="inline-flex rounded-full px-2.5 py-1 text-xs font-semibold {{ $po->status === 'draft' ? 'bg-amber-100 text-amber-700' : 'bg-slate-100 text-slate-700' }}">{{ ucfirst(str_replace('_', ' ', $po->status)) }}</span>
							</td>
							<td class="px-4 py-4 text-slate-700">{{ optional($po->ordered_at)->format('Y-m-d') }}</td>
							<td class="px-4 py-4 text-slate-600">{{ optional($po->expected_at)?->format('Y-m-d') ?? '—' }}</td>
							@php
								$supplierPaid = (float) ($po->payments_sum_amount ?? 0);
								$extraCosts = (float) ($po->additional_cost_amount ?? 0);
								$paidTowardPo = round($supplierPaid + $extraCosts, 2);
								$dueTowardPo = max(0, round((float) $po->total_amount - $paidTowardPo, 2));
							@endphp
							<td class="px-4 py-4 text-right tabular-nums text-slate-800">{{ number_format((float) $po->total_amount, 2) }}</td>
							<td class="px-4 py-4 text-right tabular-nums text-slate-600">{{ number_format($paidTowardPo, 2) }}</td>
							<td class="px-4 py-4 text-right tabular-nums font-medium {{ $dueTowardPo > 0.009 ? 'text-amber-800' : 'text-slate-500' }}">{{ number_format($dueTowardPo, 2) }}</td>
							<td class="px-4 py-4 text-right sm:px-6">
								<x-index-row-actions-menu>
									<a href="{{ route('purchase-orders.show', $po) }}">View</a>
									@can('update', $po)
										@if ($po->status !== 'cancelled')
											<a href="{{ route('purchase-orders.edit', $po) }}">Edit</a>
										@endif
									@endcan
									@can('delete', $po)
										@if ($po->status !== 'cancelled')
										<form action="{{ route('purchase-orders.destroy', $po) }}" method="post" data-confirm="Delete this purchase order? This will also remove linked receipts and payment journal entries.">
											@csrf
											@method('DELETE')
											<button type="submit" class="index-actions-menu-item--danger">Delete</button>
										</form>
										@endif
									@endcan
									@if($po->status === 'draft')
										<form action="{{ route('purchase-orders.approve', $po) }}" method="post" data-confirm="Approve this purchase order?">
											@csrf
											<button type="submit" class="index-actions-menu-item">Approve</button>
										</form>
									@endif
								</x-index-row-actions-menu>
							</td>
						</tr>
					@empty
						<tr>
							<td colspan="10" class="px-4 py-8 text-center text-slate-500">No purchase orders found.</td>
						</tr>
					@endforelse
				</tbody>
			</table>
	</x-index-table-shell>
	<x-pagination-limit-selector />

	<div class="mt-4">{{ $purchaseOrders->links() }}</div>
@endsection
