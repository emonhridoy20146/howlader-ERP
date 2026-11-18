<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Return receipt {{ $return->return_number }}</title>
    <style>
        * { box-sizing: border-box; }
        body { margin: 0; font-family: "Segoe UI", Arial, sans-serif; background: #2f3a45; color: #1f2937; }
        .frame { padding: 20px; }
        .toolbar { max-width: 720px; margin: 0 auto 8px; display: flex; justify-content: flex-end; gap: 8px; }
        .btn { border: 1px solid #cbd5e1; background: #fff; color: #334155; border-radius: 6px; font-size: 12px; padding: 7px 11px; text-decoration: none; cursor: pointer; }
        .sheet { max-width: 720px; margin: 0 auto; background: #fff; padding: 28px; min-height: 900px; }
        .print-repeat-table { width: 100%; border-collapse: collapse; table-layout: fixed; }
        .print-repeat-table > thead { display: table-header-group; }
        .print-repeat-table > tfoot { display: table-footer-group; }
        .print-repeat-table tr { page-break-inside: avoid; break-inside: avoid; }
        .print-repeat-table .print-head-cell,
        .print-repeat-table .print-foot-cell { border: none; padding: 0; vertical-align: top; text-align: left; }
        .top > div:last-child { text-align: left; }
        .bill-grid > div { text-align: left; }
        .print-repeat-table thead tr.items-head th { padding-top: 14px; }
        .top { display: flex; justify-content: space-between; gap: 16px; }
        .heading-small { font-size: 14px; letter-spacing: .08em; text-transform: uppercase; color: #4b5563; margin-bottom: 4px; }
        .brand { font-size: 32px; line-height: 1; font-weight: 700; color: #1f2937; }
        .company-block { margin-top: 12px; font-size: 14px; line-height: 1.45; }
        .logo { width: 120px; max-height: 72px; object-fit: contain; margin-left: auto; margin-bottom: 10px; }
        .meta-side { margin-top: 14px; font-size: 13px; line-height: 1.5; }
        .meta-side .k { color: #4b5563; }
        .bill-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 18px; margin-top: 18px; font-size: 14px; }
        .bill-title { font-weight: 700; text-transform: uppercase; color: #374151; margin-bottom: 4px; font-size: 12px; letter-spacing: .04em; }
        .status-stamp { display: inline-block; border: 2px solid #111827; color: #111827; padding: 4px 8px; font-size: 11px; font-weight: 700; letter-spacing: .08em; text-transform: uppercase; margin-top: 8px; }
        table { width: 100%; border-collapse: collapse; }
        .lines-table { margin-top: 20px; }
        .items-head th { text-align: left; padding: 10px 4px; font-size: 12px; font-weight: 600; color: #374151; text-transform: uppercase; border-bottom: 1px solid #9ca3af; }
        .items-body td { padding: 8px 4px; font-size: 13px; color: #374151; vertical-align: top; border-bottom: 1px solid #e5e7eb; }
        .right { text-align: right; }
        .mono { font-family: ui-monospace, monospace; font-size: 12px; }
        .totals-box { width: 100%; max-width: 320px; margin-left: auto; margin-top: 16px; font-size: 14px; color: #374151; }
        .totals-row { display: flex; justify-content: space-between; padding: 4px 0; }
        .pad-footer { border-top: 1px solid #9ca3af; padding-top: 14px; }
        .signatures { display: grid; grid-template-columns: 1fr 1fr; gap: 36px; margin-top: 8px; }
        .signature { text-align: center; }
        .signature-line { border-top: 1px dashed #9ca3af; height: 28px; margin-bottom: 6px; }
        .signature-label { font-size: 12px; font-weight: 600; color: #374151; }
        @media print {
            body { background: #fff; }
            .frame { padding: 0; }
            .toolbar { display: none; }
            .sheet { max-width: none; width: 100%; margin: 0; padding: 8mm; min-height: auto; }
            .print-repeat-table > thead { display: table-header-group !important; }
            .print-repeat-table > tfoot { display: table-footer-group !important; }
            .print-repeat-table tr { page-break-inside: avoid !important; break-inside: avoid !important; }
            @page { size: A4; margin: 12mm; }
        }
    </style>
</head>
<body>
<div class="toolbar">
    <a class="btn" href="{{ route('returns.show', $return) }}">Back to return</a>
    <button class="btn" type="button" onclick="window.print()">Print</button>
</div>

<div class="frame">
    <div class="sheet">
        @php $currency = 'Tk'; @endphp
        <table class="print-repeat-table">
            <thead>
                <tr>
                    <th colspan="2" class="print-head-cell" scope="colgroup">
                        <div class="top">
                            <div>
                                <div class="heading-small">Goods return receipt</div>
                                <div class="brand">{{ $companyPad['company_pad_name'] }}</div>
                                <div class="company-block">
                                    {{ $companyPad['company_pad_address'] }}<br>
                                    @if ($companyPad['company_pad_email']) {{ $companyPad['company_pad_email'] }}<br> @endif
                                    @if ($companyPad['company_pad_phone']) {{ $companyPad['company_pad_phone'] }} @endif
                                </div>
                            </div>
                            <div>
                                @if ($companyPad['company_pad_logo_path'] !== '')
                                    <img class="logo" src="{{ asset('storage/'.$companyPad['company_pad_logo_path']) }}" alt="Company logo">
                                @endif
                                <div class="meta-side">
                                    <div class="k">Return: <span class="mono">{{ $return->return_number }}</span></div>
                                    <div class="k">Printed: {{ $printedAt->format('Y-m-d H:i') }}</div>
                                    <div class="k">Return date: {{ $return->return_date?->format('Y-m-d') ?? '—' }}</div>
                                    @if ($return->salesInvoice)
                                        <div class="k">Invoice: <span class="mono">{{ $return->salesInvoice->invoice_number }}</span></div>
                                    @endif
                                    <div class="status-stamp">{{ strtoupper($return->status) }}</div>
                                </div>
                            </div>
                        </div>
                        <div class="bill-grid">
                            <div>
                                <div class="bill-title">Customer</div>
                                <div><b>{{ $return->customer?->name ?? '—' }}</b></div>
                            </div>
                            <div>
                                <div class="bill-title">Branch</div>
                                <div>{{ $return->branch?->name ?: '—' }}</div>
                            </div>
                        </div>
                        @if ($return->reason)
                            <div style="margin-top: 16px; font-size: 13px;">
                                <span class="bill-title">Reason</span>
                                <p style="margin: 6px 0 0; white-space: pre-wrap;">{{ $return->reason }}</p>
                            </div>
                        @endif
                    </th>
                </tr>
                <tr class="items-head">
                    <th>Item</th>
                    <th class="right">Qty / serial</th>
                </tr>
            </thead>
            <tbody class="items-body lines-table">
                @forelse ($return->returnLines as $rl)
                    <tr>
                        <td>
                            {{ $rl->salesInvoiceLine?->product?->name ?? 'Line' }}
                            @if ($rl->salesInvoiceLine?->product?->model_number)
                                <span style="color:#64748b">· {{ $rl->salesInvoiceLine->product->model_number }}</span>
                            @endif
                        </td>
                        <td class="right mono">
                            @if ($rl->serialItem)
                                {{ $rl->serialItem->serial_number ?: $rl->serialItem->manufacturer_serial }}
                            @else
                                {{ (int) $rl->qty }}
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="2" style="padding:16px;text-align:center;color:#64748b;">No return lines.</td></tr>
                @endforelse
                @if ((float) $return->refund_settlement_amount > 0 || $return->refund)
                    <tr>
                        <td colspan="2">
                            <div class="totals-box">
                                <div class="totals-row"><span>Refund base</span><span>{{ $currency }} {{ number_format((float) $return->refund_base_amount, 2) }}</span></div>
                                <div class="totals-row"><span>Percentage</span><span>{{ number_format((float) $return->refund_percentage, 2) }}%</span></div>
                                <div class="totals-row"><span>Settlement</span><span><b>{{ $currency }} {{ number_format((float) $return->refund_settlement_amount, 2) }}</b></span></div>
                                <div class="totals-row"><span>Paid</span><span>{{ $currency }} {{ number_format((float) $return->refund_paid_amount, 2) }}</span></div>
                                <div class="totals-row"><span>Due</span><span>{{ $currency }} {{ number_format((float) $return->refund_due_amount, 2) }}</span></div>
                                @if ($return->refund_status)
                                    <div class="totals-row"><span>Refund status</span><span>{{ ucfirst($return->refund_status) }}</span></div>
                                @endif
                            </div>
                        </td>
                    </tr>
                @endif
                @if ($return->refund && $return->refund->allocations->isNotEmpty())
                    <tr>
                        <td colspan="2" style="font-size: 12px;">
                            <div class="bill-title">Refund payments</div>
                            <table style="margin-top:8px; width:100%;">
                                <thead class="items-head">
                                    <tr>
                                        <th>Amount</th>
                                        <th>Reference</th>
                                    </tr>
                                </thead>
                                <tbody class="items-body">
                                    @foreach ($return->refund->allocations as $alloc)
                                        <tr>
                                            <td class="mono">{{ number_format((float) $alloc->allocated_amount, 2) }}</td>
                                            <td>
                                                @if ($alloc->payment)
                                                    {{ $alloc->payment->payment_number }} @if ($alloc->payment->payment_date) ({{ $alloc->payment->payment_date->format('Y-m-d') }}) @endif
                                                @else
                                                    —
                                                @endif
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </td>
                    </tr>
                @endif
            </tbody>
            <tfoot>
                <tr>
                    <th colspan="2" class="print-foot-cell" scope="colgroup">
                        <div class="pad-footer">
                            @if ($companyPad['company_pad_footer_note'])
                                <p style="font-size: 12px; color: #475569; text-align: center; margin: 0 0 16px;">{{ $companyPad['company_pad_footer_note'] }}</p>
                            @endif
                            <div class="signatures">
                                <div class="signature">
                                    <div class="signature-line"></div>
                                    <div class="signature-label">{{ $companyPad['company_pad_footer_left_label'] }}</div>
                                </div>
                                <div class="signature">
                                    <div class="signature-line"></div>
                                    <div class="signature-label">{{ $companyPad['company_pad_footer_right_label'] }}</div>
                                </div>
                            </div>
                        </div>
                    </th>
                </tr>
            </tfoot>
        </table>
    </div>
</div>
</body>
</html>
