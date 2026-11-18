@extends('reports.exports.print-layout')

@section('report-table')
	<style>
		.text-right { text-align: right; }
		.font-medium { font-weight: bold; }
		.invoice-detail-block { margin-top: 8px; margin-bottom: 16px; }
		.invoice-detail-title { font-size: 9px; font-weight: bold; text-transform: uppercase; color: #475569; margin: 0 0 4px; }
	</style>
	<table class="data">
		<thead>
			<tr>
				<th class="num">#</th>
				<th>Referrer</th>
				<th class="num">Invoices generated</th>
				<th class="num">Total revenue</th>
				<th class="num">Amount collected</th>
				<th class="num">Amount due</th>
			</tr>
		</thead>
		<tbody>
			@foreach ($rows as $row)
				@php
					$refKey = $row->representative_id === null ? 'none' : (string) $row->representative_id;
					$invoices = ($invoiceDetailsByReferrer ?? collect())->get($refKey, collect());
					$referrerLabel = $row->representative_id
						? \App\Models\Employee::labelFromParts($row->representative_code, $row->representative_first_name, $row->representative_last_name)
						: 'No referrer';
				@endphp
				<tr>
					<td class="num">{{ $loop->iteration }}</td>
					<td class="font-medium">{{ $referrerLabel }}</td>
					<td class="num">{{ Number::format($row->total_invoices) }}</td>
					<td class="num">{{ Number::format($row->total_revenue, 2) }}</td>
					<td class="num">{{ Number::format($row->total_collected, 2) }}</td>
					<td class="num">{{ Number::format($row->total_due, 2) }}</td>
				</tr>
				@if ($invoices->isNotEmpty())
					<tr>
						<td colspan="6" style="padding: 0 0 12px;">
							<div class="invoice-detail-block">
								<p class="invoice-detail-title">Invoices for {{ $referrerLabel }}</p>
								@include('reports.partials.representative-wise-sales-invoice-rows', [
									'invoices' => $invoices,
									'referrerId' => $row->representative_id,
									'compact' => true,
								])
							</div>
						</td>
					</tr>
				@endif
			@endforeach
			@if ($rows->isEmpty())
				<tr>
					<td colspan="6" style="text-align: center; padding: 20px;">No records found.</td>
				</tr>
			@endif
		</tbody>
	</table>
@endsection
