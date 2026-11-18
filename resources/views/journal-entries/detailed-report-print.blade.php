<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Detailed journal report — {{ config('app.name') }}</title>
    <style>
        * { box-sizing: border-box; }
        body {
            margin: 0;
            font-family: ui-sans-serif, system-ui, "Segoe UI", Arial, sans-serif;
            background: #334155;
            color: #1e293b;
        }
        .toolbar {
            max-width: 1100px;
            margin: 0 auto 10px;
            padding: 0 16px;
            display: flex;
            justify-content: flex-end;
            gap: 8px;
        }
        .btn {
            border: 1px solid #cbd5e1;
            background: #fff;
            color: #334155;
            border-radius: 8px;
            font-size: 13px;
            padding: 8px 14px;
            text-decoration: none;
            cursor: pointer;
            font-weight: 500;
        }
        .frame { padding: 16px; }
        .sheet {
            max-width: 1100px;
            margin: 0 auto;
            background: #fff;
            padding: 28px 28px 32px;
            border-radius: 8px;
            box-shadow: 0 1px 3px rgba(0,0,0,.08);
        }
        .heading-small {
            font-size: 12px;
            letter-spacing: .12em;
            text-transform: uppercase;
            color: #64748b;
            font-weight: 600;
            margin-bottom: 6px;
        }
        .title {
            font-size: 26px;
            font-weight: 700;
            color: #0f172a;
            margin: 0 0 4px;
        }
        .tagline { font-size: 14px; color: #475569; margin-bottom: 16px; }
        .company-block {
            font-size: 13px;
            line-height: 1.5;
            color: #475569;
            margin-bottom: 18px;
        }
        .meta-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
            gap: 10px 20px;
            font-size: 13px;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            padding: 12px 14px;
            margin-bottom: 20px;
            background: #f8fafc;
        }
        .meta-grid .k { color: #64748b; font-size: 11px; text-transform: uppercase; letter-spacing: .06em; font-weight: 600; }
        .meta-grid .v { color: #0f172a; margin-top: 2px; font-weight: 500; }
        table { width: 100%; border-collapse: collapse; }
        .lines-head th {
            text-align: left;
            padding: 10px 8px;
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .04em;
            color: #475569;
            background: #f1f5f9;
            border-bottom: 1px solid #cbd5e1;
        }
        .lines-head th.num { text-align: right; }
        .lines-body td {
            padding: 10px 8px;
            font-size: 13px;
            vertical-align: top;
            border-bottom: 1px solid #e2e8f0;
        }
        .lines-body tr { page-break-inside: avoid; }
        .acct-code {
            font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
            font-size: 11px;
            color: #64748b;
            display: block;
            line-height: 1.3;
        }
        .acct-name {
            font-weight: 700;
            color: #0f172a;
            display: block;
            margin-top: 4px;
            line-height: 1.35;
        }
        .mono-num {
            font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
            font-variant-numeric: tabular-nums;
            text-align: right;
            white-space: nowrap;
        }
        .doc { font-family: ui-monospace, Menlo, Monaco, Consolas, monospace; font-size: 12px; color: #334155; }
        .muted { color: #64748b; font-size: 12px; }
        .tfoot td {
            padding: 12px 8px;
            font-size: 13px;
            font-weight: 700;
            background: #f1f5f9;
            border-top: 1px solid #cbd5e1;
            border-bottom: 1px solid #cbd5e1;
        }
        .tfoot .mono-num { font-size: 14px; }
        .footer-note {
            margin-top: 18px;
            font-size: 12px;
            color: #64748b;
            text-align: center;
        }
        @media print {
            body { background: #fff; }
            .frame { padding: 0; }
            .toolbar { display: none !important; }
            .sheet {
                max-width: none;
                width: 100%;
                margin: 0;
                padding: 10mm 12mm;
                border-radius: 0;
                box-shadow: none;
            }
            @page {
                size: A4 landscape;
                margin: 10mm 10mm 18mm 10mm;
                @bottom-center {
                    content: "Page " counter(page) " of " counter(pages);
                    font-family: ui-sans-serif, system-ui, "Segoe UI", Arial, sans-serif;
                    font-size: 9px;
                    color: #64748b;
                }
            }
        }
    </style>
</head>
<body>
<div class="toolbar">
    <a class="btn" href="{{ route('journal-entries.index', request()->query()) }}">Back to journal</a>
    <button class="btn" type="button" onclick="window.print()">Print</button>
</div>

<div class="frame">
    <div class="sheet">
        <div class="heading-small">General ledger</div>
        <h1 class="title">Detailed journal report</h1>
        <p class="tagline">{{ $companyPad['company_pad_tagline'] }}</p>
        <div class="company-block">
            <strong>{{ $companyPad['company_pad_name'] }}</strong><br>
            @if ($companyPad['company_pad_address'] !== ''){{ $companyPad['company_pad_address'] }}<br>@endif
            @if ($companyPad['company_pad_phone'] !== ''){{ $companyPad['company_pad_phone'] }} @endif
            @if ($companyPad['company_pad_email'] !== ''){{ $companyPad['company_pad_email'] }}@endif
        </div>

        <div class="meta-grid">
            <div>
                <div class="k">Period</div>
                <div class="v">{{ $filterLabels['period'] }}</div>
            </div>
            <div>
                <div class="k">Branch</div>
                <div class="v">{{ $filterLabels['branch'] }}</div>
            </div>
            <div>
                <div class="k">Source</div>
                <div class="v">{{ $filterLabels['source'] }}</div>
            </div>
            @if (! empty($filterLabels['wallet']))
            <div>
                <div class="k">Wallet</div>
                <div class="v">{{ $filterLabels['wallet'] }}</div>
            </div>
            @endif
            <div>
                <div class="k">Printed</div>
                <div class="v">{{ $printedAt->format('Y-m-d H:i') }}</div>
            </div>
            <div>
                <div class="k">Lines in report</div>
                <div class="v">{{ number_format($totals['line_count'], 0) }}</div>
            </div>
        </div>

        <table>
            <thead class="lines-head">
                <tr>
                    <th class="num" style="width:4%">#</th>
                    <th style="width:9%">Date</th>
                    <th style="width:11%">Document</th>
                    <th style="width:8%">Branch</th>
                    <th style="width:14%">Source</th>
                    <th style="width:24%">Account</th>
                    <th style="width:14%">Memo</th>
                    <th class="num" style="width:10%">Debit</th>
                    <th class="num" style="width:10%">Credit</th>
                </tr>
            </thead>
            <tbody class="lines-body">
                @php
                    $sources = \App\Models\JournalEntry::sources();
                    $fmt = static fn (float $n): string => $n > 0 ? number_format($n, 2) : '—';
                @endphp
                @forelse ($lines as $line)
                    @php
                        $entry = $line->journalEntry;
                        $dr = (float) $line->debit;
                        $cr = (float) $line->credit;
                    @endphp
                    <tr>
                        <td class="num">{{ $loop->iteration }}</td>
                        <td class="muted">{{ $entry?->entry_date?->format('Y-m-d') ?? '—' }}</td>
                        <td class="doc">{{ $entry?->document_no ?? '—' }}</td>
                        <td class="muted">{{ $entry?->branch?->name ?? '—' }}</td>
                        <td class="muted">{{ $sources[$entry?->source ?? ''] ?? ($entry?->source ?? '—') }}</td>
                        <td>
                            <span class="acct-code">{{ $line->ledgerAccount?->code ?? '—' }}</span>
                            <span class="acct-name">{{ $line->ledgerAccount?->name ?? '—' }}</span>
                        </td>
                        <td>{{ $line->line_memo !== null && $line->line_memo !== '' ? $line->line_memo : '' }}</td>
                        <td class="mono-num">{{ $fmt($dr) }}</td>
                        <td class="mono-num">{{ $fmt($cr) }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="9" class="muted" style="text-align:center;padding:28px;">No journal lines match these filters.</td>
                    </tr>
                @endforelse
            </tbody>
            @if ($lines->isNotEmpty())
                <tfoot>
                    <tr class="tfoot">
                        <td colspan="7"><strong>Totals</strong></td>
                        <td class="mono-num">{{ number_format($totals['total_debit'], 2) }}</td>
                        <td class="mono-num">{{ number_format($totals['total_credit'], 2) }}</td>
                    </tr>
                </tfoot>
            @endif
        </table>

        <p class="footer-note">{{ $companyPad['company_pad_footer_note'] }}</p>
    </div>
</div>
@include('reports.exports.partials.print-page-numbers')
</body>
</html>
