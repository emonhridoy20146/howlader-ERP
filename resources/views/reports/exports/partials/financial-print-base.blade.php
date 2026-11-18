@php
    $financialPrintOrientation = $financialPrintOrientation ?? 'portrait';
@endphp
<style>
    * { box-sizing: border-box; }
    body {
        margin: 0;
        font-family: 'Noto Sans Bengali', 'DejaVu Sans', ui-sans-serif, system-ui, sans-serif;
        color: #111;
        background: #e5e7eb;
    }
    .financial-print-toolbar {
        max-width: {{ $financialPrintOrientation === 'landscape' ? '1080px' : '760px' }};
        margin: 0 auto 10px;
        padding: 0 16px;
        display: flex;
        justify-content: flex-end;
        gap: 8px;
    }
    .financial-print-btn {
        border: 1px solid #cbd5e1;
        background: #fff;
        color: #334155;
        border-radius: 8px;
        font-size: 12px;
        padding: 8px 12px;
        cursor: pointer;
        font-weight: 600;
        text-decoration: none;
    }
    .financial-print-sheet {
        max-width: {{ $financialPrintOrientation === 'landscape' ? '1080px' : '760px' }};
        margin: 0 auto;
        background: #fff;
        border: 1px solid #9ca3af;
        padding: 18px 22px 24px;
    }
    .financial-print-header {
        break-inside: avoid;
        page-break-inside: avoid;
    }
    table.financial-data {
        width: 100%;
        border-collapse: collapse;
        table-layout: fixed;
    }
    table.financial-data th,
    table.financial-data td {
        border: 1px solid #111;
        padding: 5px 6px;
        vertical-align: top;
        word-wrap: break-word;
        overflow-wrap: anywhere;
    }
    table.financial-data th {
        background: #f3f4f6;
        font-weight: 700;
        text-align: center;
    }
    table.financial-data td.num,
    table.financial-data th.num {
        text-align: right;
        font-variant-numeric: tabular-nums;
        white-space: nowrap;
    }
    table.financial-data td.sl,
    table.financial-data th.sl {
        width: 32px;
        text-align: center;
    }
    table.financial-data tr.data-row {
        break-inside: avoid;
        page-break-inside: avoid;
    }
    table.financial-data tr.total-row td,
    table.financial-data tr.summary-row td {
        font-weight: 700;
        background: #f3f4f6;
        break-inside: avoid;
        page-break-inside: avoid;
    }
    table.financial-data tr.section-row td {
        font-weight: 700;
        background: #f9fafb;
        break-inside: avoid;
        page-break-inside: avoid;
    }
    .financial-table-block {
        margin-top: 12px;
        break-inside: auto;
        page-break-inside: auto;
    }
    .financial-table-block + .financial-table-block {
        margin-top: 16px;
    }
    .financial-print-footer {
        display: flex;
        justify-content: space-between;
        margin-top: 18px;
        font-size: 10px;
        color: #4b5563;
        break-inside: avoid;
        page-break-inside: avoid;
    }
    .financial-signatures {
        display: grid;
        grid-template-columns: repeat(3, 1fr);
        gap: 16px;
        margin-top: 24px;
        font-size: 10px;
        text-align: center;
        break-inside: avoid;
        page-break-inside: avoid;
    }
    .financial-sign-line {
        border-top: 1px solid #111;
        margin-top: 36px;
        padding-top: 4px;
    }
    @media print {
        body {
            background: #fff !important;
            -webkit-print-color-adjust: exact;
            print-color-adjust: exact;
        }
        .financial-print-toolbar { display: none !important; }
        .financial-print-sheet {
            max-width: none;
            width: 100%;
            border: none;
            margin: 0;
            padding: 0;
        }
        table.financial-data thead {
            display: table-header-group;
        }
        table.financial-data tfoot {
            display: table-footer-group;
        }
        table.financial-data tr.data-row {
            break-inside: avoid;
            page-break-inside: avoid;
        }
        table.financial-data tr.data-row td {
            break-inside: avoid;
            page-break-inside: avoid;
        }
        .financial-table-block {
            break-inside: auto;
            page-break-inside: auto;
        }
        @page {
            size: A4 {{ $financialPrintOrientation }};
            margin: 12mm 12mm 18mm 12mm;
            @bottom-center {
                content: "Page " counter(page) " of " counter(pages);
                font-family: 'Noto Sans Bengali', 'DejaVu Sans', ui-sans-serif, system-ui, sans-serif;
                font-size: 9px;
                color: #64748b;
            }
        }
    }
</style>
