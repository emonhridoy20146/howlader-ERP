@extends('reports.exports.print-layout')

@section('report-table')
	@if ($customer)
		<p style="margin-bottom: 10px; font-size: 10px; color: #374151;">
			<strong>{{ $customer->name }}</strong> ({{ $customer->customer_code }}){{ $customer->phone ? ' · '.$customer->phone : '' }}
		</p>
	@endif
	<table class="data">
		<thead>
			<tr>
				<th class="num">#</th>
				<th>Date</th>
				<th>Event</th>
				<th>Reference</th>
				<th class="num">Debit</th>
				<th class="num">Credit</th>
				<th class="num">Balance</th>
			</tr>
		</thead>
		<tbody>
			@forelse ($rows as $entry)
				<tr>
					<td class="num">{{ $loop->iteration }}</td>
					<td>{{ $entry->event_time?->format('Y-m-d H:i') ?? '' }}</td>
					<td>{{ ucfirst(str_replace('_', ' ', $entry->event_type)) }}</td>
					<td>{{ \Illuminate\Support\Str::afterLast($entry->reference_type, '\\') }} #{{ $entry->reference_id }}</td>
					<td class="num">{{ $entry->debit_amount > 0 ? number_format($entry->debit_amount, 2) : '' }}</td>
					<td class="num">{{ $entry->credit_amount > 0 ? number_format($entry->credit_amount, 2) : '' }}</td>
					<td class="num">{{ number_format($entry->running_balance, 2) }}</td>
				</tr>
			@empty
				<tr><td colspan="7" style="text-align: center; padding: 20px;">No entries found.</td></tr>
			@endforelse
		</tbody>
		@if ($rows->count() > 0)
			<tfoot>
				<tr>
					<th colspan="4" class="num" style="text-align: right; font-weight: 700;">Totals</th>
					<td class="num" style="font-weight: 700;">{{ number_format((float) $rows->sum('debit_amount'), 2) }}</td>
					<td class="num" style="font-weight: 700;">{{ number_format((float) $rows->sum('credit_amount'), 2) }}</td>
					<td class="num" style="font-weight: 700;">{{ $rows->isNotEmpty() ? number_format((float) $rows->last()->running_balance, 2) : '0.00' }}</td>
				</tr>
			</tfoot>
		@endif
	</table>
@endsection
