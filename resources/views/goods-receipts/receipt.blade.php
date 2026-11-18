<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Goods Receipt {{ $goodsReceipt->grn_number }}</title>
    <style>
        * { box-sizing: border-box; }
        body { margin: 0; font-family: "Segoe UI", Arial, sans-serif; background: #2f3a45; color: #1f2937; }
        .frame { padding: 20px; }
        .toolbar { max-width: 860px; margin: 0 auto 8px; display: flex; justify-content: flex-end; gap: 8px; }
        .btn { border: 1px solid #cbd5e1; background: #fff; color: #334155; border-radius: 6px; font-size: 12px; padding: 7px 11px; text-decoration: none; cursor: pointer; }
        .sheet { max-width: 860px; margin: 0 auto; background: #fff; padding: 28px; min-height: 1120px; }
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
        .brand { font-size: 42px; line-height: .95; font-weight: 700; color: #1f2937; }
        .company-block { margin-top: 12px; font-size: 14px; line-height: 1.45; }
        .logo { width: 130px; max-height: 80px; object-fit: contain; margin-left: auto; margin-bottom: 10px; }
        .meta-side { margin-top: 14px; font-size: 14px; line-height: 1.45; }
        .meta-side .k { color: #4b5563; }
        .bill-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-top: 18px; font-size: 14px; }
        .bill-title { font-weight: 700; text-transform: uppercase; color: #374151; margin-bottom: 4px; }
        .status-stamp { display: inline-block; border: 2px solid #111827; color: #111827; padding: 4px 8px; font-size: 11px; font-weight: 700; letter-spacing: .08em; text-transform: uppercase; margin-top: 8px; }
        table { width: 100%; border-collapse: collapse; }
        .lines-table { margin-top: 22px; }
        .items-head th { text-align: left; padding: 10px 4px; font-size: 15px; font-weight: 600; color: #374151; text-transform: uppercase; border-bottom: 1px solid #9ca3af; }
        .items-body td { padding: 8px 4px; font-size: 14px; color: #374151; vertical-align: top; border-bottom: 1px solid #e5e7eb; }
        .right { text-align: right; }
        .totals-box { width: 48%; margin-left: auto; margin-top: 10px; font-size: 15px; color: #374151; }
        .totals-row { display: flex; justify-content: space-between; padding: 3px 0; }
        .balance-row { display: flex; justify-content: space-between; border-top: 1px solid #9ca3af; padding-top: 8px; margin-top: 8px; font-size: 18px; font-weight: 700; color: #111827; }
        .ref-box { margin-top: 16px; border: 1px solid #cbd5e1; border-radius: 8px; padding: 10px; }
        .ref-box--compact { margin-top: 12px; padding: 8px 10px; }
        .ref-box--compact .bill-title { font-size: 12px; margin-bottom: 6px; letter-spacing: .04em; }
        .ref-box--compact table { font-size: 12px; }
        .ref-box--compact th,
        .ref-box--compact td { padding: 4px 10px 4px 0; text-align: left; vertical-align: top; border: 0; color: #374151; }
        .ref-box--compact thead th { font-size: 11px; font-weight: 700; text-transform: uppercase; color: #475569; border-bottom: 1px solid #e2e8f0; }
        .mono { font-family: ui-monospace, monospace; font-size: 11px; }
        .pad-footer { border-top: 1px solid #9ca3af; padding-top: 10px; }
        .footer-note { font-size: 13px; color: #374151; text-align: center; margin-bottom: 20px; }
        .signatures { display: grid; grid-template-columns: 1fr 1fr; gap: 36px; }
        .signature { text-align: center; }
        .signature-line { border-top: 1px dashed #9ca3af; height: 26px; margin-bottom: 6px; }
        .signature-label { font-size: 12px; font-weight: 600; color: #374151; }
        @media print {
            body { background: #fff; }
            .frame { padding: 0; }
            .toolbar { display: none; }
            .sheet { max-width: none; width: 100%; margin: 0; padding: 8mm; min-height: 0; }
            .print-repeat-table > thead { display: table-header-group !important; }
            .print-repeat-table > tfoot { display: table-footer-group !important; }
            .print-repeat-table tr { page-break-inside: avoid !important; break-inside: avoid !important; }
            @page { size: A4; margin: 0; }
        }
    </style>
</head>
<body>
@php
    $receiptSerialCount = $goodsReceipt->lines->sum(fn ($line) => $line->serials->count());
@endphp
<div class="toolbar">
    <a class="btn" href="{{ route('goods-receipts.show', $goodsReceipt) }}">Back to GRN</a>
    @if ($receiptSerialCount > 0)
        <a class="btn" href="{{ route('goods-receipts.labels', $goodsReceipt) }}" target="_blank" rel="noopener noreferrer">Product labels</a>
    @endif
    <button class="btn" type="button" onclick="window.print()">Print</button>
</div>

<div class="frame">
    <div class="sheet">
        @php $currency = 'Tk'; @endphp
        <table class="print-repeat-table">
            <thead>
                <tr>
                    <th colspan="4" class="print-head-cell" scope="colgroup">
                        <div class="top">
                            <div>
                                <div class="heading-small">Goods Receipt Note (GRN)</div>
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
                                    <div class="k">GRN: {{ $goodsReceipt->grn_number }}</div>
                                    <div class="k">Printed: {{ $printedAt->format('Y-m-d H:i') }}</div>
                                    <div class="k">Received: {{ optional($goodsReceipt->received_at)->format('Y-m-d') }}</div>
                                    <div class="k">Delivery note: {{ $goodsReceipt->delivery_note_ref ?: '—' }}</div>
                                    @if ($goodsReceipt->posted_at)
                                        <div class="k">Posted: {{ $goodsReceipt->posted_at->format('Y-m-d H:i') }}</div>
                                    @endif
                                    <div class="status-stamp">{{ strtoupper($goodsReceipt->status) }}</div>
                                </div>
                            </div>
                        </div>
                        <div class="bill-grid">
                            <div>
                                <div class="bill-title">Supplier</div>
                                <div><b>{{ $goodsReceipt->supplier?->name }}</b></div>
                                <div>Contact: {{ $goodsReceipt->supplier?->contact_person ?: '—' }}</div>
                                <div>Phone: {{ $goodsReceipt->supplier?->phone ?: '—' }}</div>
                                <div>Email: {{ $goodsReceipt->supplier?->email ?: '—' }}</div>
                                <div>Tax ID: {{ $goodsReceipt->supplier?->tax_number ?: '—' }}</div>
                                <div>Address: {{ $goodsReceipt->supplier?->address ?: '—' }}</div>
                            </div>
                            <div>
                                <div class="bill-title">Receive at</div>
                                <div><b>{{ $goodsReceipt->location?->name }}</b></div>
                                @if ($goodsReceipt->purchaseOrder)
                                    <div class="k" style="margin-top:8px;">PO: {{ $goodsReceipt->purchaseOrder->po_number }}</div>
                                @endif
                            </div>
                        </div>
                    </th>
                </tr>
                <tr class="items-head">
                    <th>Description</th>
                    <th class="right">Qty</th>
                    <th class="right">Unit cost</th>
                    <th class="right">Line total</th>
                </tr>
            </thead>
            <tbody class="items-body lines-table">
                @foreach ($goodsReceipt->lines as $line)
                    @php $lineTotal = (int) $line->qty_received * (float) $line->unit_cost; @endphp
                    <tr>
                        <td>{{ $line->product?->sku }} — {{ $line->product?->name }}</td>
                        <td class="right">{{ $line->qty_received }}</td>
                        <td class="right">{{ $currency }} {{ number_format((float) $line->unit_cost, 2) }}</td>
                        <td class="right">{{ $currency }} {{ number_format($lineTotal, 2) }}</td>
                    </tr>
                @endforeach
                <tr>
                    <td colspan="4">
                        <div class="totals-box">
                            <div class="totals-row"><span>Total quantity</span><span>{{ $totalQty }}</span></div>
                            <div class="balance-row"><span>Total value</span><span>{{ $currency }} {{ number_format($totalValue, 2) }}</span></div>
                        </div>
                    </td>
                </tr>
            </tbody>
            <tfoot>
                <tr>
                    <th colspan="4" class="print-foot-cell" scope="colgroup">
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
