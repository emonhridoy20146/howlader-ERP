@extends('layouts.app')
@section('title', 'Returns')
@section('page-title', 'Returns')
@php
	$returnUrlToken = 90008442001;
	$returnUpdateUrlTemplate = route('returns.update', ['return' => $returnUrlToken]);
@endphp
@section('content')
<div
	class="space-y-4"
	x-data="returnOrderSheet"
	data-update-token="{{ $returnUrlToken }}"
	data-update-url-template="{{ $returnUpdateUrlTemplate }}"
	data-lookup-customers-url="{{ route('returns.lookup.customers') }}"
	data-lookup-invoices-url="{{ route('returns.lookup.invoices') }}"
	data-default-return-date="{{ old('return_date', now()->toDateString()) }}"
	data-boot-open-create="{{ $returnSheetBoot['openCreate'] ? 'true' : 'false' }}"
	@if ($returnSheetBoot['editForm'])
		data-boot-edit='@json($returnSheetBoot['editForm'])'
	@endif
	@if ($createOldBoot)
		data-boot-create-old='@json($createOldBoot)'
	@endif
>
	<div class="mb-4 flex flex-col gap-2 sm:flex-row sm:flex-wrap sm:items-center sm:justify-end">
		@can('create', \App\Models\ReturnOrder::class)
			<button type="button" @click="openCreate()" class="inline-flex w-full justify-center rounded-lg bg-slate-900 px-4 py-2.5 text-sm font-medium text-white shadow-sm hover:bg-slate-800 sm:w-auto sm:py-2">New return</button>
		@endcan
	</div>

	@if (session('status'))
		<div class="mb-4 rounded-lg bg-emerald-50 p-3 text-sm text-emerald-700">{{ session('status') }}</div>
	@endif

	<x-filter-drawer>
<form method="get" action="{{ route('returns.index') }}" class="flex flex-col gap-4">
		<div class="flex flex-col gap-4">
			<input type="text" name="q" value="{{ $filters['q'] ?? '' }}" placeholder="Return number" class="rounded-lg border border-slate-300 px-3 py-2 text-sm">
			<input type="text" name="invoice_q" value="{{ $filters['invoice_q'] ?? '' }}" placeholder="Invoice number" class="rounded-lg border border-slate-300 px-3 py-2 text-sm">
			<input type="text" name="customer_q" value="{{ $filters['customer_q'] ?? '' }}" placeholder="Customer name" class="rounded-lg border border-slate-300 px-3 py-2 text-sm">
			<select name="status" class="rounded-lg border border-slate-300 px-3 py-2 text-sm">
				<option value="">All status</option>
				@foreach(['draft', 'approved', 'restocked', 'rejected', 'refunded'] as $status)
					<option value="{{ $status }}" @selected(($filters['status'] ?? '') === $status)>{{ ucfirst($status) }}</option>
				@endforeach
			</select>
			<input type="date" name="date_from" value="{{ $filters['date_from'] ?? '' }}" class="rounded-lg border border-slate-300 px-3 py-2 text-sm">
			<input type="date" name="date_to" value="{{ $filters['date_to'] ?? '' }}" class="rounded-lg border border-slate-300 px-3 py-2 text-sm">
		</div>
		<x-index-filter-actions :reset-url="route('returns.index')" />
	</form>
