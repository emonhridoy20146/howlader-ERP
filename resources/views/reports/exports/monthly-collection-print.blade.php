@extends('reports.exports.print-layout')

@push('print-extra-styles')
	<style>
		.print-mc-summary {
			margin: 0 0 12px;
			padding: 8px 10px;
			border: 1px solid #94a3b8;
			background: #f8fafc;
			font-size: 8px;
			line-height: 1.45;
			color: #1f2937;
		}
		.print-mc-summary .print-summary-title {
			font-size: 9px;
			font-weight: 700;
			text-transform: uppercase;
			letter-spacing: 0.06em;
			color: #374151;
			margin: 0 0 6px;
		}
		.print-mc-summary .print-summary-note { margin: 0 0 6px; color: #4b5563; }
		table.print-mc-summary-grid {
			width: 100%;
			border-collapse: collapse;
			margin: 0;
		}
		table.print-mc-summary-grid td {
			width: 25%;
			padding: 4px 6px 4px 0;
			border: none;
			vertical-align: top;
		}
		table.print-mc-summary-grid .k {
			display: block;
			color: #64748b;
			font-size: 7px;
			text-transform: uppercase;
			letter-spacing: 0.04em;
			font-weight: 600;
		}
		table.print-mc-summary-grid .v {
			display: block;
			margin-top: 1px;
			font-size: 10px;
			font-weight: 700;
			font-variant-numeric: tabular-nums;
			color: #0f172a;
		}
		table.print-mc-summary-grid .m {
			display: block;
			margin-top: 1px;
			font-size: 7px;
			color: #64748b;
		}
		table.print-mc-summary-grid .v.due { color: #9f1239; }
		table.print-mc-summary-grid .v.paid { color: #047857; }
		table.print-mc-summary-grid .v.inst { color: #0369a1; }
		table.print-mc-summary-grid .v.credit { color: #4338ca; }
		table.print-mc-subtotal {
			margin-top: 0 !important;
		}
		table.print-mc-subtotal td {
			border: none;
			border-top: 1px solid #cbd5e1;
			background: #f8fafc;
			padding: 4px 4px;
			font-size: 8px;
			color: #334155;
		}
		table.print-mc-subtotal td:first-child {
			color: #0f172a;
		}
		table.print-mc-subtotal td strong {
			font-weight: 700;
		}
		table.print-report-totals th,
		table.print-report-totals td {
			font-size: 8px;
		}
		table.print-report-totals tr.grand-total td {
			font-weight: 700;
			border-top: 2px solid #94a3b8;
		}
	</style>
@endpush

@section('report-table')
@php
	$collectionBranchLabel = $collectionBranchLabel ?? null;
	$summary = $summary ?? [];

	$invoiceProductsWithPrices = static function ($invoice): string {
		if (! $invoice) {
			return '—';
		}

		if (! $invoice->relationLoaded('lines') || $invoice->lines->isEmpty()) {
			return '—';
		}

		return $invoice->lines->map(static function ($line): string {
			$label = $line->product?->name ?? ('Product #'.(string) $line->product_id);
			$qty = number_format((float) $line->qty, 0);
			$unit = number_format((float) $line->unit_price, 0);
			$defaultPrice = $line->product ? number_format((float) $line->product->default_sale_price, 0) : null;

			$text = $label.' × '.$qty.' @ '.$unit;

			return $defaultPrice !== null ? $text.' (def. '.$defaultPrice.')' : $text;
		})->implode('; ');
	};

	$invoiceGrandTotalFormatted = static function ($invoice): string {
		if (! $invoice) {
			return '—';
		}

		return number_format((float) $invoice->grand_total, 0);
	};

	$guarantorCell = static function ($invoice): string {
		if (! $invoice instanceof \App\Models\SalesInvoice) {
			return '—';
		}

		return $invoice->collectionGuarantorDisplayText();
	};

	$formatSchedDueDate = static function ($date): string {
		if (! $date instanceof \DateTimeInterface) {
			return '—';
		}

		return $date->format('d-m-Y');
	};

	$periodPaymentsByInvoiceId = $periodPaymentsByInvoiceId ?? collect();
	$periodPaymentFor = static function (?int $invoiceId) use ($periodPaymentsByInvoiceId): float {
		if (! $invoiceId) {
			return 0.0;
		}

		return (float) ($periodPaymentsByInvoiceId[$invoiceId] ?? 0);
	};

	$collectorsByInvoiceId = $collectorsByInvoiceId ?? collect();
	$collectorsFor = static fn (?int $invoiceId): string => $invoiceId ? (string) ($collectorsByInvoiceId[$invoiceId] ?? '—') : '—';

	$sortRowsByBranchThenCollectionDate = static function ($rows) {
		return $rows->sortBy(function ($row) {
			if ($row instanceof \App\Models\SalesInvoice) {
				$collectionDate = $row->sale_type === 'installment'
					? ($row->finalInstallmentDueDate()?->toDateString() ?? '')
					: ($row->collectionDueDate()?->toDateString() ?? '');

				return ($row->branch?->name ?? '').'|'.$collectionDate.'|'.sprintf('%010d', $row->id);
			}

			$collectionDate = $row->due_date?->toDateString() ?? '';

			return ($row->invoice?->branch?->name ?? '').'|'.$collectionDate.'|'.sprintf('%010d', (int) ($row->invoice?->id ?? 0));
		})->values();
	};

	$overdueCreditRows = $overdueCreditRows ?? collect();
	$maturedOverdueInstallmentRows = $maturedOverdueInstallmentRows ?? collect();

	$thisMonthInstallmentRows = $sortRowsByBranchThenCollectionDate($thisMonthInstallmentRows);
	$thisMonthCreditRows = $sortRowsByBranchThenCollectionDate($thisMonthCreditRows);
	$overdueInstallmentRows = $sortRowsByBranchThenCollectionDate($overdueInstallmentRows);
	$overdueCreditRows = $sortRowsByBranchThenCollectionDate($overdueCreditRows);
	$maturedOverdueInstallmentRows = $sortRowsByBranchThenCollectionDate($maturedOverdueInstallmentRows);

	$instGroups = $thisMonthInstallmentRows->groupBy(static fn ($row) => $row->invoice?->branch_id ?? 0);
	$creditGroups = $thisMonthCreditRows->groupBy(static fn ($row) => $row->branch_id ?? 0);
	$overdueGroups = $overdueInstallmentRows->groupBy(static fn ($row) => $row->invoice?->branch_id ?? 0);
	$overdueCreditGroups = $overdueCreditRows->groupBy(static fn ($row) => $row->branch_id ?? 0);
	$maturedOverdueGroups = $maturedOverdueInstallmentRows->groupBy(static fn ($row) => $row->branch_id ?? 0);

	$allBranchIds = $instGroups->keys()
		->merge($creditGroups->keys())
		->merge($overdueGroups->keys())
		->merge($overdueCreditGroups->keys())
		->merge($maturedOverdueGroups->keys())
		->map(fn ($id) => (int) $id)
		->unique()
		->values();

	$branchSections = $allBranchIds->map(function (int $branchId) use ($instGroups, $creditGroups, $overdueGroups, $overdueCreditGroups, $maturedOverdueGroups): array {
		$thisMonthInstallments = $instGroups->get($branchId, collect());
		$thisMonthCredits = $creditGroups->get($branchId, collect());
		$overdueInstallments = $overdueGroups->get($branchId, collect());
		$overdueCredits = $overdueCreditGroups->get($branchId, collect());
		$maturedOverdueInstallments = $maturedOverdueGroups->get($branchId, collect());

		$name = $thisMonthInstallments->first()?->invoice?->branch?->name
			?? $thisMonthCredits->first()?->branch?->name
			?? $overdueInstallments->first()?->invoice?->branch?->name
			?? $overdueCredits->first()?->branch?->name
			?? $maturedOverdueInstallments->first()?->branch?->name
			?? 'Unassigned';

		return [
			'id' => $branchId,
			'name' => $name,
			'this_month_installments' => $thisMonthInstallments,
			'this_month_credits' => $thisMonthCredits,
			'overdue_installments' => $overdueInstallments,
			'overdue_credits' => $overdueCredits,
			'matured_overdue_installments' => $maturedOverdueInstallments,
		];
	})->sortBy(fn (array $section): string => mb_strtolower($section['name']))->values();

	$instIdx = 0;
	$credIdx = 0;
	$ovIdx = 0;
	$ovCredIdx = 0;
	$maturedIdx = 0;

	$instDue = (float) ($summary['installment_due'] ?? $thisMonthInstallmentRows->sum('due_amount'));
	$instPaid = (float) ($summary['installment_paid'] ?? $thisMonthInstallmentRows->sum('paid_amount'));
	$instRemaining = (float) ($summary['installment_remaining'] ?? $thisMonthInstallmentRows->sum('remaining'));
	$instInvoices = (int) ($summary['installment_invoices'] ?? $thisMonthInstallmentRows->count());
	$instCustomers = (int) ($summary['installment_customers'] ?? 0);

	$creditGrand = (float) ($summary['credit_grand'] ?? $thisMonthCreditRows->sum('grand_total'));
	$creditPaid = (float) ($summary['credit_paid'] ?? $thisMonthCreditRows->sum('amount_paid'));
	$creditDue = (float) ($summary['credit_due'] ?? $thisMonthCreditRows->sum('amount_due'));
	$creditInvoices = (int) ($summary['credit_invoices'] ?? $thisMonthCreditRows->count());
	$creditCustomers = (int) ($summary['credit_customers'] ?? 0);

	$overdueDue = (float) ($summary['overdue_installment_due'] ?? $overdueInstallmentRows->sum('due_amount'));
	$overduePaid = (float) ($summary['overdue_installment_paid'] ?? $overdueInstallmentRows->sum('paid_amount'));
	$overdueRemaining = (float) ($summary['overdue_installment_remaining'] ?? $overdueInstallmentRows->sum('remaining'));
	$overdueInstInvoices = (int) ($summary['overdue_installment_invoices'] ?? $overdueInstallmentRows->count());
	$overdueInstCustomers = (int) ($summary['overdue_installment_customers'] ?? 0);

	$overdueCreditGrand = (float) ($summary['overdue_credit_grand'] ?? $overdueCreditRows->sum('grand_total'));
	$overdueCreditPaid = (float) ($summary['overdue_credit_paid'] ?? $overdueCreditRows->sum('amount_paid'));
	$overdueCreditDue = (float) ($summary['overdue_credit_due'] ?? $overdueCreditRows->sum('amount_due'));
	$overdueCreditInvoices = (int) ($summary['overdue_credit_invoices'] ?? $overdueCreditRows->count());
	$overdueCreditCustomers = (int) ($summary['overdue_credit_customers'] ?? 0);

	$maturedOverdueGrand = (float) ($summary['matured_overdue_grand'] ?? $maturedOverdueInstallmentRows->sum('grand_total'));
	$maturedOverduePaid = (float) ($summary['matured_overdue_paid'] ?? $maturedOverdueInstallmentRows->sum('amount_paid'));
	$maturedOverdueDue = (float) ($summary['matured_overdue_due'] ?? $maturedOverdueInstallmentRows->sum('amount_due'));
	$maturedOverdueInvoices = (int) ($summary['matured_overdue_invoices'] ?? $maturedOverdueInstallmentRows->count());
	$maturedOverdueCustomers = (int) ($summary['matured_overdue_customers'] ?? 0);

	$totalDue = (float) ($summary['total_due'] ?? ($instRemaining + $creditDue + $overdueRemaining + $overdueCreditDue + $maturedOverdueDue));
	$totalPaid = (float) ($summary['total_paid'] ?? ($instPaid + $creditPaid + $overduePaid + $overdueCreditPaid + $maturedOverduePaid));
	$periodPaid = (float) ($summary['period_paid'] ?? 0);
	$totalCustomers = (int) ($summary['total_customers'] ?? 0);
	$totalInvoices = (int) ($summary['total_invoices'] ?? 0);

	$branchHeaderText = $collectionBranchLabel
		? 'Branch filter: '.$collectionBranchLabel
		: 'Branch: all branches in scope (grouped below)';
@endphp

	<div style="margin:0 0 12px;padding:8px 10px;background:#f1f5f9;border:1px solid #cbd5e1;font-size:9px;line-height:1.4;">
		<strong style="font-size:10px;">Collection scope</strong><br>
		{{ $branchHeaderText }} · Grouped by branch (installments and credits for each branch, then next branch)
	</div>

	<div class="print-mc-summary">
		<p class="print-summary-title">Collection summary</p>
		<p class="print-summary-note">
			{{ number_format($totalInvoices) }} invoices · {{ number_format($totalCustomers) }} customers · totals use the same filters as this report
		</p>
		<table class="print-mc-summary-grid">
			<tr>
				<td>
					<span class="k">Total installment (due)</span>
					<span class="v inst">{{ number_format($instDue, 2) }}</span>
					<span class="m">{{ number_format($instInvoices) }} inv · {{ number_format($instCustomers) }} cust</span>
				</td>
				<td>
					<span class="k">Installment paid</span>
					<span class="v paid">{{ number_format($instPaid, 2) }}</span>
					<span class="m">Remaining {{ number_format($instRemaining, 2) }}</span>
				</td>
				<td>
					<span class="k">Credit &amp; default due</span>
					<span class="v credit">{{ number_format($creditDue, 2) }}</span>
					<span class="m">{{ number_format($creditInvoices) }} inv · {{ number_format($creditCustomers) }} cust</span>
				</td>
				<td>
					<span class="k">Credit &amp; default paid</span>
					<span class="v paid">{{ number_format($creditPaid, 2) }}</span>
					<span class="m">Grand {{ number_format($creditGrand, 2) }}</span>
				</td>
			</tr>
			<tr>
				<td>
					<span class="k">Overdue installment rem.</span>
					<span class="v due">{{ number_format($overdueRemaining, 2) }}</span>
					<span class="m">{{ number_format($overdueInstInvoices) }} inv · {{ number_format($overdueInstCustomers) }} cust</span>
				</td>
				<td>
					<span class="k">Overdue credit due</span>
					<span class="v due">{{ number_format($overdueCreditDue, 2) }}</span>
					<span class="m">{{ number_format($overdueCreditInvoices) }} inv · {{ number_format($overdueCreditCustomers) }} cust</span>
				</td>
				<td>
					<span class="k">Overdue (matured plans) due</span>
					<span class="v due">{{ number_format($maturedOverdueDue, 2) }}</span>
					<span class="m">{{ number_format($maturedOverdueInvoices) }} inv · {{ number_format($maturedOverdueCustomers) }} cust</span>
				</td>
				<td>
					<span class="k">Overdue (matured plans) paid</span>
					<span class="v paid">{{ number_format($maturedOverduePaid, 2) }}</span>
					<span class="m">Grand {{ number_format($maturedOverdueGrand, 2) }}</span>
				</td>
			</tr>
			<tr>
				<td>
					<span class="k">Total due (all)</span>
					<span class="v due">{{ number_format($totalDue, 2) }}</span>
					<span class="m">Installment + credit + overdue + matured</span>
				</td>
				<td>
					<span class="k">Paid in selected period</span>
					<span class="v paid">{{ number_format($periodPaid, 2) }}</span>
					<span class="m">Lifetime paid {{ number_format($totalPaid, 2) }}</span>
				</td>
				<td></td>
				<td></td>
			</tr>
		</table>
	</div>

	@forelse ($branchSections as $branchSection)
		@php
			$bName = $branchSection['name'];
			$branchInstRows = $branchSection['this_month_installments'];
			$branchCreditRows = $branchSection['this_month_credits'];
			$branchOverdueInstRows = $branchSection['overdue_installments'];
			$branchOverdueCreditRows = $branchSection['overdue_credits'];
			$branchMaturedOverdueRows = $branchSection['matured_overdue_installments'];
		@endphp

		<div style="margin:0 0 20px;padding:10px 12px;border:2px solid #64748b;background:#f8fafc;page-break-inside:avoid;">
			<h2 style="font-size:13px;margin:0 0 10px;font-weight:700;color:#0f172a;">Branch: {{ $bName }}</h2>

			@if ($branchInstRows->isNotEmpty())
			<h3 style="font-size:11px;margin:0 0 6px;">Installments due this period</h3>
			<table class="data" style="margin-bottom:14px;">
				<thead>
					<tr>
						<th class="num">#</th>
						<th>Guarantor</th>
						<th>Customer</th>
						<th>Invoice</th>
						<th>Sale date</th>
						<th>Products</th>
						<th class="num">Invoice total</th>
						<th class="num">Total paid</th>
						<th class="num">This month payment</th>
						<th>Collected by</th>
						<th class="num">Total due</th>
						<th class="num">Inst #</th>
						<th>Sched. due</th>
						<th class="num">Sched. due amt</th>
						<th class="num">Sched. paid</th>
						<th class="num">Sched. rem.</th>
					</tr>
				</thead>
				<tbody>
					@foreach ($branchInstRows as $row)
						@php
							$instIdx++;
							$invoice = $row->invoice;
							$customer = $invoice?->customer;
						@endphp
						<tr>
							<td class="num">{{ $instIdx }}</td>
							<td style="font-size:7px;line-height:1.35;max-width:120px;">{{ $guarantorCell($invoice) }}</td>
							<td style="font-size:7px;line-height:1.35;max-width:140px;">
								<strong>{{ $customer?->name ?? '—' }}</strong>
								@if ($customer?->phone)<br>{{ $customer->phone }}@endif
								@if ($customer && $customer->address !== null && trim((string) $customer->address) !== '')
									<br>{{ $customer->address }}
								@endif
							</td>
							<td>{{ $invoice?->invoice_number ?? '—' }}</td>
							<td>{{ $invoice?->invoice_date?->toDateString() ?? '—' }}</td>
							<td style="font-size:7px;line-height:1.35;max-width:160px;">{{ $invoiceProductsWithPrices($invoice) }}</td>
							<td class="num">{{ $invoiceGrandTotalFormatted($invoice) }}</td>
							<td class="num">{{ $invoice ? number_format((float) $invoice->amount_paid, 0) : '—' }}</td>
							<td class="num">{{ $invoice ? number_format($periodPaymentFor((int) $invoice->id), 0) : '—' }}</td>
							<td style="font-size:7px;">{{ $collectorsFor((int) $invoice->id) }}</td>
							<td class="num">{{ $invoice ? number_format((float) $invoice->amount_due, 0) : '—' }}</td>
							<td class="num">{{ $row->installment_nos }}</td>
							<td>{{ $formatSchedDueDate($row->due_date) }}</td>
							<td class="num">{{ number_format((float) $row->due_amount, 0) }}</td>
							<td class="num">{{ number_format((float) $row->paid_amount, 0) }}</td>
							<td class="num">{{ number_format((float) $row->remaining, 0) }}</td>
						</tr>
					@endforeach
				</tbody>
			</table>
			<table class="data print-mc-subtotal" style="margin:-8px 0 14px;">
				<tbody>
					<tr>
						<td><strong>Installments subtotal — {{ $bName }}</strong></td>
						<td class="num">Due {{ number_format((float) $branchInstRows->sum('due_amount'), 0) }}</td>
						<td class="num">Paid {{ number_format((float) $branchInstRows->sum('paid_amount'), 0) }}</td>
						<td class="num">This month {{ number_format($branchInstRows->sum(fn ($row) => $periodPaymentFor((int) ($row->invoice?->id ?? 0))), 0) }}</td>
						<td class="num"><strong>Remaining {{ number_format((float) $branchInstRows->sum('remaining'), 0) }}</strong></td>
					</tr>
				</tbody>
			</table>
			@endif

			@if ($branchCreditRows->isNotEmpty())
			<h3 style="font-size:11px;margin:0 0 6px;">Credit &amp; default dues this period</h3>
			<table class="data" style="margin-bottom:14px;">
				<thead>
					<tr>
						<th class="num">#</th>
						<th>Guarantor</th>
						<th>Customer</th>
						<th>Invoice</th>
						<th>Sale date</th>
						<th>Products</th>
						<th>Pay status</th>
						<th class="num">Grand total</th>
						<th class="num">Total paid</th>
						<th class="num">This month payment</th>
						<th>Collected by</th>
						<th class="num">Total due</th>
					</tr>
				</thead>
				<tbody>
					@foreach ($branchCreditRows as $row)
						@php
							$credIdx++;
							$customer = $row->customer;
						@endphp
						<tr>
							<td class="num">{{ $credIdx }}</td>
							<td style="font-size:7px;line-height:1.35;max-width:120px;">{{ $guarantorCell($row) }}</td>
							<td style="font-size:7px;line-height:1.35;max-width:140px;">
								<strong>{{ $customer?->name ?? '—' }}</strong>
								@if ($customer?->phone)<br>{{ $customer->phone }}@endif
								@if ($customer && $customer->address !== null && trim((string) $customer->address) !== '')
									<br>{{ $customer->address }}
								@endif
							</td>
							<td>{{ $row->invoice_number }}</td>
							<td>{{ $row->invoice_date?->toDateString() }}</td>
							<td style="font-size:7px;line-height:1.35;max-width:160px;">{{ $invoiceProductsWithPrices($row) }}</td>
							<td>{{ $row->payment_status }}</td>
							<td class="num">{{ number_format((float) $row->grand_total, 0) }}</td>
							<td class="num">{{ number_format((float) $row->amount_paid, 0) }}</td>
							<td class="num">{{ number_format($periodPaymentFor((int) $row->id), 0) }}</td>
							<td style="font-size:7px;">{{ $collectorsFor((int) $row->id) }}</td>
							<td class="num">{{ number_format((float) $row->amount_due, 0) }}</td>
						</tr>
					@endforeach
				</tbody>
			</table>
			<table class="data print-mc-subtotal" style="margin:-8px 0 14px;">
				<tbody>
					<tr>
						<td><strong>Credit &amp; default subtotal — {{ $bName }}</strong></td>
						<td class="num">Grand {{ number_format((float) $branchCreditRows->sum('grand_total'), 0) }}</td>
						<td class="num">Paid {{ number_format((float) $branchCreditRows->sum('amount_paid'), 0) }}</td>
						<td class="num">This month {{ number_format($branchCreditRows->sum(fn ($row) => $periodPaymentFor((int) $row->id)), 0) }}</td>
						<td class="num"><strong>Due {{ number_format((float) $branchCreditRows->sum('amount_due'), 0) }}</strong></td>
					</tr>
				</tbody>
			</table>
			@endif

			@if ($branchOverdueInstRows->isNotEmpty())
			<h3 style="font-size:11px;margin:0 0 6px;">Overdue installments</h3>
			<table class="data" style="margin-bottom:14px;">
				<thead>
					<tr>
						<th class="num">#</th>
						<th>Guarantor</th>
						<th>Customer</th>
						<th>Invoice</th>
						<th>Sale date</th>
						<th>Products</th>
						<th class="num">Invoice total</th>
						<th class="num">Total paid</th>
						<th class="num">This month payment</th>
						<th>Collected by</th>
						<th class="num">Total due</th>
						<th class="num">Inst #</th>
						<th>Sched. due</th>
						<th class="num">Late (mo)</th>
						<th class="num">Sched. due amt</th>
						<th class="num">Sched. paid</th>
						<th class="num">Sched. rem.</th>
					</tr>
				</thead>
				<tbody>
					@foreach ($branchOverdueInstRows as $row)
						@php
							$ovIdx++;
							$invoice = $row->invoice;
							$customer = $invoice?->customer;
							$monthsLate = (int) $row->months_late;
						@endphp
						<tr>
							<td class="num">{{ $ovIdx }}</td>
							<td style="font-size:7px;line-height:1.35;max-width:120px;">{{ $guarantorCell($invoice) }}</td>
							<td style="font-size:7px;line-height:1.35;max-width:140px;">
								<strong>{{ $customer?->name ?? '—' }}</strong>
								@if ($customer?->phone)<br>{{ $customer->phone }}@endif
								@if ($customer && $customer->address !== null && trim((string) $customer->address) !== '')
									<br>{{ $customer->address }}
								@endif
							</td>
							<td>{{ $invoice?->invoice_number ?? '—' }}</td>
							<td>{{ $invoice?->invoice_date?->toDateString() ?? '—' }}</td>
							<td style="font-size:7px;line-height:1.35;max-width:160px;">{{ $invoiceProductsWithPrices($invoice) }}</td>
							<td class="num">{{ $invoiceGrandTotalFormatted($invoice) }}</td>
							<td class="num">{{ $invoice ? number_format((float) $invoice->amount_paid, 0) : '—' }}</td>
							<td class="num">{{ $invoice ? number_format($periodPaymentFor((int) $invoice->id), 0) : '—' }}</td>
							<td style="font-size:7px;">{{ $collectorsFor((int) $invoice->id) }}</td>
							<td class="num">{{ $invoice ? number_format((float) $invoice->amount_due, 0) : '—' }}</td>
							<td class="num">{{ $row->installment_nos }}</td>
							<td>{{ $formatSchedDueDate($row->due_date) }}</td>
							<td class="num" style="@if ($monthsLate >= 2) color:#b91c1c;font-weight:bold; @elseif ($monthsLate > 0) color:#854d0e; @endif">
								{{ $monthsLate }}
							</td>
							<td class="num">{{ number_format((float) $row->due_amount, 0) }}</td>
							<td class="num">{{ number_format((float) $row->paid_amount, 0) }}</td>
							<td class="num">{{ number_format((float) $row->remaining, 0) }}</td>
						</tr>
					@endforeach
				</tbody>
			</table>
			<table class="data print-mc-subtotal" style="margin:-8px 0 14px;">
				<tbody>
					<tr>
						<td><strong>Overdue installments subtotal — {{ $bName }}</strong></td>
						<td class="num">Due {{ number_format((float) $branchOverdueInstRows->sum('due_amount'), 0) }}</td>
						<td class="num">Paid {{ number_format((float) $branchOverdueInstRows->sum('paid_amount'), 0) }}</td>
						<td class="num">This month {{ number_format($branchOverdueInstRows->sum(fn ($row) => $periodPaymentFor((int) ($row->invoice?->id ?? 0))), 0) }}</td>
						<td class="num"><strong>Remaining {{ number_format((float) $branchOverdueInstRows->sum('remaining'), 0) }}</strong></td>
					</tr>
				</tbody>
			</table>
			@endif

			@if ($branchOverdueCreditRows->isNotEmpty())
			<h3 style="font-size:11px;margin:0 0 6px;">Overdue credit &amp; default dues</h3>
			<table class="data">
				<thead>
					<tr>
						<th class="num">#</th>
						<th>Guarantor</th>
						<th>Customer</th>
						<th>Invoice</th>
						<th>Sale date</th>
						<th>Products</th>
						<th>Credit due</th>
						<th class="num">Late (mo)</th>
						<th>Pay status</th>
						<th class="num">Grand total</th>
						<th class="num">Total paid</th>
						<th class="num">This month payment</th>
						<th>Collected by</th>
						<th class="num">Total due</th>
					</tr>
				</thead>
				<tbody>
					@foreach ($branchOverdueCreditRows as $row)
						@php
							$ovCredIdx++;
							$customer = $row->customer;
							$monthsLate = $row->creditMonthsLate();
						@endphp
						<tr>
							<td class="num">{{ $ovCredIdx }}</td>
							<td style="font-size:7px;line-height:1.35;max-width:120px;">{{ $guarantorCell($row) }}</td>
							<td style="font-size:7px;line-height:1.35;max-width:140px;">
								<strong>{{ $customer?->name ?? '—' }}</strong>
								@if ($customer?->phone)<br>{{ $customer->phone }}@endif
								@if ($customer && $customer->address !== null && trim((string) $customer->address) !== '')
									<br>{{ $customer->address }}
								@endif
							</td>
							<td>{{ $row->invoice_number }}</td>
							<td>{{ $row->invoice_date?->toDateString() }}</td>
							<td style="font-size:7px;line-height:1.35;max-width:160px;">{{ $invoiceProductsWithPrices($row) }}</td>
							<td>{{ $row->collectionDueDate()?->toDateString() }}</td>
							<td class="num" style="@if ($monthsLate >= 2) color:#b91c1c;font-weight:bold; @elseif ($monthsLate > 0) color:#854d0e; @endif">
								{{ $monthsLate }}
							</td>
							<td>{{ $row->payment_status }}</td>
							<td class="num">{{ number_format((float) $row->grand_total, 0) }}</td>
							<td class="num">{{ number_format((float) $row->amount_paid, 0) }}</td>
							<td class="num">{{ number_format($periodPaymentFor((int) $row->id), 0) }}</td>
							<td style="font-size:7px;">{{ $collectorsFor((int) $row->id) }}</td>
							<td class="num">{{ number_format((float) $row->amount_due, 0) }}</td>
						</tr>
					@endforeach
				</tbody>
			</table>
			<table class="data print-mc-subtotal" style="margin:-8px 0 14px;">
				<tbody>
					<tr>
						<td><strong>Overdue credit &amp; default subtotal — {{ $bName }}</strong></td>
						<td class="num">Grand {{ number_format((float) $branchOverdueCreditRows->sum('grand_total'), 0) }}</td>
						<td class="num">Paid {{ number_format((float) $branchOverdueCreditRows->sum('amount_paid'), 0) }}</td>
						<td class="num">This month {{ number_format($branchOverdueCreditRows->sum(fn ($row) => $periodPaymentFor((int) $row->id)), 0) }}</td>
						<td class="num"><strong>Due {{ number_format((float) $branchOverdueCreditRows->sum('amount_due'), 0) }}</strong></td>
					</tr>
				</tbody>
			</table>
			@endif

			@if ($branchMaturedOverdueRows->isNotEmpty())
			<h3 style="font-size:11px;margin:0 0 6px;">Overdue (matured plans) — final installment date passed</h3>
			<table class="data">
				<thead>
					<tr>
						<th class="num">#</th>
						<th>Guarantor</th>
						<th>Customer</th>
						<th>Invoice</th>
						<th>Sale date</th>
						<th>Products</th>
						<th>Last inst. due</th>
						<th class="num">Late (mo)</th>
						<th>Pay status</th>
						<th class="num">Grand total</th>
						<th class="num">Total paid</th>
						<th class="num">This month payment</th>
						<th>Collected by</th>
						<th class="num">Total due</th>
					</tr>
				</thead>
				<tbody>
					@foreach ($branchMaturedOverdueRows as $row)
						@php
							$maturedIdx++;
							$customer = $row->customer;
							$monthsLate = $row->installmentMaturityMonthsLate();
						@endphp
						<tr>
							<td class="num">{{ $maturedIdx }}</td>
							<td style="font-size:7px;line-height:1.35;max-width:120px;">{{ $guarantorCell($row) }}</td>
							<td style="font-size:7px;line-height:1.35;max-width:140px;">
								<strong>{{ $customer?->name ?? '—' }}</strong>
								@if ($customer?->phone)<br>{{ $customer->phone }}@endif
								@if ($customer && $customer->address !== null && trim((string) $customer->address) !== '')
									<br>{{ $customer->address }}
								@endif
							</td>
							<td>{{ $row->invoice_number }}</td>
							<td>{{ $row->invoice_date?->toDateString() }}</td>
							<td style="font-size:7px;line-height:1.35;max-width:160px;">{{ $invoiceProductsWithPrices($row) }}</td>
							<td>{{ $row->finalInstallmentDueDate()?->toDateString() }}</td>
							<td class="num" style="@if ($monthsLate >= 2) color:#b91c1c;font-weight:bold; @elseif ($monthsLate > 0) color:#854d0e; @endif">
								{{ $monthsLate }}
							</td>
							<td>{{ $row->payment_status }}</td>
							<td class="num">{{ number_format((float) $row->grand_total, 0) }}</td>
							<td class="num">{{ number_format((float) $row->amount_paid, 0) }}</td>
							<td class="num">{{ number_format($periodPaymentFor((int) $row->id), 0) }}</td>
							<td style="font-size:7px;">{{ $collectorsFor((int) $row->id) }}</td>
							<td class="num">{{ number_format((float) $row->amount_due, 0) }}</td>
						</tr>
					@endforeach
				</tbody>
			</table>
			<table class="data print-mc-subtotal" style="margin:-8px 0 0;">
				<tbody>
					<tr>
						<td><strong>Overdue (matured) subtotal — {{ $bName }}</strong></td>
						<td class="num">Grand {{ number_format((float) $branchMaturedOverdueRows->sum('grand_total'), 0) }}</td>
						<td class="num">Paid {{ number_format((float) $branchMaturedOverdueRows->sum('amount_paid'), 0) }}</td>
						<td class="num">This month {{ number_format($branchMaturedOverdueRows->sum(fn ($row) => $periodPaymentFor((int) $row->id)), 0) }}</td>
						<td class="num"><strong>Due {{ number_format((float) $branchMaturedOverdueRows->sum('amount_due'), 0) }}</strong></td>
					</tr>
				</tbody>
			</table>
			@endif
		</div>
	@empty
		<p style="text-align:center;color:#64748b;font-size:10px;">No collection rows for this filter.</p>
	@endforelse

	@if ($thisMonthInstallmentRows->isNotEmpty() || $thisMonthCreditRows->isNotEmpty() || $overdueInstallmentRows->isNotEmpty() || $overdueCreditRows->isNotEmpty() || $maturedOverdueInstallmentRows->isNotEmpty())
	<div style="margin-top:16px;padding:10px 12px;border:1px solid #cbd5e1;background:#fff;page-break-inside:avoid;">
		<h2 style="font-size:11px;margin:0 0 8px;">Report totals (all branches)</h2>
		<table class="data print-report-totals" style="max-width:720px;">
			<thead>
				<tr>
					<th class="num">#</th>
					<th>Section</th>
					<th class="num">Due / Grand</th>
					<th class="num">Paid</th>
					<th class="num">This month payment</th>
						<th>Collected by</th>
					<th class="num">Remaining / Due</th>
				</tr>
			</thead>
			<tbody>
				@php $totalIdx = 0; @endphp
				@if ($thisMonthInstallmentRows->isNotEmpty())
				@php $totalIdx++; @endphp
				<tr>
					<td class="num">{{ $totalIdx }}</td>
					<td>This period installments</td>
					<td class="num">{{ number_format($instDue, 0) }}</td>
					<td class="num">{{ number_format($instPaid, 0) }}</td>
					<td class="num">{{ number_format($thisMonthInstallmentRows->sum(fn ($row) => $periodPaymentFor((int) ($row->invoice?->id ?? 0))), 0) }}</td>
					<td class="num">{{ number_format($instRemaining, 0) }}</td>
				</tr>
				@endif
				@if ($thisMonthCreditRows->isNotEmpty())
				@php $totalIdx++; @endphp
				<tr>
					<td class="num">{{ $totalIdx }}</td>
					<td>This period credit &amp; default</td>
					<td class="num">{{ number_format($creditGrand, 0) }}</td>
					<td class="num">{{ number_format($creditPaid, 0) }}</td>
					<td class="num">{{ number_format($thisMonthCreditRows->sum(fn ($row) => $periodPaymentFor((int) $row->id)), 0) }}</td>
					<td class="num">{{ number_format((float) $creditDue, 0) }}</td>
				</tr>
				@endif
				@if ($overdueInstallmentRows->isNotEmpty())
				@php $totalIdx++; @endphp
				<tr>
					<td class="num">{{ $totalIdx }}</td>
					<td>Overdue installments</td>
					<td class="num">{{ number_format($overdueDue, 0) }}</td>
					<td class="num">{{ number_format($overduePaid, 0) }}</td>
					<td class="num">{{ number_format($overdueInstallmentRows->sum(fn ($row) => $periodPaymentFor((int) ($row->invoice?->id ?? 0))), 0) }}</td>
					<td class="num">{{ number_format($overdueRemaining, 0) }}</td>
				</tr>
				@endif
				@if ($overdueCreditRows->isNotEmpty())
				@php $totalIdx++; @endphp
				<tr>
					<td class="num">{{ $totalIdx }}</td>
					<td>Overdue credit &amp; default</td>
					<td class="num">{{ number_format($overdueCreditGrand, 0) }}</td>
					<td class="num">{{ number_format($overdueCreditPaid, 0) }}</td>
					<td class="num">{{ number_format($overdueCreditRows->sum(fn ($row) => $periodPaymentFor((int) $row->id)), 0) }}</td>
					<td class="num">{{ number_format((float) $overdueCreditDue, 0) }}</td>
				</tr>
				@endif
				@if ($maturedOverdueInstallmentRows->isNotEmpty())
				@php $totalIdx++; @endphp
				<tr>
					<td class="num">{{ $totalIdx }}</td>
					<td>Overdue (matured plans) — final installment date passed</td>
					<td class="num">{{ number_format($maturedOverdueGrand, 0) }}</td>
					<td class="num">{{ number_format($maturedOverduePaid, 0) }}</td>
					<td class="num">{{ number_format($maturedOverdueInstallmentRows->sum(fn ($row) => $periodPaymentFor((int) $row->id)), 0) }}</td>
					<td class="num">{{ number_format((float) $maturedOverdueDue, 0) }}</td>
				</tr>
				@endif
				<tr class="grand-total">
					<td></td>
					<td>Grand total</td>
					<td class="num">—</td>
					<td class="num">{{ number_format($totalPaid, 0) }}</td>
					<td class="num">{{ number_format($periodPaid, 0) }}</td>
					<td class="num">{{ number_format($totalDue, 0) }}</td>
				</tr>
			</tbody>
		</table>
	</div>
	@endif
	@include('reports.partials.collector-subtotals', ['totals' => $summary['collector_totals'] ?? [], 'print' => true])
@endsection
