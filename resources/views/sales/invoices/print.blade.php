<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Invoice {{ $invoice->invoice_number ?? '#'.$invoice->id }}</title>
    <style>
		* { box-sizing: border-box; }
		body {
			margin: 0;
			font-family: "Segoe UI", Arial, sans-serif;
			background: #2f3a45;
			color: #1f2937;
		}
		.frame {
			padding: 20px;
		}
		.toolbar {
			max-width: 860px;
			margin: 0 auto 8px;
			display: flex;
			justify-content: flex-end;
			gap: 8px;
		}
		.btn {
			border: 1px solid #cbd5e1;
			background: #fff;
			color: #334155;
			border-radius: 6px;
			font-size: 12px;
			padding: 7px 11px;
			text-decoration: none;
			cursor: pointer;
		}
		.sheet {
			max-width: 860px;
			margin: 0 auto;
			background: #fff;
			padding: 28px;
			min-height: 1120px;
		}
		.print-repeat-table {
			width: 100%;
			border-collapse: collapse;
			table-layout: fixed;
		}
		.print-repeat-table tr { page-break-inside: avoid; break-inside: avoid; }
		.print-repeat-table tr.no-break-avoid { page-break-inside: auto; break-inside: auto; }
		.print-repeat-table .print-head-cell,
		.print-repeat-table .print-foot-cell {
			border: none;
			padding: 0;
			vertical-align: top;
			text-align: left;
		}
		.top > div:last-child {
			text-align: left;
		}
		.bill-grid > div {
			text-align: left;
		}
		.print-repeat-table thead tr.items-head th {
			padding-top: 6px;
		}
		.top {
			display: flex;
			justify-content: space-between;
			gap: 16px;
		}
		.top-left {
			display: flex;
			align-items: flex-start;
			gap: 14px;
		}
		.heading-small {
			font-size: 14px;
			letter-spacing: .08em;
			text-transform: uppercase;
			color: #4b5563;
			margin-bottom: 4px;
		}
		.brand {
			font-size: 46px;
			line-height: .95;
			font-weight: 700;
			color: #1f2937;
		}
		.company-block {
			margin-top: 12px;
			font-size: 14px;
			line-height: 1.45;
		}
		.company-block b {
			display: block;
			margin-bottom: 2px;
		}
		.logo {
			width: 90px;
			max-height: 90px;
			object-fit: contain;
			flex-shrink: 0;
		}
		.meta-box {
			background: #334155;
			color: #fff;
			padding: 10px 12px;
			font-weight: 700;
			font-size: 20px;
			width: fit-content;
			margin-left: auto;
			line-height: 1.2;
		}
		.meta-side {
			margin-top: 14px;
			font-size: 14px;
			line-height: 1.45;
		}
		.meta-side .k { color: #4b5563; }
		.meta-side .v { font-weight: 700; color: #111827; }
		.bill-grid {
			display: grid;
			grid-template-columns: 1fr 1fr;
			gap: 20px;
			margin-top: 18px;
			font-size: 14px;
		}
		.bill-title {
			font-weight: 700;
			text-transform: uppercase;
			color: #374151;
			margin-bottom: 4px;
		}
		.payment-terms {
			border-top: 1px solid #9ca3af;
			border-bottom: 1px solid #9ca3af;
			margin-top: 18px;
			padding: 6px 0;
			text-align: center;
			font-size: 13px;
			color: #374151;
		}
		.payment-terms b { font-style: italic; }
		table { width: 100%; border-collapse: collapse; }
		.lines-table {
			margin-top: 22px;
		}
		.items-head th {
			padding: 10px 4px;
			font-size: 14px;
			font-weight: 500;
			color: #374151;
			text-transform: uppercase;
			border-bottom: 1px solid #9ca3af;
		}
		.col-desc  { width: 28%; }
		.col-qty   { width: 50px; }
		.col-price { width: 105px; }
		.col-disc  { width: 105px; }
		.col-svc   { width: 95px; }
		.col-total { width: 110px; }
		.items-body td {
			padding: 8px 4px;
			font-size: 20px;
			color: #374151;
			vertical-align: top;
		}
		.taka { font-size: 26px !important; font-weight: 700 !important; line-height: 1; }
		.item-meta {
			margin-top: 4px;
			font-size: 12px;
			line-height: 1.35;
			color: #4b5563;
		}
		.item-meta b {
			font-weight: 700;
			color: #334155;
		}
		.right { text-align: right; }
		.totals-box {
			width: 48%;
			margin-left: auto;
			margin-top: 8px;
			font-size: 18px;
			color: #374151;
		}
		.totals-row {
			display: flex;
			justify-content: space-between;
			padding: 2px 0;
		}
		.totals-row strong { color: #111827; }
		.balance-row {
			display: flex;
			justify-content: space-between;
			border-top: 1px solid #9ca3af;
			padding-top: 8px;
			margin-top: 8px;
			font-size: 22px;
			font-weight: 800;
			color: #111827;
		}
		.pad-footer {
			margin-top: 16px;
			border-top: 1px solid #9ca3af;
			padding-top: 10px;
		}
		.footer-note {
			font-size: 13px;
			color: #374151;
			text-align: center;
			margin-bottom: 20px;
		}
		.signatures {
			display: grid;
			grid-template-columns: 1fr 1fr;
			gap: 36px;
		}
		.signature {
			text-align: center;
		}
		.signature-line {
			border-top: 1px dashed #9ca3af;
			height: 26px;
			margin-bottom: 6px;
		}
		.signature-label {
			font-size: 12px;
			font-weight: 600;
			color: #374151;
		}
		.status-stamp {
			display: inline-block;
			border: 2px solid #111827;
			color: #111827;
			padding: 4px 8px;
			font-size: 11px;
			font-weight: 700;
			letter-spacing: .08em;
			text-transform: uppercase;
			margin-top: 8px;
		}
		@media print {
			body { background: #fff; }
			.frame { padding: 0; }
			.toolbar { display: none; }
			.sheet { max-width: none; width: 100%; margin: 0; padding: 8mm; min-height: 0; }
			.print-repeat-table tr { page-break-inside: avoid !important; break-inside: avoid !important; }
			.print-repeat-table tr.no-break-avoid { page-break-inside: auto !important; break-inside: auto !important; }
			.avoid-break { page-break-inside: avoid !important; break-inside: avoid !important; }
			.break-before { page-break-before: always !important; break-before: always !important; }
			@page { size: A4; margin: 0; }
		}
    </style>
</head>
<body>
<div class="toolbar">
    <a class="btn" href="{{ route('sales.invoices.show', $invoice) }}">Back to invoice</a>
    <button class="btn" type="button" onclick="window.print()">Print</button>
</div>

<div class="frame">
	<div class="sheet">
		@php $currency = 'Tk'; @endphp
		{{-- Invoice header: rendered once, not repeated on page breaks --}}
		<div class="top">
			<div class="top-left">
				@if ($companyPad['company_pad_logo_path'] !== '')
					<img class="logo" src="{{ asset('storage/'.$companyPad['company_pad_logo_path']) }}" alt="Company logo">
				@endif
				<div>
					<div class="heading-small">Invoice</div>
					<div class="brand">{{ $companyPad['company_pad_name'] }}</div>
					<div class="company-block">
						{{ $companyPad['company_pad_address'] }}<br>
						@if ($companyPad['company_pad_email']) {{ $companyPad['company_pad_email'] }}<br> @endif
						@if ($companyPad['company_pad_phone']) {{ $companyPad['company_pad_phone'] }} @endif
					</div>
				</div>
			</div>
			<div>
				<div class="meta-side">
					<div class="k">{{ $invoice->invoice_date?->format('d/m/Y') }}</div>
					<div class="k">{{ $invoice->invoice_number ?? '#'.$invoice->id }}</div>
					<div class="status-stamp">{{ strtoupper(str_replace('_', ' ', $invoice->payment_status)) }}</div>
				</div>
			</div>
		</div>
		<div class="bill-grid">
			<div>
				<div class="bill-title">Customer Info:</div>
				<div><b>{{ $invoice->customer?->name }}</b></div>
				<div>{{ $invoice->customer?->address }}</div>
				<div>{{ $invoice->customer?->email }}</div>
				<div>{{ implode(', ', $invoice->customer?->normalizedPhoneNumbers() ?? []) }}</div>
				@if ($invoice->sale_type === 'installment' && ($invoice->guarantor_name || !empty($invoice->guarantor_phone_numbers)))
					<div><b>Guarantor:</b> {{ $invoice->guarantor_name ?: 'N/A' }}</div>
					@if (!empty($invoice->guarantor_phone_numbers))
						<div><b>Guarantor phones:</b> {{ implode(', ', $invoice->guarantor_phone_numbers) }}</div>
					@endif
				@endif
			</div>
			<div>
				<div class="bill-title">Seller Info:</div>
				<div><b>{{ $companyPad['company_pad_name'] }}</b></div>
				@if ($invoice->branch?->address)
					<div>{{ $invoice->branch->address }}</div>
				@elseif ($companyPad['company_pad_address'])
					<div>{{ $companyPad['company_pad_address'] }}</div>
				@endif
				@if ($invoice->branch?->phone) <div>{{ $invoice->branch->phone }}</div> @endif
				@if ($invoice->branch?->manager_name) <div>Manager: {{ $invoice->branch->manager_name }}</div> @endif
				@if ($companyPad['company_pad_email']) <div>{{ $companyPad['company_pad_email'] }}</div> @endif
				@if ($companyPad['company_pad_phone']) <div>{{ $companyPad['company_pad_phone'] }}</div> @endif
				@if ($companyPad['company_pad_website']) <div>{{ $companyPad['company_pad_website'] }}</div> @endif
			</div>
		</div>

		{{-- Items table: no thead, nothing repeats on page break --}}
		<table class="print-repeat-table">
			<colgroup>
				<col class="col-desc">
				<col class="col-qty">
				<col class="col-price">
				<col class="col-disc">
				<col class="col-svc">
				<col class="col-total">
			</colgroup>
			<tbody class="items-body lines-table">
				<tr class="items-head">
					<th>Description</th>
					<th class="right">Qty</th>
					<th class="right">Price</th>
					<th class="right">Discount</th>
					<th class="right">Service</th>
					<th class="right">Total</th>
				</tr>
				@foreach ($invoice->lines as $line)
					<tr>
						<td>
							{{ $line->product?->name }}
							<div class="item-meta">
								<div><b>Brand:</b> {{ $line->product?->brand?->name ?? 'N/A' }}</div>
								<div><b>Warranty:</b> {{ (int) ($line->warranty_months_snapshot ?? 0) > 0 ? $line->warranty_months_snapshot.' months' : 'No warranty' }}</div>
								<div>
									<b>Asset/MFG:</b>
									@if ($line->saleLineSerials->isNotEmpty())
										{{ $line->saleLineSerials->map(function ($sl) { $s = $sl->serialItem; if (! $s) { return null; } $t = $s->serial_number.' / '.($s->manufacturer_serial ?: 'N/A'); return $sl->released_at ? $t.' (returned)' : $t; })->filter()->implode(', ') }}
									@else
										N/A
									@endif
								</div>
							</div>
						</td>
						<td class="right">{{ $line->qty }}</td>
						<td class="right"><span class="taka">৳</span> {{ number_format($line->unit_price, 2) }}</td>
						<td class="right">
							@php
								$lineDiscount = max(0, (float) ($line->discount_amount ?? 0));
							@endphp
							@if ($lineDiscount <= 0.0001)
								—
							@else
								<span class="taka">৳</span> {{ number_format($lineDiscount, 2) }}
								@if ($line->formattedDiscountPercent() !== null)
									<br><small style="font-size:11px;color:#64748b">({{ $line->formattedDiscountPercent() }}%)</small>
								@endif
							@endif
						</td>
						<td class="right">
							@php
								$psc = max(0, (float) ($line->service_charge ?? 0));
							@endphp
							@if ($psc <= 0.0001)
								—
							@else
								<span class="taka">৳</span> {{ number_format($psc, 2) }}
							@endif
						</td>
						<td class="right"><span class="taka">৳</span> {{ number_format($line->line_total, 2) }}</td>
					</tr>
				@endforeach
				<tr>
					<td colspan="6">
						<div class="avoid-break" style="display:table; width:100%;">
						<div class="totals-box">
							<div class="totals-row"><span>Sub Total</span><span>{{ $currency }} {{ number_format($invoice->subtotal, 2) }}</span></div>
							<div class="totals-row"><span>Tax</span><span>{{ $currency }} {{ number_format((float) $invoice->tax_total, 2) }}</span></div>
							<div class="totals-row"><span>Discount</span><span>{{ $currency }} {{ number_format((float) $invoice->discount_total, 2) }}</span></div>
							<div class="totals-row"><strong>Total Amount</strong><strong>{{ $currency }} {{ number_format($invoice->grand_total, 2) }}</strong></div>
							@php
								$printSettlementDiscount = round((float) $invoice->displaySettlementDiscountApplied(), 2);
								$printCashPaid = round((float) $invoice->displayCashCollected(), 2);
								$printWriteoffTotal = round((float) $invoice->debtWriteoffs->sum('amount'), 2);
							@endphp
							<div class="totals-row"><span>Amount Paid</span><span>{{ $currency }} {{ number_format($printCashPaid, 2) }}</span></div>
							@if ($printSettlementDiscount > 0.009)
								<div class="totals-row"><span>Settlement Discount</span><span>{{ $currency }} {{ number_format($printSettlementDiscount, 2) }}</span></div>
							@endif
							@if ($printWriteoffTotal > 0.009)
								<div class="totals-row"><span>Written Off</span><span>{{ $currency }} {{ number_format($printWriteoffTotal, 2) }}</span></div>
							@endif
							<div class="balance-row"><span>Balance Due</span><span>{{ $currency }} {{ number_format($invoice->amount_due, 2) }}</span></div>
						</div>
						</div>{{-- end avoid-break totals --}}
					</td>
				</tr>
				<tr>
					<td colspan="6">
						<div class="avoid-break" style="display:table; width:100%;">
						<div class="pad-footer">
							@if ($companyPad['company_pad_footer_note'] !== '')
								<div class="footer-note">{{ $companyPad['company_pad_footer_note'] }}</div>
							@endif
							<div class="signatures">
								<div class="signature">
									<div class="signature-line"></div>
									<div class="signature-label">{{ $companyPad['company_pad_footer_left_label'] ?: 'Customer Signature' }}</div>
								</div>
								<div class="signature">
									<div class="signature-line"></div>
									<div class="signature-label">{{ $companyPad['company_pad_footer_right_label'] ?: 'Authorized Signature' }}</div>
								</div>
							</div>{{-- end signatures --}}
							</div>{{-- end pad-footer --}}
						</div>{{-- end avoid-break footer --}}
					</td>
				</tr>

				@php
					// Collect all payment rows across all payment paths
					$printPaymentRows = collect();
					$seenPaymentIds = [];

					// Path 1 & 4: cash/credit/historical — PaymentAllocation rows
					foreach ($invoice->recordedInboundPaymentAllocations as $alloc) {
						$pmt = $alloc->payment;
						if (! $pmt) continue;
						$cash = round((float) $alloc->allocated_amount, 2);
						$disc = round((float) ($alloc->settlement_discount ?? 0), 2);
						if ($cash > 0.009) {
							$methodName = $pmt->paymentMethodRef?->name ?? ucfirst(str_replace('_', ' ', $pmt->payment_method ?? ''));
							$isInstallment = $invoice->sale_type === 'installment';
							$isHistorical = $pmt->isHistoricalPayment();
							// Check if this payment is linked to any installment schedule
							$linkedToSchedule = false;
							if ($isInstallment && $invoice->installmentPlan) {
								foreach ($invoice->installmentPlan->schedules as $sch) {
									foreach ($sch->paymentAllocations as $ia) {
										if ($ia->payment_id == $pmt->id) { $linkedToSchedule = true; break 2; }
									}
								}
							}
							if ($isInstallment && !$linkedToSchedule && !$isHistorical) {
								$label = 'Down Payment · '.$methodName;
							} else {
								$label = $methodName;
							}
							$printPaymentRows->push([
								'date'       => $pmt->payment_date?->format('d/m/Y') ?? '—',
								'label'      => $label,
								'amount'     => $cash,
								'note'       => $isHistorical ? 'Historical' : null,
								'sort'       => $pmt->payment_date?->toDateString() ?? '0000-01-01',
								'payment_id' => $pmt->id,
							]);
							$seenPaymentIds[$pmt->id] = true;
						}
						if ($disc > 0.009) {
							$printPaymentRows->push([
								'date'       => $pmt->payment_date?->format('d/m/Y') ?? '—',
								'label'      => 'Settlement Discount',
								'amount'     => $disc,
								'note'       => null,
								'sort'       => $pmt->payment_date?->toDateString() ?? '0000-01-01',
								'payment_id' => null,
							]);
						}
					}

					// Path 2 & 3: installment schedule payments
					if ($invoice->sale_type === 'installment' && $invoice->installmentPlan) {
						foreach ($invoice->installmentPlan->schedules as $schedule) {
							foreach ($schedule->paymentAllocations as $instAlloc) {
								$pmt = $instAlloc->payment;
								if (! $pmt) continue;
								if (isset($seenPaymentIds[$pmt->id])) continue;
								$amt = round((float) $instAlloc->allocated_amount, 2);
								if ($amt <= 0.009) continue;
								$printPaymentRows->push([
									'date'       => $pmt->payment_date?->format('d/m/Y') ?? '—',
									'label'      => 'Installment #'.$schedule->installment_no.' · '.($pmt->paymentMethodRef?->name ?? ucfirst(str_replace('_', ' ', $pmt->payment_method ?? ''))),
									'amount'     => $amt,
									'note'       => null,
									'sort'       => $pmt->payment_date?->toDateString() ?? '0000-01-01',
									'payment_id' => $pmt->id,
								]);
								$seenPaymentIds[$pmt->id] = true;
							}
						}
					}

					// Path 5: header-only baseline (imported invoices)
					$baseline = $invoice->headerPaymentBaseline();
					if ($baseline['cash'] > 0.009) {
						$printPaymentRows->push([
							'date'   => $invoice->invoice_date?->format('d/m/Y') ?? '—',
							'label'  => 'Payment (imported)',
							'amount' => $baseline['cash'],
							'note'   => 'Imported',
							'sort'   => $invoice->invoice_date?->toDateString() ?? '0000-01-01',
						]);
					}

					// Path 7: debt write-offs
					foreach ($invoice->debtWriteoffs as $wo) {
						$woAmt = round((float) $wo->amount, 2);
						if ($woAmt <= 0.009) continue;
						$printPaymentRows->push([
							'date'   => $wo->created_at?->format('d/m/Y') ?? '—',
							'label'  => 'Debt Write-off',
							'amount' => $woAmt,
							'note'   => $wo->reason ? \Illuminate\Support\Str::limit($wo->reason, 40) : null,
							'sort'   => $wo->created_at?->toDateString() ?? '0000-01-01',
						]);
					}

					$printPaymentRows = $printPaymentRows->sortBy('sort')->values();
				@endphp

				@if ($printPaymentRows->isNotEmpty())
				@php
					$phLeftCount = (int) ceil($printPaymentRows->count() / 2);
					$phLeft  = $printPaymentRows->slice(0, $phLeftCount)->values();
					$phRight = $printPaymentRows->slice($phLeftCount)->values();
				@endphp
				<tr>
					<td colspan="6">
						<div class="avoid-break" style="display:table; width:100%; margin-top:16px; padding-top:8px; font-size:12px !important;">
							<div style="font-size:12px !important; font-weight:700; text-transform:uppercase; letter-spacing:.06em; color:#374151; margin-bottom:5px;">Payment History</div>
							<div style="display:flex; gap:0; align-items:flex-start; font-size:12px !important;">
								{{-- LEFT COLUMN --}}
								<table style="width:50%; border-collapse:collapse; font-size:12px !important; color:#374151;">
									<thead>
										<tr style="border-bottom:1px solid #d1d5db;">
											<th style="text-align:left; padding:2px 4px; font-weight:700; color:#374151; white-space:nowrap; font-size:12px !important;">#</th>
											<th style="text-align:left; padding:2px 4px; font-weight:700; color:#374151; white-space:nowrap; font-size:12px !important;">Date</th>
											<th style="text-align:left; padding:2px 4px; font-weight:700; color:#374151; font-size:12px !important;">Description</th>
											<th style="text-align:right; padding:2px 4px; font-weight:700; color:#374151; white-space:nowrap; font-size:12px !important;">Amount</th>
										</tr>
									</thead>
									<tbody>
										@foreach ($phLeft as $i => $row)
											<tr style="border-bottom:1px solid #f3f4f6;">
												<td style="padding:2px 4px; color:#9ca3af; font-size:12px !important;">{{ $i + 1 }}</td>
												<td style="padding:2px 4px; white-space:nowrap; font-size:12px !important;">{{ $row['date'] }}</td>
												<td style="padding:2px 4px; font-size:10px !important;">{{ $row['label'] }}@if ($row['note'])<span style="color:#9ca3af;"> · {{ $row['note'] }}</span>@endif</td>
												<td style="padding:2px 4px; text-align:right; font-weight:600; white-space:nowrap; font-size:12px !important;">{{ $currency }} {{ number_format($row['amount'], 2) }}</td>
											</tr>
										@endforeach
									</tbody>
								</table>
								{{-- VERTICAL DIVIDER --}}
								@if ($phRight->isNotEmpty())
								<div style="width:1px; background:#d1d5db; align-self:stretch; margin:0 8px; flex-shrink:0;"></div>
								@endif
								{{-- RIGHT COLUMN --}}
								@if ($phRight->isNotEmpty())
								<table style="width:50%; border-collapse:collapse; font-size:12px !important; color:#374151;">
									<thead>
										<tr style="border-bottom:1px solid #d1d5db;">
											<th style="text-align:left; padding:2px 4px; font-weight:700; color:#374151; white-space:nowrap; font-size:12px !important;">#</th>
											<th style="text-align:left; padding:2px 4px; font-weight:700; color:#374151; white-space:nowrap; font-size:12px !important;">Date</th>
											<th style="text-align:left; padding:2px 4px; font-weight:700; color:#374151; font-size:12px !important;">Description</th>
											<th style="text-align:right; padding:2px 4px; font-weight:700; color:#374151; white-space:nowrap; font-size:12px !important;">Amount</th>
										</tr>
									</thead>
									<tbody>
										@foreach ($phRight as $i => $row)
											<tr style="border-bottom:1px solid #f3f4f6;">
												<td style="padding:2px 4px; color:#9ca3af; font-size:12px !important;">{{ $phLeftCount + $i + 1 }}</td>
												<td style="padding:2px 4px; white-space:nowrap; font-size:12px !important;">{{ $row['date'] }}</td>
												<td style="padding:2px 4px; font-size:10px !important;">{{ $row['label'] }}@if ($row['note'])<span style="color:#9ca3af;"> · {{ $row['note'] }}</span>@endif</td>
												<td style="padding:2px 4px; text-align:right; font-weight:600; white-space:nowrap; font-size:12px !important;">{{ $currency }} {{ number_format($row['amount'], 2) }}</td>
											</tr>
										@endforeach
									</tbody>
								</table>
								@endif
							</div>
						</div>
					</td>
				</tr>
				@endif
			</tbody>
		</table>
	</div>
</div>
<script>
    window.addEventListener('load', () => window.print());
</script>
</body>
</html>