</x-filter-drawer>

	<div class="mb-4 grid gap-3 sm:grid-cols-5">
		<x-status-card label="Total">
			{{ $kpis['total'] }}
		</x-status-card>
		<x-status-card label="Draft">
			{{ $kpis['draft'] }}
		</x-status-card>
		<x-status-card label="Approved">
			{{ $kpis['approved'] }}
		</x-status-card>
		<x-status-card label="Restocked">
			{{ $kpis['restocked'] }}
		</x-status-card>
		<x-status-card label="Filtered">
			{{ $kpis['filtered'] }}
		</x-status-card>
	</div>

	<x-index-table-shell title="Returns" :meta="$returns->total().' in this list'">
		<table class="index-data-table min-w-[48rem] sm:min-w-full whitespace-nowrap">
			<thead class="bg-slate-50 border-b border-slate-200 text-left text-xs font-semibold uppercase tracking-wider text-slate-700">
				<tr>
					<th class="px-4 py-3">Return No</th>
					<th class="px-4 py-3">Invoice</th>
					<th class="px-4 py-3">Customer</th>
					<th class="px-4 py-3">Branch</th>
					<th class="px-4 py-3">Date</th>
					<th class="px-4 py-3">Status</th>
					<th class="px-4 py-3 text-right">Actions</th>
				</tr>
			</thead>
			<tbody>
				@forelse($returns as $return)
					<tr class="border-b border-slate-100 transition-colors hover:bg-slate-50/70 last:border-0">
						<td class="px-4 py-3 font-mono">{{ $return->return_number }}</td>
						<td class="px-4 py-3 font-mono text-xs">{{ $return->salesInvoice?->invoice_number ?? '—' }}</td>
						<td class="px-4 py-3">{{ $return->customer?->name ?? '—' }}</td>
						<td class="px-4 py-3">{{ $return->branch?->name ?? '—' }}</td>
						<td class="px-4 py-3">{{ $return->return_date?->toDateString() }}</td>
						<td class="px-4 py-3">{{ ucfirst($return->status) }}</td>
						<td class="px-4 py-3 text-right">
							<x-index-row-actions-menu>
								<a href="{{ route('returns.show', $return) }}">View</a>
								@can('view', $return)
									<a href="{{ route('returns.receipt', $return) }}" target="_blank" rel="noopener">Receipt</a>
								@endcan
								@if ($return->status === 'draft')
									@can('update', $return)
										<a href="{{ route('returns.index', ['open' => $return->id]) }}">Edit</a>
									@endcan
								@endif
							</x-index-row-actions-menu>
						</td>
					</tr>
				@empty
					<tr><td colspan="7" class="px-4 py-12 text-center text-sm font-medium text-slate-500 bg-slate-50/50">No returns found.</td></tr>
				@endforelse
			</tbody>
		</table>
	</x-index-table-shell>
	<x-pagination-limit-selector />
	<div class="mt-4">{{ $returns->links() }}</div>

	{{-- Create / edit offcanvas --}}
	<div
		x-show="sheet !== null"
		x-cloak
		class="fixed inset-0 z-50 flex justify-end"
		role="dialog"
		aria-modal="true"
		aria-labelledby="return-sheet-title"
	>
		<div class="absolute inset-0 bg-slate-900/40" @click="closeSheet()" aria-hidden="true"></div>
		<div
			class="relative flex h-full w-full max-w-lg flex-col bg-white shadow-2xl ring-1 ring-slate-900/10"
			@click.stop
		>
			<div class="flex items-start justify-between gap-3 border-b border-slate-200 px-4 py-4 sm:px-5">
				<div>
					<h2 id="return-sheet-title" class="text-base font-semibold text-slate-900" x-text="sheet === 'create' ? 'New return' : 'Edit return'"></h2>
					<p class="mt-1 text-xs text-slate-500" x-show="sheet === 'edit'">Update draft return details. Search customer and invoice; preview shows lines, models, serials, and total.</p>
					<p class="mt-1 text-xs text-slate-500" x-show="sheet === 'create'">Search by customer or invoice (number, product, model, serial). Pick an invoice to auto-fill customer and branch.</p>
				</div>
				<button type="button" class="rounded-lg p-2 text-slate-500 hover:bg-slate-100 hover:text-slate-800" @click="closeSheet()" aria-label="Close">
					<svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" /></svg>
				</button>
			</div>

			<div class="min-h-0 flex-1 overflow-y-auto px-4 py-4 sm:px-5">
				<form
					x-show="sheet === 'create'"
					action="{{ route('returns.store') }}"
					method="post"
					class="space-y-4"
				>
					@csrf
					@if ($errors->any() && ! old('return_order_id'))
						<div class="rounded-lg border border-rose-200 bg-rose-50 p-3 text-sm text-rose-800">
							<p class="font-medium">Please fix the errors below.</p>
							<ul class="mt-2 list-inside list-disc text-xs">
								@foreach ($errors->all() as $err)
									<li>{{ $err }}</li>
								@endforeach
							</ul>
						</div>
					@endif
					@include('returns._sheet-pick', ['idSuffix' => 'create'])
					<div>
						<label for="create_return_date" class="text-xs font-medium text-slate-600">Return date</label>
						<input id="create_return_date" type="date" name="return_date" x-model="pick.return_date" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm" required>
						@error('return_date')<p class="mt-1 text-xs text-rose-600">{{ $message }}</p>@enderror
					</div>
					<div>
						<label for="create_reason" class="text-xs font-medium text-slate-600">Reason</label>
						<textarea id="create_reason" name="reason" rows="3" x-model="pick.reason" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"></textarea>
						@error('reason')<p class="mt-1 text-xs text-rose-600">{{ $message }}</p>@enderror
					</div>
					<div class="flex justify-end gap-2 border-t border-slate-100 pt-4">
						<button type="button" class="rounded-lg border border-slate-300 px-4 py-2 text-sm text-slate-700 hover:bg-slate-50" @click="closeSheet()">Cancel</button>
						<button type="submit" class="rounded-lg bg-slate-900 px-4 py-2 text-sm text-white">Save return</button>
					</div>
				</form>

				<form
					x-show="sheet === 'edit'"
					method="post"
					class="space-y-4"
					x-bind:action="updateAction()"
				>
					@csrf
					@method('PUT')
					<input type="hidden" name="return_order_id" x-bind:value="editingId">
					@if ($errors->any() && old('return_order_id'))
						<div class="rounded-lg border border-rose-200 bg-rose-50 p-3 text-sm text-rose-800">
							<p class="font-medium">Please fix the errors below.</p>
							<ul class="mt-2 list-inside list-disc text-xs">
								@foreach ($errors->all() as $err)
									<li>{{ $err }}</li>
								@endforeach
							</ul>
						</div>
					@endif
					@include('returns._sheet-pick', ['idSuffix' => 'edit'])
					<div>
						<label for="edit_return_date" class="text-xs font-medium text-slate-600">Return date</label>
						<input id="edit_return_date" type="date" name="return_date" x-model="pick.return_date" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm" required>
						@error('return_date')<p class="mt-1 text-xs text-rose-600">{{ $message }}</p>@enderror
					</div>
					<div>
						<label for="edit_reason" class="text-xs font-medium text-slate-600">Reason</label>
						<textarea id="edit_reason" name="reason" rows="3" x-model="pick.reason" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"></textarea>
						@error('reason')<p class="mt-1 text-xs text-rose-600">{{ $message }}</p>@enderror
					</div>
					<div class="flex justify-end gap-2 border-t border-slate-100 pt-4">
						<button type="button" class="rounded-lg border border-slate-300 px-4 py-2 text-sm text-slate-700 hover:bg-slate-50" @click="closeSheet()">Cancel</button>
						<button type="submit" class="rounded-lg bg-slate-900 px-4 py-2 text-sm text-white">Update return</button>
					</div>
				</form>
			</div>
		</div>
	</div>
</div>
@endsection
