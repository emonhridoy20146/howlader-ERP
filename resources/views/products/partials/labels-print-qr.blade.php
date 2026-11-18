{{-- QR thermal labels: 25 mm wide × 38 mm tall (portrait). --}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="qr-labels-doc">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Product labels</title>
    <style>
        * { box-sizing: border-box; }

        @page {
            size: 25mm 38mm;
            margin: 0;
        }

        body {
            margin: 0;
            padding: 0.5rem;
            font-family: ui-sans-serif, system-ui, sans-serif;
            font-size: 10px;
            color: #0f172a;
            background: #f1f5f9;
        }

        .toolbar {
            margin-bottom: 0.5rem;
            display: flex;
            flex-wrap: wrap;
            gap: 0.35rem;
            align-items: center;
        }
        .toolbar button,
        .toolbar a.btn {
            border-radius: 0.35rem;
            border: 1px solid #cbd5e1;
            background: #0f172a;
            color: #fff;
            padding: 0.35rem 0.65rem;
            font-size: 0.7rem;
            cursor: pointer;
            text-decoration: none;
        }
        .toolbar button.secondary,
        .toolbar a.btn.secondary {
            background: #fff;
            color: #334155;
        }
        .toolbar .format-active {
            background: #334155;
            pointer-events: none;
        }
        .toolbar select {
            max-width: 14rem;
            border-radius: 0.35rem;
            border: 1px solid #cbd5e1;
            background: #fff;
            color: #334155;
            padding: 0.3rem 0.45rem;
            font-size: 0.7rem;
        }
        .empty {
            padding: 1.25rem;
            text-align: center;
            color: #64748b;
            font-size: 0.75rem;
        }

        .sheet {
            display: grid;
            grid-template-columns: repeat(auto-fill, 25mm);
            gap: 0.5rem;
            justify-content: start;
        }

        .qr-label {
            width: 25mm;
            height: 38mm;
            border: 1px solid #e2e8f0;
            border-radius: 0.2rem;
            background: #fff;
            display: flex;
            flex-direction: column;
            padding: 0.35mm 0.45mm;
            overflow: hidden;
        }

        .qr-label__code {
            flex: 1 1 auto;
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 0;
        }

        .qr-label__code svg {
            display: block;
            width: 21mm;
            height: 21mm;
        }

        .qr-label__caption {
            flex: 0 0 auto;
            text-align: center;
            padding-top: 0.35mm;
            border-top: 1px solid #f1f5f9;
            line-height: 1.12;
        }

        .qr-label__name {
            font-size: 7pt;
            font-weight: 700;
            color: #334155;
            overflow: hidden;
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
            word-break: break-word;
        }

        .qr-label__serial {
            margin-top: 0.2mm;
            font-size: 7pt;
            font-weight: 700;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        @media print {
            body {
                margin: 0;
                padding: 0;
                background: #fff;
                width: 25mm;
            }

            .no-print { display: none !important; }

            .sheet {
                display: flex;
                flex-direction: column;
                gap: 0;
                width: 25mm;
            }

            .qr-label {
                flex: 0 0 38mm;
                width: 25mm;
                height: 38mm;
                border: none;
                border-radius: 0;
                page-break-after: always;
                break-after: page;
            }

            .qr-label:last-child {
                page-break-after: auto;
                break-after: auto;
            }

            .qr-label__code svg {
                width: 21.5mm;
                height: 21.5mm;
            }
        }
    </style>
</head>
<body>
    <div class="toolbar no-print">
        @if (! empty($labelsBackUrl))
            <a href="{{ $labelsBackUrl }}" class="btn secondary">Back to GRN</a>
        @endif
        @if (! empty($labelsFormatUrls))
            <a href="{{ $labelsFormatUrls['qr'] ?? '#' }}" class="btn secondary format-active">QR labels</a>
            <a href="{{ $labelsFormatUrls['barcode'] ?? '#' }}" class="btn secondary">Barcode labels</a>
        @endif
        @include('products.partials.labels-print-branch-filter')
        <button type="button" onclick="window.print()">Print</button>
        <button type="button" class="secondary" onclick="window.close()">Close</button>
        <span style="font-size: 0.65rem; color: #64748b;">{{ $labelToolbarNote ?? ($serials->count().' label(s)') }}</span>
    </div>

    @if ($serials->isEmpty())
        <p class="empty">{{ $labelEmptyMessage ?? 'No in-stock serial units for the selected products.' }}</p>
    @else
        <div class="sheet">
            @foreach ($serials as $item)
                @php
                    $product = $item->product;
                    $code = $codeSvgs[$item->id] ?? null;
                    $captionName = \Illuminate\Support\Str::limit((string) ($product?->name ?? 'Product'), 36);
                    $labelCodeDisplay = $item->label_code
                        ? \App\Support\SerialLabelCode::format((int) $item->label_code)
                        : '';
                @endphp
                <article class="qr-label">
                    <div class="qr-label__code">
                        @if (is_string($code) && $code !== '')
                            {!! $code !!}
                        @else
                            <span style="font-size: 5pt; color: #94a3b8;">No code</span>
                        @endif
                    </div>
                    <div class="qr-label__caption">
                        <div class="qr-label__name" title="{{ $product?->name ?? '' }}">{{ $captionName }}</div>
                        <div class="qr-label__serial" title="{{ $item->serial_number }}">
                            {{ $labelCodeDisplay !== '' ? $labelCodeDisplay : $item->serial_number }}
                        </div>
                    </div>
                </article>
            @endforeach
        </div>
    @endif
</body>
</html>
