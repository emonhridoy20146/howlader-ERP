@extends('layouts.app')
@section('title', 'Transfer ledger')
@section('page-title', 'Transfer ledger')
@section('content')
<div class="space-y-4">
	<p class="flex justify-end">
		<a href="{{ route('reports.index', ['report' => 'transfer-ledger']) }}" class="text-sm font-medium text-emerald-600 hover:text-emerald-700">← Back to reports</a>
	</p>
	<p class="text-sm text-slate-600">
		Stock ledger entries posted when transfers are issued (out from source) or received (in at destination). Each serialized unit creates one ledger line.
	</p>

	<x-filter-drawer>
		<form method="get" action="{{ route('reports.transfer-ledger') }}" class="flex flex-col gap-4">
			@include('reports.partials.transfer-ledger-filter-fields', [
				'filters' => $filters,
				'filterLocations' => $filterLocations,
			])
			<div class="flex flex-wrap items-center gap-2 border-t border-slate-100 pt-3">
				<span class="text-xs font-medium text-slate-500">Export</span>
				@php
					$exportQuery = request()->only(['q', 'date_from', 'date_to', 'source_location_id', 'destination_location_id', 'location_id', 'movement_type']);
				@endphp
				<a target="_blank" rel="noopener noreferrer" href="{{ route('reports.print', array_merge(['slug' => 'transfer-ledger'], $exportQuery)) }}" class="rounded-lg border border-slate-300 bg-white px-3 py-1.5 text-xs font-medium text-slate-800 hover:bg-slate-50">Print</a>
				<a href="{{ route('reports.export', array_merge(['slug' => 'transfer-ledger', 'format' => 'xlsx'], $exportQuery)) }}" class="rounded-lg border border-slate-300 bg-white px-3 py-1.5 text-xs font-medium text-slate-800 hover:bg-slate-50">Excel</a>
			</div>
			<x-index-filter-actions :reset-url="route('reports.transfer-ledger')" />
		</form>
	</x-filter-drawer>

	<div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
		<x-status-card label="Ledger entries">{{ number_format($kpis['ledger_entries']) }}</x-status-card>
		<x-status-card label="Transfers">{{ number_format($kpis['transfers']) }}</x-status-card>
		<x-status-card label="Units issued">{{ number_format($kpis['units_issued']) }}</x-status-card>
		<x-status-card label="Units received">{{ number_format($kpis['units_received']) }}</x-status-card>
	</div>

	<div class="overflow-x-auto overflow-y-hidden rounded-2xl bg-white shadow-sm ring-1 ring-slate-200">
		<table class="min-w-full text-sm whitespace-nowrap">
			<thead class="border-b border-slate-200 bg-slate-50 text-left text-xs font-semibold uppercase tracking-wider text-slate-700">
				<tr>
					<th class="px-4 py-3">Date/time</th>
					<th class="px-4 py-3">Transfer</th>
					<th class="px-4 py-3">Movement</th>
					<th class="px-4 py-3">Source → destination</th>
					<th class="px-4 py-3">Ledger branch</th>
					<th class="px-4 py-3">Product</th>
					<th class="px-4 py-3">Serial</th>
					<th class="px-4 py-3 text-right">Qty</th>
					<th class="px-4 py-3">Actor</th>
				</tr>
			</thead>
			<tbody>
				@forelse ($rows as $row)
					@php
						$delta = (int) $row->quantity_delta;
						$movementLabel = match ($row->movement_type) {
							'transfer_issue' => 'Issue (out)',
							'transfer_receive' => 'Receive (in)',
							default => \Illuminate\Support\Str::of($row->movement_type)->replace('_', ' ')->title(),
						};
					@endphp
					<tr class="border-b border-slate-100 transition-colors hover:bg-slate-50/70 last:border-0">
						<td class="px-4 py-3 tabular-nums text-slate-800">{{ \Illuminate\Support\Carbon::parse($row->event_time)->format('Y-m-d H:i') }}</td>
						<td class="px-4 py-3">
							<a href="{{ route('transfers.show', $row->stock_transfer_id) }}" class="font-mono text-xs font-semibold text-indigo-700 hover:text-indigo-900">{{ $row->transfer_number }}</a>
							<p class="text-xs text-slate-500">{{ ucfirst(str_replace('_', ' ', $row->transfer_status)) }}</p>
						</td>
						<td class="px-4 py-3">
							<span class="inline-flex rounded-full px-2 py-0.5 text-xs font-semibold {{ $row->movement_type === 'transfer_issue' ? 'bg-amber-100 text-amber-800' : 'bg-emerald-100 text-emerald-800' }}">{{ $movementLabel }}</span>
						</td>
						<td class="px-4 py-3 text-slate-700">{{ $row->source_location_name }} → {{ $row->destination_location_name }}</td>
						<td class="px-4 py-3 text-slate-700">{{ $row->ledger_location_name }}</td>
						<td class="px-4 py-3">
							<p class="font-medium text-slate-800">{{ $row->product_sku }}</p>
							<p class="text-xs text-slate-500">{{ $row->product_name }}</p>
						</td>
						<td class="px-4 py-3 font-mono text-xs text-slate-700">{{ $row->serial_number ?: '—' }}</td>
						<td class="px-4 py-3 text-right font-semibold tabular-nums {{ $delta >= 0 ? 'text-emerald-700' : 'text-rose-700' }}">{{ $delta >= 0 ? '+' : '' }}{{ number_format($delta) }}</td>
						<td class="px-4 py-3 text-slate-600">{{ $row->actor_name ?: '—' }}</td>
					</tr>
				@empty
					<tr>
						<td colspan="9" class="px-4 py-8 text-center text-slate-500">No transfer ledger entries found for current filters.</td>
					</tr>
				@endforelse
			</tbody>
		</table>
	</div>

	<x-pagination-limit-selector />
	<div>{{ $rows->links() }}</div>
</div>
@endsection
