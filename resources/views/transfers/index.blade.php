@extends('layouts.app')
@section('title', 'Stock Transfers')
@section('page-title', 'Stock Transfers')
@section('content')
	<div class="mb-4 flex flex-col gap-2 sm:flex-row sm:flex-wrap sm:items-center sm:justify-end">
		<a href="{{ route('transfers.create') }}" class="inline-flex justify-center rounded-lg bg-slate-900 px-4 py-2.5 text-sm font-medium text-white shadow-sm hover:bg-slate-800 sm:py-2">New Transfer</a>
	</div>

	@if (session('status'))
		<div class="mb-4 rounded-lg bg-emerald-50 p-3 text-sm text-emerald-700">{{ session('status') }}</div>
	@endif

	<div class="mb-4 rounded-lg border border-sky-200 bg-sky-50 p-3 text-xs text-sky-700">
		Flow: Approve transfer, then choose either Receive Directly or Issue.
	</div>

	<x-filter-drawer>
<form method="get" action="{{ route('transfers.index') }}" class="flex flex-col gap-4">
		<div class="flex flex-col gap-4">
			<input type="text" name="q" value="{{ $filters['q'] ?? '' }}" placeholder="Search transfer number" class="rounded-lg border border-slate-300 px-3 py-2 text-sm">
			<select name="status" class="rounded-lg border border-slate-300 px-3 py-2 text-sm">
				<option value="">All status</option>
				@foreach(['draft', 'approved', 'issued', 'received', 'cancelled'] as $status)
					<option value="{{ $status }}" @selected(($filters['status'] ?? '') === $status)>{{ ucfirst($status) }}</option>
				@endforeach
			</select>
			<select name="source_location_id" class="rounded-lg border border-slate-300 px-3 py-2 text-sm">
				<option value="">All sources</option>
				@foreach($filterLocations as $location)
					<option value="{{ $location->id }}" @selected(($filters['source_location_id'] ?? '') === (string) $location->id)>{{ $location->name }}</option>
				@endforeach
			</select>
			<select name="destination_location_id" class="rounded-lg border border-slate-300 px-3 py-2 text-sm">
				<option value="">All destinations</option>
				@foreach($filterLocations as $location)
					<option value="{{ $location->id }}" @selected(($filters['destination_location_id'] ?? '') === (string) $location->id)>{{ $location->name }}</option>
				@endforeach
			</select>
		</div>
		<x-index-filter-actions :reset-url="route('transfers.index')" />
	</form>
