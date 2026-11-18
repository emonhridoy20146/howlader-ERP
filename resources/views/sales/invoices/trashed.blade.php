@extends('layouts.app')
@section('title', 'Deleted Invoices')
@section('page-title', 'Deleted Invoices')
@section('content')
<div class="mb-4 flex flex-col gap-2 sm:flex-row sm:flex-wrap sm:items-center sm:justify-end">
	<a href="{{ route('sales.invoices.index') }}" class="inline-flex justify-center rounded-lg border border-slate-300 bg-white px-4 py-2.5 text-sm font-medium text-slate-700 shadow-sm hover:bg-slate-50 sm:py-2">Back to invoices</a>
</div>

@if (session('status'))
	<div class="mb-4 rounded-lg bg-emerald-50 p-3 text-sm text-emerald-700">{{ session('status') }}</div>
@endif
@if ($errors->any())
	<div class="mb-4 rounded-lg border border-rose-200 bg-rose-50 p-3 text-sm text-rose-700">
		<ul class="list-disc pl-5">
			@foreach ($errors->all() as $error)
				<li>{{ $error }}</li>
			@endforeach
		</ul>
	</div>
@endif

<x-filter-drawer>
<form method="get" action="{{ route('sales.invoices.trashed') }}" class="flex flex-col gap-4">
	<div class="flex flex-col gap-4">
		<input type="text" name="q" value="{{ $filters['q'] ?? '' }}" placeholder="Search invoice / customer / phone" class="rounded-lg border border-slate-300 px-3 py-2 text-sm ">
	</div>
	<x-index-filter-actions :reset-url="route('sales.invoices.trashed')" />
</form>
</x-filter-drawer>

<x-index-table-shell title="Deleted invoices" :meta="$invoices->total().' in the trash'">
	<table class="index-data-table min-w-[48rem] sm:min-w-full whitespace-nowrap">
		<thead class="bg-slate-50 border-b border-slate-200 text-left text-xs font-semibold uppercase tracking-wider text-slate-700">
			<tr>
				<th class="px-4 py-3">Invoice</th>
				<th class="px-4 py-3">Date</th>
				<th class="px-4 py-3">Customer</th>
				<th class="px-4 py-3">Type</th>
				<th class="px-4 py-3">Total</th>
				<th class="px-4 py-3">Invoice Status</th>
				<th class="px-4 py-3">Deleted</th>
				<th class="px-4 py-3 text-right">Actions</th>
			</tr>
		</thead>
		<tbody>
			@forelse($invoices as $invoice)
				<tr class="border-b border-slate-100 transition-colors hover:bg-slate-50/70 last:border-0">
					<td class="px-4 py-3 font-mono text-xs">{{ $invoice->invoice_number ?? 'Pending payment' }}</td>
					<td class="px-4 py-3">{{ $invoice->invoice_date?->toDateString() }}</td>
					<td class="px-4 py-3">{{ $invoice->customer?->name }}<div class="text-xs text-slate-500">{{ $invoice->customer?->phone }}</div></td>
					<td class="px-4 py-3">{{ ucfirst($invoice->sale_type) }}</td>
					<td class="px-4 py-3">{{ number_format($invoice->grand_total, 2) }}</td>
					<td class="px-4 py-3">{{ ucfirst(str_replace('_', ' ', $invoice->status)) }}</td>
					<td class="px-4 py-3">
						{{ $invoice->deleted_at?->toDateString() }}
						@if ($invoice->deleted_at)
							<div class="text-xs text-slate-500">{{ $invoice->deleted_at->format('H:i') }}</div>
						@endif
					</td>
					<td class="px-4 py-3 text-right">
						<x-index-row-actions-menu>
							@can('restore', $invoice)
								<form action="{{ route('sales.invoices.restore', $invoice) }}" method="post" data-confirm="Restore this invoice back into the active list?">
									@csrf
									<button type="submit" class="index-actions-menu-item">Restore</button>
								</form>
							@endcan
							@can('forceDelete', $invoice)
								<form action="{{ route('sales.invoices.force-delete', $invoice) }}" method="post" data-confirm="Permanently delete this invoice? This cannot be undone.">
									@csrf
									@method('DELETE')
									<button type="submit" class="index-actions-menu-item--danger">Delete permanently</button>
								</form>
							@endcan
						</x-index-row-actions-menu>
					</td>
				</tr>
			@empty
				<tr>
					<td colspan="8" class="px-4 py-12 text-center text-sm font-medium text-slate-500 bg-slate-50/50">The trash is empty.</td>
				</tr>
			@endforelse
		</tbody>
	</table>
</x-index-table-shell>
	<x-pagination-limit-selector />

<div class="mt-4">{{ $invoices->links() }}</div>
@endsection
