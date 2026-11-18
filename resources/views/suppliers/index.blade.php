@extends('layouts.app')
@section('title', 'Suppliers')
@section('page-title', 'Suppliers')
@section('content')
@php
	$drawerMode = request('drawer');
	$isEdit = $drawerMode === 'edit' && !is_null($editingSupplier);
	$isDrawerOpen = in_array($drawerMode, ['create', 'edit'], true) || $errors->any();
	$supplier = $isEdit ? $editingSupplier : new \App\Models\Supplier();
@endphp

<div x-data="{ drawerOpen: @js($isDrawerOpen) }">
	<div class="mb-4 flex flex-col gap-2 sm:flex-row sm:flex-wrap sm:items-center sm:justify-end">
		<a href="{{ route('suppliers.index', ['drawer' => 'create']) }}" class="inline-flex justify-center rounded-lg bg-slate-900 px-4 py-2.5 text-sm font-medium text-white shadow-sm hover:bg-slate-800 sm:py-2">New Supplier</a>
	</div>

	@if (session('status'))
		<div class="mb-4 rounded-lg bg-emerald-50 p-3 text-sm text-emerald-700">{{ session('status') }}</div>
	@endif

	<x-filter-drawer>
<form method="get" action="{{ route('suppliers.index') }}" class="flex flex-col gap-4">
		<div class="flex flex-col gap-4">
			<input type="text" name="q" value="{{ $filters['q'] ?? '' }}" placeholder="Search supplier, contact, phone, email" class="rounded-lg border border-slate-300 px-3 py-2 text-sm ">
			<select name="is_active" class="rounded-lg border border-slate-300 px-3 py-2 text-sm">
				<option value="">All status</option>
				<option value="1" @selected(($filters['is_active'] ?? '') === '1')>Active</option>
				<option value="0" @selected(($filters['is_active'] ?? '') === '0')>Inactive</option>
			</select>
		</div>
		<x-index-filter-actions :reset-url="route('suppliers.index')" />
	</form>
</x-filter-drawer>

	<div class="mb-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-5">
		<x-status-card label="Total">
			{{ $kpis['total'] }}
		</x-status-card>
		<x-status-card label="Active">
			{{ $kpis['active'] }}
		</x-status-card>
		<x-status-card label="Inactive">
			{{ $kpis['inactive'] }}
		</x-status-card>
		<x-status-card label="Balance due (AP)">
			<span class="text-amber-900" title="Unpaid purchase orders (total − payments), all suppliers">{{ number_format($kpis['purchase_order_due_total'] ?? 0, 2) }}</span>
		</x-status-card>
		<x-status-card label="Filtered">
			{{ $kpis['filtered'] }}
		</x-status-card>
	</div>

	<x-index-table-shell title="Suppliers" :meta="$suppliers->total().' in this list'">
		<table class="index-data-table min-w-[48rem] sm:min-w-full whitespace-nowrap">
			<thead class="bg-slate-50 border-b border-slate-200 text-left text-xs font-semibold uppercase tracking-wider text-slate-700">
				<tr>
					<th class="px-4 py-3">Name</th>
					<th class="px-4 py-3">Phone</th>
					<th class="px-4 py-3">Email</th>
					<th class="px-4 py-3 text-right">Ledger balance</th>
					<th class="px-4 py-3 text-right">PO balance due</th>
					<th class="px-4 py-3">Status</th>
					<th class="px-4 py-3 text-right">Actions</th>
				</tr>
			</thead>
			<tbody>
				@forelse ($suppliers as $rowSupplier)
					<tr class="border-b border-slate-100 transition-colors hover:bg-slate-50/70 last:border-0">
						<td class="px-4 py-3 font-medium">{{ $rowSupplier->name }}</td>
						<td class="px-4 py-3">
							@php $phones = $rowSupplier->normalizedPhoneNumbers(); @endphp
							@if(count($phones))
								{{ implode(', ', $phones) }}
							@else
								-
							@endif
						</td>
						<td class="px-4 py-3">{{ $rowSupplier->email ?: '-' }}</td>
						<td class="px-4 py-3 text-right tabular-nums text-slate-700" title="Stored supplier balance (if your workflows update this field)">
							{{ number_format((float) $rowSupplier->outstanding_balance, 2) }}
						</td>
						<td class="px-4 py-3 text-right tabular-nums">
							@php $poDue = (float) ($purchaseOrderDueBySupplier[$rowSupplier->id] ?? 0); @endphp
							@if($poDue > 0.009)
								<a href="{{ route('purchase-orders.index', ['supplier_id' => $rowSupplier->id]) }}" class="font-medium text-amber-800 underline decoration-amber-300 hover:text-amber-950">{{ number_format($poDue, 2) }}</a>
							@else
								<span class="text-slate-400">—</span>
							@endif
						</td>
						<td class="px-4 py-3">{{ $rowSupplier->is_active ? 'Active' : 'Inactive' }}</td>
						<td class="px-4 py-3 text-right">
							<x-index-row-actions-menu>
								<a href="{{ route('suppliers.show', $rowSupplier) }}">View</a>
								<a href="{{ route('suppliers.index', ['drawer' => 'edit', 'edit' => $rowSupplier->id]) }}">Edit</a>
							</x-index-row-actions-menu>
						</td>
					</tr>
				@empty
					<tr><td colspan="7" class="px-4 py-12 text-center text-sm font-medium text-slate-500 bg-slate-50/50">No suppliers found.</td></tr>
				@endforelse
			</tbody>
		</table>
	</x-index-table-shell>
	<x-pagination-limit-selector />

	<div class="mt-4">{{ $suppliers->links() }}</div>

	<div x-cloak x-show="drawerOpen" class="fixed inset-0 z-40">
		<a href="{{ route('suppliers.index') }}" class="absolute inset-0 bg-slate-900/40" aria-label="Close"></a>
		<div class="absolute inset-y-0 right-0 w-full max-w-xl overflow-y-auto bg-white shadow-2xl">
			<div class="sticky top-0 flex items-center justify-between border-b border-slate-200 bg-white px-6 py-4">
				<h2 class="text-lg font-semibold text-slate-900">{{ $isEdit ? 'Edit Supplier' : 'New Supplier' }}</h2>
				<a href="{{ route('suppliers.index') }}" class="text-sm text-slate-600">Close</a>
			</div>
			<div class="p-6">
				<form action="{{ $isEdit ? route('suppliers.update', $supplier) : route('suppliers.store') }}" method="post" class="space-y-4">
					@if ($isEdit)
						@method('PUT')
					@endif
					@include('suppliers._form')
				</form>
			</div>
		</div>
	</div>
</div>
@endsection
