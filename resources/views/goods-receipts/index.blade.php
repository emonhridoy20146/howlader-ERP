@extends('layouts.app')
@section('title', 'Goods Receipts')
@section('page-title', 'Goods Receipts')
@section('content')
	<div class="mb-4 flex flex-col gap-2 sm:flex-row sm:flex-wrap sm:items-center sm:justify-end">
		<a href="{{ route('goods-receipts.create') }}" class="inline-flex justify-center rounded-lg bg-slate-900 px-4 py-2.5 text-sm font-medium text-white shadow-sm hover:bg-slate-800 sm:py-2">New GRN</a>
	</div>

	@if (session('status'))
		<div class="mb-4 rounded-lg bg-emerald-50 p-3 text-sm text-emerald-700">{{ session('status') }}</div>
	@endif

	<x-filter-drawer>
<form method="get" action="{{ route('goods-receipts.index') }}" class="flex flex-col gap-4">
		<div class="flex flex-col gap-4">
			<div class="flex flex-col gap-1  ">
				<label for="grn-filter-q" class="text-xs font-medium text-slate-600">Search</label>
				<input id="grn-filter-q" type="text" name="q" value="{{ $filters['q'] ?? '' }}" placeholder="GRN, note, supplier, PO, product…" class="rounded-lg border border-slate-300 px-3 py-2 text-sm">
			</div>
			<div class="flex flex-col gap-1">
				<label for="grn-filter-status" class="text-xs font-medium text-slate-600">Status</label>
				<select id="grn-filter-status" name="status" class="rounded-lg border border-slate-300 px-3 py-2 text-sm">
					<option value="">All status</option>
					@foreach(['draft', 'posted', 'cancelled'] as $status)
						<option value="{{ $status }}" @selected(($filters['status'] ?? '') === $status)>{{ ucfirst($status) }}</option>
					@endforeach
				</select>
			</div>
			<div class="flex flex-col gap-1">
				<label for="grn-filter-supplier" class="text-xs font-medium text-slate-600">Supplier</label>
				<select id="grn-filter-supplier" name="supplier_id" class="rounded-lg border border-slate-300 px-3 py-2 text-sm">
					<option value="">All suppliers</option>
					@foreach($filterSuppliers as $supplier)
						<option value="{{ $supplier->id }}" @selected(($filters['supplier_id'] ?? '') === (string) $supplier->id)>{{ $supplier->name }}</option>
					@endforeach
				</select>
			</div>
			<div class="flex flex-col gap-1">
				<label for="grn-filter-location" class="text-xs font-medium text-slate-600">Location</label>
				<select id="grn-filter-location" name="location_id" class="rounded-lg border border-slate-300 px-3 py-2 text-sm">
					<option value="">All locations</option>
					@foreach($filterLocations as $location)
						<option value="{{ $location->id }}" @selected(($filters['location_id'] ?? '') === (string) $location->id)>{{ $location->name }}</option>
					@endforeach
				</select>
			</div>
			<div class="flex flex-col gap-1">
				<label for="grn-filter-received-from" class="text-xs font-medium text-slate-600">Received from</label>
				<input id="grn-filter-received-from" type="date" name="date_from" value="{{ $filters['date_from'] ?? '' }}" class="rounded-lg border border-slate-300 px-3 py-2 text-sm">
			</div>
			<div class="flex flex-col gap-1">
				<label for="grn-filter-received-to" class="text-xs font-medium text-slate-600">Received to</label>
				<input id="grn-filter-received-to" type="date" name="date_to" value="{{ $filters['date_to'] ?? '' }}" class="rounded-lg border border-slate-300 px-3 py-2 text-sm">
			</div>
		</div>
		<div class="flex flex-col gap-4">
			<div class="flex flex-col gap-1">
				<label for="grn-filter-posted-from" class="text-xs font-medium text-slate-600">Posted from</label>
				<input id="grn-filter-posted-from" type="date" name="posted_from" value="{{ $filters['posted_from'] ?? '' }}" class="rounded-lg border border-slate-300 px-3 py-2 text-sm">
			</div>
			<div class="flex flex-col gap-1">
				<label for="grn-filter-posted-to" class="text-xs font-medium text-slate-600">Posted to</label>
				<input id="grn-filter-posted-to" type="date" name="posted_to" value="{{ $filters['posted_to'] ?? '' }}" class="rounded-lg border border-slate-300 px-3 py-2 text-sm">
			</div>
			<div class="flex flex-col gap-1 ">
				<label for="grn-filter-po" class="text-xs font-medium text-slate-600">Purchase order</label>
				<select id="grn-filter-po" name="purchase_order_id" class="rounded-lg border border-slate-300 px-3 py-2 text-sm">
					<option value="">Any purchase order</option>
					@foreach($filterPurchaseOrders as $po)
						<option value="{{ $po->id }}" @selected(($filters['purchase_order_id'] ?? '') === (string) $po->id)>{{ $po->po_number }}</option>
					@endforeach
				</select>
			</div>
			<div class="flex flex-col gap-1">
				<label for="grn-filter-po-link" class="text-xs font-medium text-slate-600">PO link</label>
				<select id="grn-filter-po-link" name="po_link" class="rounded-lg border border-slate-300 px-3 py-2 text-sm">
					<option value="" @selected(($filters['po_link'] ?? '') === '')>Any</option>
					<option value="with" @selected(($filters['po_link'] ?? '') === 'with')>Linked to PO</option>
					<option value="without" @selected(($filters['po_link'] ?? '') === 'without')>Standalone (no PO)</option>
				</select>
			</div>
			<div class="flex flex-col gap-1">
				<label for="grn-filter-sort" class="text-xs font-medium text-slate-600">Sort by</label>
				<select id="grn-filter-sort" name="sort" class="rounded-lg border border-slate-300 px-3 py-2 text-sm">
					<option value="received_at" @selected(($filters['sort'] ?? 'received_at') === 'received_at')>Received date</option>
					<option value="posted_at" @selected(($filters['sort'] ?? '') === 'posted_at')>Posted date</option>
					<option value="grn_number" @selected(($filters['sort'] ?? '') === 'grn_number')>GRN number</option>
					<option value="status" @selected(($filters['sort'] ?? '') === 'status')>Status</option>
					<option value="created_at" @selected(($filters['sort'] ?? '') === 'created_at')>Created</option>
				</select>
			</div>
			<div class="flex flex-col gap-1">
				<label for="grn-filter-dir" class="text-xs font-medium text-slate-600">Direction</label>
				<select id="grn-filter-dir" name="dir" class="rounded-lg border border-slate-300 px-3 py-2 text-sm">
					<option value="desc" @selected(($filters['dir'] ?? 'desc') === 'desc')>Descending</option>
					<option value="asc" @selected(($filters['dir'] ?? '') === 'asc')>Ascending</option>
				</select>
			</div>
		</div>
		<p class="text-xs text-slate-500">Received date range applies to <strong>received_at</strong>. Posted range applies when a GRN was posted. Search matches GRN, delivery note, supplier, linked PO number, line products, and serial numbers.</p>
		<x-index-filter-actions :reset-url="route('goods-receipts.index')" />
	</form>
