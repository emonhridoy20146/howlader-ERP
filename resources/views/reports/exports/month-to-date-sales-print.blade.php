@extends('reports.exports.print-layout')

@section('report-table')
	@if(! empty($summaryByType) && $summaryByType->isNotEmpty())
		@php
			$typeWriteoffTotal = (float) $summaryByType->sum('writeoff');
			$showWriteoffColumn = $typeWriteoffTotal > 0.009;
		@endphp
		<div style="margin:0 0 14px;padding:10px 12px;border:1px solid #cbd5e1;background:#f8fafc;border-radius:6px;font-size:9px;line-height:1.45;color:#0f172a;">
			<strong style="font-size:10px;display:block;margin-bottom:6px;text-transform:uppercase;letter-spacing:0.04em;">Collection summary by type</strong>
			<table class="data" style="margin:0;">
				<thead>
					<tr>
						<th class="num">#</th>
						<th>Type</th>
						<th class="num">Invoices</th>
						<th class="num">Total</th>
						<th class="num">Paid (cash)</th>
						<th class="num">Settlement discount</th>
						@if ($showWriteoffColumn)
							<th class="num">Write-offs</th>
						@endif
						<th class="num">Cash in period</th>
						<th class="num">Discount in period</th>
						<th class="num">Due</th>
					</tr>
				</thead>
				<tbody>
					@foreach ($summaryByType as $row)
						<tr>
							<td class="num">{{ $loop->iteration }}</td>
							<td>{{ $row['label'] }}</td>
							<td class="num">{{ number_format((int) $row['invoices']) }}</td>
							<td class="num">{{ number_format((float) $row['grand_total'], 2) }}</td>
							<td class="num">{{ number_format((float) ($row['amount_paid'] ?? 0), 2) }}</td>
							<td class="num">{{ (float) ($row['settlement_discount'] ?? 0) > 0 ? number_format((float) $row['settlement_discount'], 2) : '—' }}</td>
							@if ($showWriteoffColumn)
								<td class="num">{{ (float) ($row['writeoff'] ?? 0) > 0 ? number_format((float) $row['writeoff'], 2) : '—' }}</td>
							@endif
							<td class="num">{{ number_format((float) ($row['period_cash'] ?? $row['period_paid'] ?? 0), 2) }}</td>
							<td class="num">{{ (float) ($row['period_discount'] ?? 0) > 0 ? number_format((float) $row['period_discount'], 2) : '—' }}</td>
							<td class="num">{{ number_format((float) $row['amount_due'], 2) }}</td>
						</tr>
					@endforeach
				</tbody>
				<tfoot>
					<tr>
						<td></td>
						<td><strong>Total</strong></td>
						<td class="num"><strong>{{ number_format((int) $summaryByType->sum('invoices')) }}</strong></td>
						<td class="num"><strong>{{ number_format((float) $summaryByType->sum('grand_total'), 2) }}</strong></td>
						<td class="num"><strong>{{ number_format((float) $summaryByType->sum('amount_paid'), 2) }}</strong></td>
						<td class="num"><strong>{{ (float) $summaryByType->sum('settlement_discount') > 0 ? number_format((float) $summaryByType->sum('settlement_discount'), 2) : '—' }}</strong></td>
						@if ($showWriteoffColumn)
							<td class="num"><strong>{{ $typeWriteoffTotal > 0 ? number_format($typeWriteoffTotal, 2) : '—' }}</strong></td>
						@endif
						<td class="num"><strong>{{ number_format((float) $summaryByType->sum('period_cash'), 2) }}</strong></td>
						<td class="num"><strong>{{ (float) $summaryByType->sum('period_discount') > 0 ? number_format((float) $summaryByType->sum('period_discount'), 2) : '—' }}</strong></td>
						<td class="num"><strong>{{ number_format((float) $summaryByType->sum('amount_due'), 2) }}</strong></td>
					</tr>
				</tfoot>
			</table>
		</div>
	@endif

	@php
		$branchSummaries = collect($summaryByBranch ?? [])->keyBy(fn (array $row): string => (string) ($row['branch_id'] ?? 0));
		$groupedRows = collect($rows ?? [])->groupBy(fn ($invoice) => (string) ($invoice->branch_id ?? 0));
		$branchIds = $groupedRows->keys()->merge($branchSummaries->keys())->unique()->values();
	@endphp

	@forelse ($branchIds as $branchId)
		@php
			$branchRows = $groupedRows->get($branchId, collect());
			$branchSummary = $branchSummaries->get($branchId);
			if ($branchSummary === null && $branchRows->isNotEmpty()) {
				$first = $branchRows->first();
				$branchSummary = [
					'branch_id' => (int) $branchId,
					'branch_name' => $first->branch?->name ?? 'Unassigned',
					'branch_code' => $first->branch?->code ?? '',
					'invoices' => $branchRows->pluck('id')->unique()->count(),
					'grand_total' => $branchRows->sum(fn ($invoice) => (float) $invoice->grand_total),
					'amount_paid' => $branchRows->sum(fn ($invoice) => (float) ($invoice->cash_at_transaction ?? $invoice->cash_paid_total ?? max(0, (float) $invoice->amount_paid - (float) ($invoice->settlement_discount_total ?? 0)))),
					'settlement_discount' => $branchRows->sum(fn ($invoice) => (float) ($invoice->discount_at_transaction ?? $invoice->settlement_discount_total ?? 0)),
					'period_cash' => $branchRows->sum(fn ($invoice) => (float) ($invoice->period_cash ?? $invoice->period_paid ?? 0)),
					'period_discount' => $branchRows->sum(fn ($invoice) => (float) ($invoice->period_discount ?? 0)),
					'period_paid' => $branchRows->sum(fn ($invoice) => (float) ($invoice->period_paid ?? 0)),
					'amount_due' => $branchRows->sum(fn ($invoice) => (float) ($invoice->due_at_transaction ?? $invoice->amount_due)),
				];
			}
		@endphp
		@if ($branchRows->isNotEmpty() && $branchSummary !== null)
			<div style="margin:0 0 16px;border:1px solid #e2e8f0;border-radius:8px;overflow:hidden;">
				<div style="padding:10px 12px;background:#f8fafc;border-bottom:1px solid #e2e8f0;">
					<strong style="font-size:11px;color:#0f172a;">{{ $branchSummary['branch_name'] }}</strong>
					@if(! empty($branchSummary['branch_code']))
						<span style="font-size:9px;color:#64748b;"> · {{ $branchSummary['branch_code'] }}</span>
					@endif
					<span style="font-size:9px;color:#64748b;"> · {{ number_format((int) $branchSummary['invoices']) }} invoices</span>
					<div style="margin-top:6px;font-size:9px;color:#334155;">
						Total {{ number_format((float) $branchSummary['grand_total'], 2) }}
						· Paid (cash) {{ number_format((float) $branchSummary['amount_paid'], 2) }}
						@if ((float) ($branchSummary['settlement_discount'] ?? 0) > 0)
							· Settlement discount {{ number_format((float) $branchSummary['settlement_discount'], 2) }}
						@endif
						· Cash in period {{ number_format((float) ($branchSummary['period_cash'] ?? $branchSummary['period_paid'] ?? 0), 2) }}
						@if ((float) ($branchSummary['period_discount'] ?? 0) > 0)
							· Discount in period {{ number_format((float) $branchSummary['period_discount'], 2) }}
						@endif
						· Due {{ number_format((float) $branchSummary['amount_due'], 2) }}
					</div>
				</div>
				<table class="data" style="margin:0;">
					<thead>
						<tr>
							<th class="num">#</th>
							<th>Invoice</th>
							<th>Payment date</th>
							<th>Customer</th>
							<th>Type</th>
							<th>Invoice status</th>
							<th class="num">Total</th>
							<th class="num">Paid (cash)</th>
							<th class="num">Settlement discount</th>
							<th class="num">Cash in period</th>
							<th class="num">Discount in period</th>
							<th class="num">Due after payment</th>
							<th>Payment status</th>
						</tr>
					</thead>
					<tbody>
						@foreach ($branchRows as $invoice)
							@php
								$rowCashPaid = (float) ($invoice->cash_at_transaction ?? $invoice->cash_paid_total ?? max(0, (float) $invoice->amount_paid - (float) ($invoice->settlement_discount_total ?? 0)));
								$rowDiscount = (float) ($invoice->discount_at_transaction ?? $invoice->settlement_discount_total ?? 0);
								$rowPeriodCash = (float) ($invoice->period_cash ?? $invoice->period_paid ?? 0);
								$rowPeriodDiscount = (float) ($invoice->period_discount ?? 0);
							@endphp
							<tr>
								<td class="num">{{ $loop->iteration }}</td>
								<td>{{ $invoice->invoice_number }}</td>
								<td>{{ $invoice->period_payment_dates ?: '—' }}</td>
								<td>{{ $invoice->customer?->name }} @if($invoice->customer?->phone)<br><span style="font-size:8px;">{{ $invoice->customer->phone }}</span>@endif</td>
								<td>{{ ucfirst($invoice->sale_type) }}</td>
								<td>{{ ucfirst(str_replace('_', ' ', $invoice->status)) }}</td>
								<td class="num">{{ number_format((float) $invoice->grand_total, 2) }}</td>
								<td class="num">{{ number_format($rowCashPaid, 2) }}</td>
								<td class="num">{{ $rowDiscount > 0.009 ? number_format($rowDiscount, 2) : '—' }}</td>
								<td class="num">{{ number_format($rowPeriodCash, 2) }}</td>
								<td class="num">{{ $rowPeriodDiscount > 0.009 ? number_format($rowPeriodDiscount, 2) : '—' }}</td>
								<td class="num">{{ number_format((float) ($invoice->due_at_transaction ?? $invoice->amount_due), 2) }}</td>
								<td>{{ ucfirst(str_replace('_', ' ', $invoice->payment_status)) }}</td>
							</tr>
						@endforeach
					</tbody>
				</table>
			</div>
		@endif
	@empty
		<p style="text-align:center;color:#64748b;font-size:10px;">No payments in this period match the current filters.</p>
	@endforelse
@endsection
