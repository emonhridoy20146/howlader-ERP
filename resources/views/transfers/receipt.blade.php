<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Transfer receipt {{ $transfer->transfer_number }}</title>
    <style>
        * { box-sizing: border-box; }
        body { margin: 0; font-family: "Segoe UI", Arial, sans-serif; background: #2f3a45; color: #1f2937; font-size: 11px; line-height: 1.3; }
        .frame { padding: 12px; }
        .toolbar { max-width: 210mm; margin: 0 auto 8px; display: flex; justify-content: flex-end; gap: 8px; }
        .btn { border: 1px solid #cbd5e1; background: #fff; color: #334155; border-radius: 6px; font-size: 12px; padding: 7px 11px; text-decoration: none; cursor: pointer; }
        .half-sheet {
            max-width: 210mm;
            margin: 0 auto;
            background: #fff;
            padding: 10px 12px;
            border: 1px dashed #cbd5e1;
        }
        .header { display: flex; justify-content: space-between; align-items: flex-start; gap: 8px; }
        .heading-small { font-size: 10px; letter-spacing: .08em; text-transform: uppercase; color: #4b5563; margin-bottom: 2px; }
        .brand { font-size: 18px; line-height: 1.1; font-weight: 700; color: #1f2937; }
        .company-line { margin-top: 3px; font-size: 9px; color: #64748b; line-height: 1.35; }
        .logo { width: 64px; max-height: 40px; object-fit: contain; margin-left: auto; }
        .meta { text-align: right; font-size: 10px; line-height: 1.35; color: #475569; }
        .meta strong { color: #111827; }
        .status-stamp { display: inline-block; border: 1px solid #111827; color: #111827; padding: 1px 5px; font-size: 9px; font-weight: 700; letter-spacing: .05em; text-transform: uppercase; margin-top: 3px; }
        .route-bar {
            display: flex;
            align-items: center;
            gap: 8px;
            margin-top: 8px;
            padding: 6px 8px;
            border: 1px solid #cbd5e1;
            border-radius: 6px;
            background: #f8fafc;
            font-size: 10px;
        }
        .route-bar .loc { flex: 1; min-width: 0; }
        .route-bar .loc-label { font-size: 8px; font-weight: 700; text-transform: uppercase; color: #64748b; letter-spacing: .04em; }
        .route-bar .loc-name { font-weight: 700; color: #111827; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .route-arrow { flex-shrink: 0; color: #64748b; font-size: 14px; font-weight: 700; }
        .meta-grid {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 6px;
            margin-top: 8px;
            font-size: 9px;
            color: #475569;
        }
        .meta-grid span { display: block; font-size: 8px; text-transform: uppercase; letter-spacing: .04em; color: #64748b; }
        .meta-grid strong { display: block; margin-top: 1px; color: #111827; font-size: 9px; font-weight: 600; }
        .reason { margin-top: 6px; font-size: 9px; color: #475569; }
        .reason strong { color: #374151; }
        table { width: 100%; border-collapse: collapse; }
        .lines { margin-top: 8px; }
        .lines th {
            text-align: left;
            padding: 3px 2px;
            font-size: 8px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .03em;
            color: #475569;
            border-bottom: 1px solid #94a3b8;
        }
        .lines th.right, .lines td.right { text-align: right; }
        .lines td {
            padding: 3px 2px;
            font-size: 10px;
            color: #374151;
            vertical-align: top;
            border-bottom: 1px solid #e2e8f0;
        }
        .lines .sku { color: #64748b; font-size: 9px; }
        .mono { font-family: ui-monospace, monospace; font-size: 9px; }
        .section-label { margin-top: 8px; font-size: 8px; font-weight: 700; text-transform: uppercase; letter-spacing: .05em; color: #64748b; }
        .empty { padding: 6px 0; color: #94a3b8; font-size: 9px; }
        .footer { margin-top: 10px; padding-top: 8px; border-top: 1px solid #94a3b8; }
        .footer-note { font-size: 9px; color: #475569; margin: 0 0 8px; line-height: 1.35; }
        .signatures { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-top: 12px; }
        .signature-line { border-top: 1px dashed #94a3b8; height: 14px; margin-bottom: 2px; }
        .signature-label { font-size: 9px; font-weight: 600; color: #374151; }
        @media print {
            body { background: #fff; font-size: 10px; }
            .frame { padding: 0; }
            .toolbar { display: none; }
            .half-sheet {
                max-width: none;
                width: 100%;
                margin: 0;
                padding: 4mm 5mm;
                border: none;
                max-height: 138mm;
                overflow: hidden;
            }
            .lines td { font-size: 9px; }
            .lines th { font-size: 7px; }
            @page { size: A4 portrait; margin: 8mm; }
        }
    </style>
</head>
<body>
<div class="toolbar">
    <a class="btn" href="{{ route('transfers.show', $transfer) }}">Back to transfer</a>
    <button class="btn" type="button" onclick="window.print()">Print</button>
</div>

<div class="frame">
    <div class="half-sheet">
        <div class="header">
            <div style="min-width:0; flex:1;">
                <div class="heading-small">Stock transfer receipt</div>
                <div class="brand">{{ $companyPad['company_pad_name'] }}</div>
                <div class="company-line">
                    @if ($companyPad['company_pad_address']) {{ $companyPad['company_pad_address'] }} @endif
                    @if ($companyPad['company_pad_phone']) · {{ $companyPad['company_pad_phone'] }} @endif
                </div>
            </div>
            <div>
                @if ($companyPad['company_pad_logo_path'] !== '')
                    <img class="logo" src="{{ asset('storage/'.$companyPad['company_pad_logo_path']) }}" alt="Company logo">
                @endif
                <div class="meta">
                    <div><strong class="mono">{{ $transfer->transfer_number }}</strong></div>
                    <div>{{ $printedAt->format('Y-m-d H:i') }}</div>
                    <div class="status-stamp">{{ strtoupper($transfer->status) }}</div>
                </div>
            </div>
        </div>

        <div class="route-bar">
            <div class="loc">
                <div class="loc-label">From</div>
                <div class="loc-name">{{ $transfer->sourceLocation?->name ?: '—' }}</div>
            </div>
            <div class="route-arrow" aria-hidden="true">→</div>
            <div class="loc">
                <div class="loc-label">To</div>
                <div class="loc-name">{{ $transfer->destinationLocation?->name ?: '—' }}</div>
            </div>
        </div>

        <div class="meta-grid">
            <div><span>Requested</span><strong>{{ $transfer->requested_at?->format('Y-m-d H:i') ?? '—' }}</strong></div>
            <div><span>Approved</span><strong>{{ $transfer->approved_at?->format('Y-m-d H:i') ?? '—' }}</strong></div>
            <div><span>Issued</span><strong>{{ $transfer->issued_at?->format('Y-m-d H:i') ?? '—' }}</strong></div>
            <div><span>Received</span><strong>{{ $transfer->received_at?->format('Y-m-d H:i') ?? '—' }}</strong></div>
        </div>

        @if ($transfer->reason)
            <div class="reason"><strong>Reason:</strong> {{ $transfer->reason }}</div>
        @endif

        <table class="lines">
            <thead>
                <tr>
                    <th>Product</th>
                    <th class="right">Req</th>
                    <th class="right">Iss</th>
                    <th class="right">Rcv</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($transfer->lines as $line)
                    <tr>
                        <td>
                            {{ $line->product?->name ?? '—' }}
                            @if ($line->product?->sku)
                                <span class="sku">({{ $line->product->sku }})</span>
                            @endif
                        </td>
                        <td class="right mono">{{ $line->qty_requested }}</td>
                        <td class="right mono">{{ $line->qty_issued }}</td>
                        <td class="right mono">{{ $line->qty_received }}</td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="empty">No lines.</td></tr>
                @endforelse
            </tbody>
        </table>

        @if ($transfer->transferSerials->isNotEmpty())
            <div class="section-label">Serial numbers</div>
            <table class="lines">
                <thead>
                    <tr>
                        <th>Serial</th>
                        <th>Product</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($transfer->transferSerials as $row)
                        <tr>
                            <td class="mono">{{ $row->serialItem?->serial_number ?: $row->serialItem?->manufacturer_serial ?: '—' }}</td>
                            <td>{{ $row->product?->name ?? '—' }}</td>
                            <td>{{ ucfirst($row->status) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif

        <div class="footer">
            @if ($companyPad['company_pad_footer_note'])
                <p class="footer-note">{{ $companyPad['company_pad_footer_note'] }}</p>
            @endif
            <div class="signatures">
                <div>
                    <div class="signature-line"></div>
                    <div class="signature-label">{{ $companyPad['company_pad_footer_left_label'] ?: 'Issued by' }}</div>
                </div>
                <div>
                    <div class="signature-line"></div>
                    <div class="signature-label">{{ $companyPad['company_pad_footer_right_label'] ?: 'Received by' }}</div>
                </div>
            </div>
        </div>
    </div>
</div>
</body>
</html>
