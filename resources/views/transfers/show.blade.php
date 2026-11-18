@extends('layouts.app')
@section('title', 'Transfer '.$transfer->transfer_number)
@section('page-title', 'Transfer '.$transfer->transfer_number)
@section('content')
	<div class="mb-4 flex flex-wrap items-center justify-between gap-3">
		<a href="{{ route('transfers.index') }}" class="text-sm font-medium text-slate-600 hover:text-slate-900">← Back to transfers</a>
		<div class="flex flex-wrap items-center gap-2">
			@can('view', $transfer)
				<a href="{{ route('transfers.receipt', $transfer) }}" target="_blank" rel="noopener" class="rounded-lg border border-slate-300 px-3 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50">Print receipt</a>
			@endcan
			@if ($transfer->status === 'draft')
				@can('update', $transfer)
					<a href="{{ route('transfers.edit', $transfer) }}" class="rounded-lg border border-indigo-200 bg-indigo-50 px-3 py-2 text-sm font-medium text-indigo-800 hover:bg-indigo-100">Edit transfer</a>
				@endcan
			@endif
			@if ($transfer->status === 'draft')
				@can('approveSource', $transfer)
					<form action="{{ route('transfers.approve-source', $transfer) }}" method="post" class="inline">@csrf
						<button type="submit" class="rounded-lg border border-blue-200 bg-blue-50 px-3 py-2 text-sm font-medium text-blue-800 hover:bg-blue-100">Approve (sending branch)</button>
					</form>
				@endcan
				@can('approveDestination', $transfer)
					<form action="{{ route('transfers.approve-destination', $transfer) }}" method="post" class="inline">@csrf
						<button type="submit" class="rounded-lg border border-sky-200 bg-sky-50 px-3 py-2 text-sm font-medium text-sky-800 hover:bg-sky-100">Approve (receiving branch)</button>
					</form>
				@endcan
			@endif
			@if ($transfer->status === 'approved')
				@can('receive', $transfer)
					<form action="{{ route('transfers.receive-direct', $transfer) }}" method="post" class="inline">@csrf
						<button type="submit" class="rounded-lg border border-emerald-200 bg-emerald-50 px-3 py-2 text-sm font-medium text-emerald-800 hover:bg-emerald-100">Receive directly</button>
					</form>
				@endcan
				@can('issue', $transfer)
					<form action="{{ route('transfers.issue', $transfer) }}" method="post" class="inline">@csrf
						<button type="submit" class="rounded-lg border border-indigo-200 bg-indigo-50 px-3 py-2 text-sm font-medium text-indigo-800 hover:bg-indigo-100">Issue</button>
					</form>
				@endcan
			@endif
			@if ($transfer->status === 'issued')
				@can('receive', $transfer)
					<form action="{{ route('transfers.receive', $transfer) }}" method="post" class="inline">@csrf
						<button type="submit" class="rounded-lg border border-emerald-200 bg-emerald-50 px-3 py-2 text-sm font-medium text-emerald-800 hover:bg-emerald-100">Receive</button>
					</form>
				@endcan
			@endif
			@if (in_array($transfer->status, ['draft', 'approved'], true))
				@can('cancel', $transfer)
					<form action="{{ route('transfers.cancel', $transfer) }}" method="post" class="inline" data-confirm="Cancel this transfer? Planned serials will be released.">@csrf
						<button type="submit" class="rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-sm font-medium text-rose-800 hover:bg-rose-100">Cancel transfer</button>
					</form>
				@endcan
			@endif
			@can('delete', $transfer)
				<form action="{{ route('transfers.destroy', $transfer) }}" method="post" class="inline" data-confirm="Delete this transfer and revert quantities? Serialized units will move back to the source branch as in-stock, and this transfer&#39;s ledger entries will be removed.">@csrf
					@method('DELETE')
					<button type="submit" class="rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-sm font-medium text-rose-800 hover:bg-rose-100">Delete transfer</button>
				</form>
			@endcan
		</div>
	</div>

	@if (session('status'))
		<div class="mb-4 rounded-lg bg-emerald-50 p-3 text-sm text-emerald-700">{{ session('status') }}</div>
	@endif

	<div class="mb-6 grid gap-4 lg:grid-cols-2">
		<div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
			<h2 class="text-sm font-semibold text-slate-900">Summary</h2>
			<dl class="mt-3 grid gap-3 text-sm sm:grid-cols-2">
				<div>
					<dt class="text-xs font-medium uppercase tracking-wide text-slate-500">Transfer no.</dt>
					<dd class="mt-0.5 font-mono text-xs font-semibold text-slate-800">{{ $transfer->transfer_number }}</dd>
				</div>
				<div>
					<dt class="text-xs font-medium uppercase tracking-wide text-slate-500">Transfer date</dt>
					<dd class="mt-0.5 text-slate-800">{{ $transfer->requested_at?->toDateString() ?? '—' }}</dd>
				</div>
				<div>
					<dt class="text-xs font-medium uppercase tracking-wide text-slate-500">Status</dt>
					<dd class="mt-0.5">
						<span class="inline-flex rounded-full px-2 py-1 text-xs font-semibold
							@if ($transfer->status === 'draft') bg-amber-100 text-amber-800
							@elseif ($transfer->status === 'approved') bg-blue-100 text-blue-800
							@elseif ($transfer->status === 'issued') bg-indigo-100 text-indigo-800
							@elseif ($transfer->status === 'cancelled') bg-slate-200 text-slate-700
							@else bg-emerald-100 text-emerald-800
							@endif">{{ $transfer->displayStatus() }}</span>
					</dd>
				</div>
				<div class="sm:col-span-2">
					<dt class="text-xs font-medium uppercase tracking-wide text-slate-500">Source → destination</dt>
					<dd class="mt-0.5 text-slate-800">{{ $transfer->sourceLocation?->name }} → {{ $transfer->destinationLocation?->name }}</dd>
				</div>
				@if ($transfer->reason)
					<div class="sm:col-span-2">
						<dt class="text-xs font-medium uppercase tracking-wide text-slate-500">Reason</dt>
						<dd class="mt-0.5 whitespace-pre-wrap text-slate-700">{{ $transfer->reason }}</dd>
					</div>
				@endif
			</dl>
		</div>
		<div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
			<h2 class="text-sm font-semibold text-slate-900">Timeline</h2>
			<ul class="mt-3 space-y-2 text-sm text-slate-700">
				<li><span class="text-slate-500">Requested</span> {{ $transfer->requested_at?->toDateTimeString() ?? '—' }} @if ($transfer->requested_by)<span class="text-slate-500">·</span> {{ $actors->get($transfer->requested_by)?->name ?? 'User #'.$transfer->requested_by }}@endif</li>
				@if ($transfer->source_approved_at)
					<li><span class="text-slate-500">Sending branch approved</span> {{ $transfer->source_approved_at->toDateTimeString() }} @if ($transfer->source_approved_by)<span class="text-slate-500">·</span> {{ $actors->get($transfer->source_approved_by)?->name ?? 'User #'.$transfer->source_approved_by }}@endif</li>
				@endif
				@if ($transfer->destination_approved_at)
					<li><span class="text-slate-500">Receiving branch approved</span> {{ $transfer->destination_approved_at->toDateTimeString() }} @if ($transfer->destination_approved_by)<span class="text-slate-500">·</span> {{ $actors->get($transfer->destination_approved_by)?->name ?? 'User #'.$transfer->destination_approved_by }}@endif</li>
				@endif
				@if ($transfer->approved_at)
					<li><span class="text-slate-500">Fully approved</span> {{ $transfer->approved_at->toDateTimeString() }} @if ($transfer->approved_by)<span class="text-slate-500">·</span> {{ $actors->get($transfer->approved_by)?->name ?? 'User #'.$transfer->approved_by }}@endif</li>
				@endif
				@if ($transfer->issued_at)
					<li><span class="text-slate-500">Issued</span> {{ $transfer->issued_at->toDateTimeString() }} @if ($transfer->issued_by)<span class="text-slate-500">·</span> {{ $actors->get($transfer->issued_by)?->name ?? 'User #'.$transfer->issued_by }}@endif</li>
				@endif
				@if ($transfer->received_at)
					<li><span class="text-slate-500">Received</span> {{ $transfer->received_at->toDateTimeString() }} @if ($transfer->received_by)<span class="text-slate-500">·</span> {{ $actors->get($transfer->received_by)?->name ?? 'User #'.$transfer->received_by }}@endif</li>
				@endif
			</ul>
		</div>
	</div>

	<div class="rounded-2xl border border-slate-200 bg-white shadow-sm">
		<div class="border-b border-slate-100 px-5 py-4">
			<h2 class="text-sm font-semibold text-slate-900">Products (lines)</h2>
		</div>
		<div class="overflow-x-auto px-2 pb-4">
			<table class="min-w-full text-sm whitespace-nowrap">
				<thead class="text-left text-slate-600">
					<tr>
						<th class="px-3 py-2">Product</th>
						<th class="px-3 py-2 text-right">Requested</th>
						<th class="px-3 py-2 text-right">Issued</th>
						<th class="px-3 py-2 text-right">Received</th>
					</tr>
				</thead>
				<tbody>
					@forelse ($transfer->lines as $line)
						<tr class="border-t border-slate-100">
							<td class="px-3 py-2">{{ $line->product?->name ?? '—' }} @if ($line->product?->sku)<span class="text-xs text-slate-500">({{ $line->product->sku }})</span>@endif</td>
							<td class="px-3 py-2 text-right tabular-nums">{{ $line->qty_requested }}</td>
							<td class="px-3 py-2 text-right tabular-nums">{{ $line->qty_issued }}</td>
							<td class="px-3 py-2 text-right tabular-nums">{{ $line->qty_received }}</td>
						</tr>
					@empty
						<tr><td colspan="4" class="px-3 py-6 text-center text-slate-500">No lines.</td></tr>
					@endforelse
				</tbody>
			</table>
		</div>
	</div>

	<div class="mt-6 rounded-2xl border border-slate-200 bg-white shadow-sm">
		<div class="border-b border-slate-100 px-5 py-4">
			<h2 class="text-sm font-semibold text-slate-900">Serials</h2>
			<p class="mt-1 text-xs text-slate-500">Each row is one unit on this transfer.</p>
		</div>
		<div class="overflow-x-auto px-2 pb-4">
			<table class="min-w-full text-sm whitespace-nowrap">
				<thead class="text-left text-slate-600">
					<tr>
						<th class="px-3 py-2">Serial</th>
						<th class="px-3 py-2">Product</th>
						<th class="px-3 py-2">Status</th>
					</tr>
				</thead>
				<tbody>
					@forelse ($transfer->transferSerials as $row)
						<tr class="border-t border-slate-100">
							<td class="px-3 py-2 font-mono text-xs">{{ $row->serialItem?->serial_number ?: $row->serialItem?->manufacturer_serial ?: '—' }}</td>
							<td class="px-3 py-2">{{ $row->product?->name ?? '—' }}</td>
							<td class="px-3 py-2"><span class="rounded bg-slate-100 px-2 py-0.5 text-xs font-medium text-slate-700">{{ ucfirst($row->status) }}</span></td>
						</tr>
					@empty
						<tr><td colspan="3" class="px-3 py-6 text-center text-slate-500">No serials.</td></tr>
					@endforelse
				</tbody>
			</table>
		</div>
	</div>
@endsection
