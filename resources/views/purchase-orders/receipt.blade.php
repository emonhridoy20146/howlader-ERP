<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>PO Receipt {{ $purchaseOrder->po_number }}</title>
    <style>
        * { box-sizing: border-box; }
        body { margin: 0; font-family: "Segoe UI", Arial, sans-serif; background: #2f3a45; color: #1f2937; font-size: 12px; line-height: 1.35; }
        .frame { padding: 12px; }
        .toolbar { max-width: 860px; margin: 0 auto 8px; display: flex; justify-content: flex-end; gap: 8px; }
        .btn { border: 1px solid #cbd5e1; background: #fff; color: #334155; border-radius: 6px; font-size: 12px; padding: 7px 11px; text-decoration: none; cursor: pointer; }
        .sheet { max-width: 860px; margin: 0 auto; background: #fff; padding: 14px 16px; min-height: 0; }
        .print-repeat-table { width: 100%; border-collapse: collapse; table-layout: fixed; }
        .print-repeat-table > thead { display: table-header-group; }
        .print-repeat-table > tfoot { display: table-footer-group; }
        .print-repeat-table tr { page-break-inside: avoid; break-inside: avoid; }
        .print-repeat-table .print-head-cell,
        .print-repeat-table .print-foot-cell { border: none; padding: 0; vertical-align: top; text-align: left; }
        .top > div:last-child { text-align: left; }
        .bill-grid > div { text-align: left; }
        .print-repeat-table thead tr.items-head th { padding-top: 6px; }
        .top { display: flex; justify-content: space-between; gap: 10px; }
        .top-left { display: flex; align-items: flex-start; gap: 10px; }
        .heading-small { font-size: 11px; letter-spacing: .08em; text-transform: uppercase; color: #4b5563; margin-bottom: 2px; }
        .brand { font-size: 30px; line-height: 1; font-weight: 700; color: #1f2937; }
        .company-block { margin-top: 6px; font-size: 11px; line-height: 1.35; }
        .logo { width: 64px; max-height: 64px; object-fit: contain; flex-shrink: 0; }
        .meta-side { margin-top: 6px; font-size: 11px; line-height: 1.35; }
        .meta-side .k { color: #4b5563; }
        .bill-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin-top: 10px; font-size: 11px; }
        .bill-title { font-weight: 700; text-transform: uppercase; color: #374151; margin-bottom: 2px; font-size: 11px; letter-spacing: .04em; }
        .status-stamp { display: inline-block; border: 1px solid #111827; color: #111827; padding: 2px 6px; font-size: 10px; font-weight: 700; letter-spacing: .06em; text-transform: uppercase; margin-top: 4px; }
        table { width: 100%; border-collapse: collapse; }
        .lines-table { margin-top: 8px; }
        .items-head th { text-align: left; padding: 5px 2px; font-size: 10px; font-weight: 600; color: #374151; text-transform: uppercase; border-bottom: 1px solid #9ca3af; letter-spacing: .03em; }
        .items-body td { padding: 4px 2px; font-size: 11px; color: #374151; vertical-align: top; border-bottom: 1px solid #e5e7eb; line-height: 1.3; }
        .right { text-align: right; }
        .settlement { display: grid; grid-template-columns: 1.15fr 0.85fr; gap: 14px; align-items: start; margin-top: 8px; }
        .settlement--totals-only { grid-template-columns: 1fr; }
        .settlement--totals-only .totals-box { width: 52%; margin-left: auto; }
        .pay-ledger { border: 1px solid #d1d5db; border-radius: 4px; overflow: hidden; background: #fff; }
        .pay-ledger__head { display: flex; align-items: baseline; justify-content: space-between; gap: 8px; padding: 6px 8px; border-bottom: 1px solid #d1d5db; background: #f8fafc; }
        .pay-ledger__title { font-size: 10px; font-weight: 700; letter-spacing: .08em; text-transform: uppercase; color: #1f2937; }
        .pay-ledger__count { font-size: 9px; color: #64748b; white-space: nowrap; }
        .pay-ledger table { width: 100%; }
        .pay-ledger th { text-align: left; font-size: 9px; font-weight: 700; letter-spacing: .04em; text-transform: uppercase; color: #64748b; padding: 4px 8px; border-bottom: 1px solid #e5e7eb; background: #fff; }
        .pay-ledger td { font-size: 10px; color: #374151; padding: 5px 8px; border-bottom: 1px solid #f1f5f9; vertical-align: top; line-height: 1.35; }
        .pay-ledger tbody tr:last-child td { border-bottom: 0; }
        .pay-ledger__date { white-space: nowrap; font-variant-numeric: tabular-nums; color: #111827; font-weight: 600; width: 72px; }
        .pay-ledger__detail { color: #4b5563; }
        .pay-ledger__wallet { display: block; font-weight: 600; color: #1f2937; }
        .pay-ledger__meta { display: block; margin-top: 1px; font-size: 9px; color: #64748b; }
        .pay-ledger__amt { text-align: right; white-space: nowrap; font-variant-numeric: tabular-nums; font-weight: 600; color: #111827; }
        .pay-ledger__disc { display: block; margin-top: 1px; font-size: 9px; font-weight: 500; color: #4338ca; }
        .pay-ledger__foot { display: flex; justify-content: space-between; align-items: center; gap: 8px; padding: 5px 8px; border-top: 1px solid #d1d5db; background: #f8fafc; font-size: 10px; }
        .pay-ledger__foot-label { font-weight: 600; color: #475569; text-transform: uppercase; letter-spacing: .04em; font-size: 9px; }
        .pay-ledger__foot-amt { font-weight: 700; font-variant-numeric: tabular-nums; color: #111827; }
        .pay-ledger__foot-note { margin-left: 4px; font-size: 9px; font-weight: 500; color: #4338ca; }
        .pay-ledger__empty { padding: 10px 8px; font-size: 10px; color: #94a3b8; font-style: italic; }
        .cost-strip { margin-top: 0; border-top: 1px dashed #d1d5db; padding: 6px 8px; }
        .cost-strip__title { font-size: 9px; font-weight: 700; letter-spacing: .06em; text-transform: uppercase; color: #64748b; margin-bottom: 3px; }
        .cost-strip table { width: 100%; }
        .cost-strip th { text-align: left; font-size: 8px; font-weight: 700; letter-spacing: .03em; text-transform: uppercase; color: #94a3b8; padding: 2px 0; border-bottom: 1px solid #e5e7eb; }
        .cost-strip td { font-size: 10px; color: #475569; padding: 2px 0; border-bottom: 1px solid #f8fafc; }
        .cost-strip tr:last-child td { border-bottom: 0; }
        .totals-box { width: 100%; margin-top: 0; font-size: 11px; color: #374151; border: 1px solid #d1d5db; border-radius: 4px; padding: 8px 10px; background: #fafafa; }
        .totals-row { display: flex; justify-content: space-between; padding: 2px 0; gap: 8px; }
        .balance-row { display: flex; justify-content: space-between; border-top: 1px solid #9ca3af; padding-top: 5px; margin-top: 5px; font-size: 13px; font-weight: 700; color: #111827; }
        .pad-footer { border-top: 1px solid #9ca3af; padding-top: 6px; margin-top: 8px; }
        .footer-note { font-size: 10px; color: #374151; text-align: center; margin-bottom: 8px; line-height: 1.35; }
        .signatures { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-top: 28px; padding-top: 8px; }
        .signature { text-align: center; }
        .signature-line { border-top: 1px dashed #9ca3af; height: 16px; margin-bottom: 3px; }
        .signature-label { font-size: 10px; font-weight: 600; color: #374151; }
        @media print {
            body { background: #fff; font-size: 11px; }
            .frame { padding: 0; }
            .toolbar { display: none; }
            .sheet { max-width: none; width: 100%; margin: 0; padding: 4mm 5mm; min-height: 0; }
            .print-repeat-table > thead { display: table-header-group !important; }
            .print-repeat-table > tfoot { display: table-footer-group !important; }
            .print-repeat-table tr { page-break-inside: avoid !important; break-inside: avoid !important; }
            .items-body td { font-size: 10px; padding: 3px 2px; }
            .items-head th { font-size: 9px; padding: 4px 2px; }
            .totals-box { font-size: 10px; padding: 6px 8px; }
            .balance-row { font-size: 12px; }
            .pay-ledger th { font-size: 8px; padding: 3px 6px; }
            .pay-ledger td { font-size: 9px; padding: 4px 6px; }
            .pay-ledger__meta,
            .pay-ledger__disc { font-size: 8px; }
            .cost-strip td { font-size: 9px; }
            @page { size: A4; margin: 0; }
        }
    </style>
</head>
<body>
<div class="toolbar">
    <a class="btn" href="{{ route('purchase-orders.show', $purchaseOrder) }}">Back to PO</a>
    <button class="btn" type="button" onclick="window.print()">Print</button>
</div>

<div class="frame">
    <div class="sheet">
        @php $currency = 'Tk'; @endphp
        <table class="print-repeat-table">
            <thead>
                <tr>
                    <th colspan="7" class="print-head-cell" scope="colgroup">
                        <div class="top">
                            <div class="top-left">
                                @if ($companyPad['company_pad_logo_path'] !== '')
                                    <img class="logo" src="{{ asset('storage/'.$companyPad['company_pad_logo_path']) }}" alt="Company logo">
                                @endif
                                <div>
                                    <div class="heading-small">Purchase Order Receipt</div>
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
                                    <div class="k">PO: {{ $purchaseOrder->po_number }}</div>
                                    <div class="k">Printed: {{ $printedAt->format('Y-m-d H:i') }}</div>
                                    <div class="k">Ordered: {{ optional($purchaseOrder->ordered_at)->format('Y-m-d') }}</div>
                                    <div class="k">Expected: {{ optional($purchaseOrder->expected_at)->format('Y-m-d') ?? '—' }}</div>
                                    <div class="status-stamp">{{ strtoupper(str_replace('_', ' ', $purchaseOrder->status)) }}</div>
                                </div>
                            </div>
                        </div>
                        <div class="bill-grid">
                            <div>
                                <div class="bill-title">Supplier Info</div>
                                <div><b>{{ $purchaseOrder->supplier?->name }}</b></div>
                                <div>Contact: {{ $purchaseOrder->supplier?->contact_person ?: '—' }}</div>
                                <div>Phone: {{ $purchaseOrder->supplier?->phone ?: '—' }}</div>
                                <div>Email: {{ $purchaseOrder->supplier?->email ?: '—' }}</div>
                                <div>Tax ID: {{ $purchaseOrder->supplier?->tax_number ?: '—' }}</div>
                                <div>Address: {{ $purchaseOrder->supplier?->address ?: '—' }}</div>
                            </div>
                            <div>
                                <div class="bill-title">Warehouse</div>
                                <div><b>{{ $purchaseOrder->destinationLocation?->name }}</b></div>
                                <div>Notes: {{ $purchaseOrder->notes ?: '—' }}</div>
                            </div>
                        </div>
                    </th>
                </tr>
                <tr class="items-head">
                    <th>Description</th>
                    <th class="right">Ord</th>
                    <th class="right">Rcvd</th>
                    <th class="right">Unit</th>
                    <th class="right">Total</th>
                    <th class="right">L. unit</th>
                    <th class="right">L. line</th>
                </tr>
            </thead>
            <tbody class="items-body lines-table">
                @foreach ($purchaseOrder->lines as $line)
                    @php $landed = $landedLineAllocations[$line->id] ?? null; @endphp
                    <tr>
                        <td>{{ $line->product?->sku }} - {{ $line->product?->name }}</td>
                        <td class="right">{{ $line->qty_ordered }}</td>
                        <td class="right">{{ $line->qty_received }}</td>
                        <td class="right">{{ $currency }} {{ number_format((float) $line->unit_cost, 2) }}</td>
                        <td class="right">{{ $currency }} {{ number_format((float) $line->line_total, 2) }}</td>
                        <td class="right">{{ $landed ? $currency.' '.number_format($landed['landed_unit_cost'], 2) : '—' }}</td>
                        <td class="right">{{ $landed ? $currency.' '.number_format($landed['landed_line_total'], 2) : '—' }}</td>
                    </tr>
                @endforeach
                @php
                    $hasAdditionalCosts = is_array($purchaseOrder->additional_costs) && count($purchaseOrder->additional_costs) > 0;
                    $hasSupplierPayments = $purchaseOrder->payments->isNotEmpty();
                    $supplierPaymentsCash = round((float) $purchaseOrder->payments->sum('amount'), 2);
                    $supplierPaymentsDiscount = round((float) $purchaseOrder->payments->sum(fn ($p) => (float) ($p->settlement_discount ?? 0)), 2);
                    $supplierPaymentsApplied = round($supplierPaymentsCash + $supplierPaymentsDiscount, 2);
                @endphp
                <tr>
                    <td colspan="7">
                        <div @class(['settlement', 'settlement--totals-only' => ! $hasSupplierPayments && ! $hasAdditionalCosts])>
                            @if ($hasSupplierPayments || $hasAdditionalCosts)
                                <div class="pay-ledger">
                                    <div class="pay-ledger__head">
                                        <div class="pay-ledger__title">Payment History</div>
                                        @if ($hasSupplierPayments)
                                            <div class="pay-ledger__count">{{ $purchaseOrder->payments->count() }} {{ $purchaseOrder->payments->count() === 1 ? 'transaction' : 'transactions' }}</div>
                                        @endif
                                    </div>
                                    @if ($hasSupplierPayments)
                                        <table>
                                            <thead>
                                                <tr>
                                                    <th>Date</th>
                                                    <th>Particulars</th>
                                                    <th class="right">Amount</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                @foreach ($purchaseOrder->payments as $payRef)
                                                    @php
                                                        $payCash = round((float) $payRef->amount, 2);
                                                        $payDisc = round((float) ($payRef->settlement_discount ?? 0), 2);
                                                        $isRefund = $payRef->isRefund();
                                                        $walletLabel = $payRef->wallet
                                                            ? trim(($payRef->wallet->code ?? '').(($payRef->wallet->code && $payRef->wallet->name) ? ' — ' : '').($payRef->wallet->name ?? ''))
                                                            : '—';
                                                    @endphp
                                                    <tr>
                                                        <td class="pay-ledger__date">{{ $payRef->paid_at?->format('Y-m-d') }}</td>
                                                        <td class="pay-ledger__detail">
                                                            <span class="pay-ledger__wallet">{{ $isRefund ? 'Refund → ' : '' }}{{ $walletLabel }}</span>
                                                            @if ($payRef->reference)
                                                                <span class="pay-ledger__meta">Ref: {{ $payRef->reference }}</span>
                                                            @endif
                                                            @if ($payRef->notes)
                                                                <span class="pay-ledger__meta">{{ $payRef->notes }}</span>
                                                            @endif
                                                            @if ($payRef->creator?->name)
                                                                <span class="pay-ledger__meta">By {{ $payRef->creator->name }}</span>
                                                            @endif
                                                        </td>
                                                        <td class="pay-ledger__amt">
                                                            {{ $currency }} {{ number_format($payCash, 2) }}
                                                            @if ($payDisc > 0.009)
                                                                <span class="pay-ledger__disc">Disc. {{ number_format($payDisc, 2) }}</span>
                                                            @endif
                                                        </td>
                                                    </tr>
                                                @endforeach
                                            </tbody>
                                        </table>
                                        <div class="pay-ledger__foot">
                                            <span class="pay-ledger__foot-label">Supplier paid</span>
                                            <span class="pay-ledger__foot-amt">
                                                {{ $currency }} {{ number_format($supplierPaymentsApplied, 2) }}
                                                @if ($supplierPaymentsDiscount > 0.009)
                                                    <span class="pay-ledger__foot-note">(incl. disc. {{ number_format($supplierPaymentsDiscount, 2) }})</span>
                                                @endif
                                            </span>
                                        </div>
                                    @else
                                        <div class="pay-ledger__empty">No supplier payments recorded.</div>
                                    @endif

                                    @if ($hasAdditionalCosts)
                                        <div class="cost-strip">
                                            <div class="cost-strip__title">Additional costs (wallet)</div>
                                            <table>
                                                <thead>
                                                    <tr>
                                                        <th>Head</th>
                                                        <th>Date</th>
                                                        <th>Wallet</th>
                                                        <th class="right">Amt</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    @foreach ($purchaseOrder->additional_costs as $cost)
                                                        <tr>
                                                            <td>{{ $cost['label'] ?? 'Misc Cost' }}</td>
                                                            <td>{{ $cost['paid_at'] ?? '—' }}</td>
                                                            <td>{{ ($cost['wallet_code'] ?? '') !== '' ? $cost['wallet_code'] : '—' }}</td>
                                                            <td class="right">{{ $currency }} {{ number_format((float) ($cost['amount'] ?? 0), 2) }}</td>
                                                        </tr>
                                                    @endforeach
                                                </tbody>
                                            </table>
                                        </div>
                                    @endif
                                </div>
                            @endif

                            <div class="totals-box">
                                <div class="totals-row"><span>Subtotal</span><span>{{ $currency }} {{ number_format((float) $purchaseOrder->subtotal_amount, 2) }}</span></div>
                                <div class="totals-row"><span>Discount</span><span>{{ $currency }} {{ number_format((float) $purchaseOrder->discount_amount, 2) }}</span></div>
                                <div class="totals-row"><span>Tax</span><span>{{ $currency }} {{ number_format((float) $purchaseOrder->tax_amount, 2) }}</span></div>
                                <div class="totals-row"><span>Additional costs</span><span>{{ $currency }} {{ number_format((float) $purchaseOrder->additional_cost_amount, 2) }}</span></div>
                                <div class="totals-row"><strong>Grand Total</strong><strong>{{ $currency }} {{ number_format((float) $purchaseOrder->total_amount, 2) }}</strong></div>
                                <div class="totals-row"><span>Paid (supplier + extra costs)</span><span>{{ $currency }} {{ number_format((float) $amountPaid, 2) }}</span></div>
                                <div class="balance-row"><span>Due</span><span>{{ $currency }} {{ number_format((float) $amountDue, 2) }}</span></div>
                            </div>
                        </div>
                    </td>
                </tr>
            </tbody>
            <tfoot>
                <tr>
                    <th colspan="7" class="print-foot-cell" scope="colgroup">
                        <div class="pad-footer">
                            @if ($companyPad['company_pad_footer_note'] !== '')
                                <div class="footer-note">{{ $companyPad['company_pad_footer_note'] }}</div>
                            @endif
                            <div class="signatures">
                                <div class="signature">
                                    <div class="signature-line"></div>
                                    <div class="signature-label">{{ $companyPad['company_pad_footer_left_label'] ?: 'Supplier Signature' }}</div>
                                </div>
                                <div class="signature">
                                    <div class="signature-line"></div>
                                    <div class="signature-label">{{ $companyPad['company_pad_footer_right_label'] ?: 'Authorized Signature' }}</div>
                                </div>
                            </div>
                        </div>
                    </th>
                </tr>
            </tfoot>
        </table>
    </div>
</div>
<script>
    window.addEventListener('load', () => window.print());
</script>
</body>
</html>