</x-filter-drawer>

	<div class="mb-4 grid gap-3 sm:grid-cols-5">
		<x-status-card label="Total">
			{{ $kpis['total'] }}
		</x-status-card>
		<x-status-card label="Approved">
			{{ $kpis['approved'] }}
		</x-status-card>
		<x-status-card label="Issued">
			{{ $kpis['issued'] }}
		</x-status-card>
		<x-status-card label="Received">
			{{ $kpis['received'] }}
		</x-status-card>
		<x-status-card label="Filtered">
			{{ $kpis['filtered'] }}
		</x-status-card>
	</div>

	<x-index-table-shell title="Stock transfers" :meta="$transfers->total().' in this list'">
		<table class="index-data-table min-w-[52rem] sm:min-w-full whitespace-nowrap">
			<thead class="bg-slate-50 border-b border-slate-200 text-left text-xs font-semibold uppercase tracking-wider text-slate-700">
				<tr>
					<th class="px-4 py-3">Transfer No</th>
					<th class="px-4 py-3">Transfer Date</th>
					<th class="px-4 py-3">Source</th>
					<th class="px-4 py-3">Destination</th>
					<th class="px-4 py-3">Items</th>
					<th class="px-4 py-3">Progress</th>
					<th class="px-4 py-3">Status</th>
					<th class="px-4 py-3 text-right">Actions</th>
				</tr>
			</thead>
			<tbody>
				@forelse($transfers as $transfer)
					<tr class="border-t border-slate-100">
						<td class="px-4 py-3 font-mono text-xs font-semibold text-slate-700">
							<a href="{{ route('transfers.show', $transfer) }}" class="text-sky-800 hover:underline">{{ $transfer->transfer_number }}</a>
						</td>
						<td class="px-4 py-3 text-slate-700">{{ $transfer->requested_at?->toDateString() ?? '—' }}</td>
						<td class="px-4 py-3 text-slate-700">{{ $transfer->sourceLocation?->name }}</td>
						<td class="px-4 py-3 text-slate-700">{{ $transfer->destinationLocation?->name }}</td>
						<td class="px-4 py-3 text-slate-700">{{ (int) ($transfer->lines_sum_qty_requested ?? 0) }}</td>
						<td class="px-4 py-3 text-xs text-slate-600">
							@if ($transfer->status === 'received')
								Completed
							@elseif ($transfer->status === 'issued')
								Issued, awaiting receive
							@elseif ($transfer->status === 'approved')
								Approved, choose direct receive or issue
							@elseif ($transfer->status === 'cancelled')
								Cancelled
							@else
								Draft
							@endif
						</td>
						<td class="px-4 py-3">
							<span class="inline-flex rounded-full px-2 py-1 text-xs font-semibold {{ $transfer->status === 'draft' ? 'bg-amber-100 text-amber-700' : ($transfer->status === 'approved' ? 'bg-blue-100 text-blue-700' : ($transfer->status === 'issued' ? 'bg-indigo-100 text-indigo-700' : ($transfer->status === 'cancelled' ? 'bg-slate-200 text-slate-700' : 'bg-emerald-100 text-emerald-700'))) }}">{{ $transfer->displayStatus() }}</span>
						</td>
						<td class="px-4 py-3 text-right">
							<x-index-row-actions-menu>
								<a href="{{ route('transfers.show', $transfer) }}">View</a>
								@if($transfer->status === 'draft')
									@can('update', $transfer)
										<a href="{{ route('transfers.edit', $transfer) }}">Edit</a>
									@endcan
								@endif
								@can('view', $transfer)
									<a href="{{ route('transfers.receipt', $transfer) }}" target="_blank" rel="noopener">Receipt</a>
								@endcan
								@if($transfer->status === 'draft')
									@can('approveSource', $transfer)
										<form action="{{ route('transfers.approve-source', $transfer) }}" method="post">@csrf
											<button type="submit">Approve (sending)</button>
										</form>
									@endcan
									@can('approveDestination', $transfer)
										<form action="{{ route('transfers.approve-destination', $transfer) }}" method="post">@csrf
											<button type="submit">Approve (receiving)</button>
										</form>
									@endcan
								@endif

								@if($transfer->status === 'approved')
									<form action="{{ route('transfers.receive-direct', $transfer) }}" method="post">@csrf
										<button type="submit">Receive directly</button>
									</form>

									<form action="{{ route('transfers.issue', $transfer) }}" method="post">@csrf
										<button type="submit">Issue</button>
									</form>
								@endif

								@if($transfer->status === 'issued')
									<form action="{{ route('transfers.receive', $transfer) }}" method="post">@csrf
										<button type="submit">Receive</button>
									</form>
								@endif

								@if (in_array($transfer->status, ['draft', 'approved'], true))
									@can('cancel', $transfer)
										<form action="{{ route('transfers.cancel', $transfer) }}" method="post" data-confirm="Cancel this transfer?">@csrf
											<button type="submit" class="index-actions-menu-item--danger">Cancel</button>
										</form>
									@endcan
								@endif
								@can('delete', $transfer)
									<form action="{{ route('transfers.destroy', $transfer) }}" method="post" data-confirm="Delete this transfer and revert quantities? Serialized units will move back to the source branch as in-stock, and this transfer&#39;s ledger entries will be removed.">@csrf
										@method('DELETE')
										<button type="submit" class="index-actions-menu-item--danger">Delete</button>
									</form>
								@endcan
							</x-index-row-actions-menu>
						</td>
					</tr>
				@empty
					<tr>
						<td colspan="8" class="px-4 py-12 text-center text-sm font-medium text-slate-500 bg-slate-50/50">No transfers found.</td>
					</tr>
				@endforelse
			</tbody>
		</table>
	</x-index-table-shell>
	<x-pagination-limit-selector />

	<div class="mt-4">{{ $transfers->links() }}</div>
@endsection