</x-filter-drawer>

	<div class="mb-4 grid gap-3 sm:grid-cols-5">
		<x-status-card label="Total GRN">
			{{ $kpis['total'] }}
		</x-status-card>
		<x-status-card label="Draft">
			{{ $kpis['draft'] }}
		</x-status-card>
		<x-status-card label="Posted">
			{{ $kpis['posted'] }}
		</x-status-card>
		<x-status-card label="Today">
			{{ $kpis['today'] }}
		</x-status-card>
		<x-status-card label="Filtered">
			{{ $kpis['filtered'] }}
		</x-status-card>
	</div>

	<x-index-table-shell title="Goods receipts" :meta="$goodsReceipts->total().' records'">
		<table class="index-data-table min-w-[52rem] sm:min-w-full whitespace-nowrap">
				<thead class="bg-slate-50/80 text-left text-[11px] font-semibold uppercase tracking-wide text-slate-600">
					<tr>
						<th class="px-4 py-3 sm:px-6">GRN No</th>
						<th class="px-4 py-3">PO</th>
						<th class="px-4 py-3">Supplier</th>
						<th class="px-4 py-3">Location</th>
						<th class="px-4 py-3">Status</th>
						<th class="px-4 py-3">Received</th>
						<th class="px-4 py-3">Posted</th>
						<th class="px-4 py-3 text-right sm:px-6">Actions</th>
					</tr>
				</thead>
				<tbody class="divide-y divide-slate-100">
					@forelse($goodsReceipts as $grn)
						<tr class="transition hover:bg-slate-50/70">
							<td class="px-4 py-4 sm:px-6 font-mono text-xs font-semibold text-slate-700">{{ $grn->grn_number }}</td>
							<td class="px-4 py-4 text-slate-700">
								@if ($grn->purchaseOrder)
									<a href="{{ route('purchase-orders.show', $grn->purchaseOrder) }}" class="font-mono text-xs text-indigo-700 hover:underline">{{ $grn->purchaseOrder->po_number }}</a>
								@else
									<span class="text-slate-400">—</span>
								@endif
							</td>
							<td class="px-4 py-4 text-slate-800">{{ $grn->supplier?->name }}</td>
							<td class="px-4 py-4 text-slate-700">{{ $grn->location?->name }}</td>
							<td class="px-4 py-4">
								<span class="inline-flex rounded-full px-2.5 py-1 text-xs font-semibold {{ $grn->status === 'draft' ? 'bg-amber-100 text-amber-700' : 'bg-emerald-100 text-emerald-700' }}">{{ ucfirst($grn->status) }}</span>
							</td>
							<td class="px-4 py-4 text-slate-700">{{ optional($grn->received_at)->format('Y-m-d') }}</td>
							<td class="px-4 py-4 text-slate-600">{{ optional($grn->posted_at)?->format('Y-m-d H:i') ?? '—' }}</td>
							<td class="px-4 py-4 text-right sm:px-6">
								<x-index-row-actions-menu>
									<a href="{{ route('goods-receipts.show', $grn) }}">View</a>
									<a href="{{ route('goods-receipts.receipt', $grn) }}" target="_blank" rel="noopener noreferrer">Receipt</a>
									@if (($grn->serials_count ?? 0) > 0)
										<a href="{{ route('goods-receipts.labels', $grn) }}" target="_blank" rel="noopener noreferrer">Labels</a>
									@endif
									@can('delete', $grn)
										<form action="{{ route('goods-receipts.destroy', $grn) }}" method="post" data-confirm="Delete this goods receipt? Posted stock/journal effects will be rolled back first.">
											@csrf
											@method('DELETE')
											<button type="submit" class="index-actions-menu-item--danger">Delete</button>
										</form>
									@endcan
									@if($grn->status === 'draft')
										<a href="{{ route('goods-receipts.edit', $grn) }}">Edit</a>
										<form action="{{ route('goods-receipts.post', $grn) }}" method="post">
											@csrf
											<button type="submit">Post</button>
										</form>
									@endif
								</x-index-row-actions-menu>
							</td>
						</tr>
					@empty
						<tr>
							<td colspan="8" class="px-4 py-8 text-center text-slate-500">No GRNs found.</td>
						</tr>
					@endforelse
				</tbody>
			</table>
	</x-index-table-shell>
	<x-pagination-limit-selector />

	<div class="mt-4">{{ $goodsReceipts->links() }}</div>
@endsection
