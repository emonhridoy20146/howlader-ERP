{{-- Barcode thermal labels: 38 mm wide × 25 mm tall (landscape). One label per printed page. --}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="barcode-labels-doc">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Barcode labels</title>
    <style>
        :root {
            --label-w: 38mm;
            --label-h: 25mm;
            --pad-x: 0.75mm;
            --pad-y: 0.5mm;
            --gap-text-bar: 1mm;
            --gap-bar-code: 0.6mm;
            --bar-max-h: 11mm;
            --bar-max-w: 36.5mm;
            --font-name: 8.5pt;
            --font-price: 7.5pt;
            --font-code: 7.5pt;
        }

        * { box-sizing: border-box; margin: 0; padding: 0; }

        @page {
            size: var(--label-w) var(--label-h);
            margin: 0;
        }

        body {
            font-family: "Times New Roman", Times, Georgia, serif;
            color: #000;
            background: #e2e8f0;
            padding: 0.5rem;
        }

        .toolbar {
            margin-bottom: 0.5rem;
            display: flex;
            flex-wrap: wrap;
            gap: 0.35rem;
            align-items: center;
            font-family: ui-sans-serif, system-ui, sans-serif;
            font-size: 0.7rem;
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
        .toolbar-hint { color: #64748b; font-size: 0.65rem; }
        .toolbar select {
            font-family: ui-sans-serif, system-ui, sans-serif;
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
            font-family: ui-sans-serif, system-ui, sans-serif;
        }

        /* Preview grid (screen only) */
        .sheet {
            display: flex;
            flex-wrap: wrap;
            gap: 0.5rem;
        }

        .thermal-label {
            width: var(--label-w);
            height: var(--label-h);
            background: #fff;
            border: 1px solid #cbd5e1;
            border-radius: 0.15rem;
            padding: var(--pad-y) var(--pad-x);
            display: flex;
            flex-direction: column;
            align-items: stretch;
            overflow: hidden;
        }

        .thermal-label__head {
            flex: 0 0 auto;
            text-align: center;
            line-height: 1.08;
        }

        .thermal-label__name {
            font-size: var(--font-name);
            font-weight: 400;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .thermal-label__price {
            margin-top: 0.15mm;
            font-size: var(--font-price);
            font-weight: 400;
            white-space: nowrap;
        }

        .thermal-label__bars {
            flex: 0 1 auto;
            margin-top: var(--gap-text-bar);
            display: flex;
            align-items: center;
            justify-content: center;
            max-height: var(--bar-max-h);
        }

        .thermal-label__bars img {
            display: block;
            max-width: var(--bar-max-w);
            max-height: var(--bar-max-h);
            width: auto;
            height: auto;
            object-fit: contain;
            image-rendering: pixelated;
            image-rendering: crisp-edges;
            -webkit-print-color-adjust: exact;
            print-color-adjust: exact;
        }

        .thermal-label__code {
            flex: 0 0 auto;
            flex-shrink: 0;
            margin-top: var(--gap-bar-code);
            padding-bottom: 0.15mm;
            text-align: center;
            font-size: var(--font-code);
            letter-spacing: 0.04em;
            line-height: 1.15;
            white-space: nowrap;
        }

        .thermal-label__missing {
            font-size: 6pt;
            color: #94a3b8;
            text-align: center;
        }

        @media print {
            body {
                background: #fff;
                padding: 0;
                width: var(--label-w);
            }

            .no-print { display: none !important; }

            .sheet {
                display: block;
                width: var(--label-w);
            }

            .thermal-label {
                border: none;
                border-radius: 0;
                width: var(--label-w);
                height: var(--label-h);
                page-break-after: always;
                break-after: page;
            }

            .thermal-label:last-child {
                page-break-after: auto;
                break-after: auto;
            }

            /* Keep same sizes as screen — do not enlarge on print. */
        }
    </style>
</head>
<body>
    <div class="toolbar no-print">
        @if (! empty($labelsBackUrl))
            <a href="{{ $labelsBackUrl }}" class="btn secondary">Back to GRN</a>
        @endif
        @if (! empty($labelsFormatUrls))
            <a href="{{ $labelsFormatUrls['qr'] ?? '#' }}" class="btn secondary">QR labels</a>
            <a href="{{ $labelsFormatUrls['barcode'] ?? '#' }}" class="btn secondary format-active">Barcode labels</a>
        @endif
        @include('products.partials.labels-print-branch-filter')
        <button type="button" onclick="printBarcodeLabels()">Print</button>
        <button type="button" class="secondary" onclick="window.close()">Close</button>
        <span class="toolbar-hint">{{ $labelToolbarNote ?? ($serials->count().' label(s)') }}</span>
        <span class="toolbar-hint">Paper <strong>38×25 mm</strong> · scale <strong>100%</strong> · margins <strong>none</strong></span>
    </div>

    @if ($serials->isEmpty())
        <p class="empty">{{ $labelEmptyMessage ?? 'No in-stock serial units for the selected products.' }}</p>
    @else
        <div class="sheet">
            @foreach ($serials as $item)
                @php
                    $product = $item->product;
                    $barcode = $codeSvgs[$item->id] ?? null;
                    $productName = \Illuminate\Support\Str::limit((string) ($product?->name ?? 'Product'), 32);
                    $mrp = $product?->default_sale_price;
                    $scanCode = \App\Support\SerialLabelBarcode::linearScanPayload($item);
                    $barWmm = is_array($barcode) ? min(36.5, (float) ($barcode['width_mm'] ?? 36.5)) : 0;
                    $barHmm = is_array($barcode) ? min(11, (float) ($barcode['height_mm'] ?? 11)) : 0;
                @endphp
                <section class="thermal-label">
                    <header class="thermal-label__head">
                        <div class="thermal-label__name" title="{{ $product?->name ?? '' }}">{{ $productName }}</div>
                        @if ($mrp !== null && $mrp !== '')
                            <div class="thermal-label__price">Price: MRP {{ number_format((float) $mrp, 2, '.', '') }}</div>
                        @endif
                    </header>

                    <div class="thermal-label__bars">
                        @if (is_array($barcode) && ! empty($barcode['image_url']))
                            <img
                                class="label-barcode-img"
                                src="{{ url(route('serial-items.label-barcode', ['serial_item' => $item->id])) }}"
                                alt=""
                                width="{{ (int) ($barcode['width_px'] ?? 0) }}"
                                height="{{ (int) ($barcode['height_px'] ?? 0) }}"
                                @if ($barWmm > 0 && $barHmm > 0)
                                    style="width: {{ $barWmm }}mm; height: {{ $barHmm }}mm; max-height: var(--bar-max-h);"
                                @endif
                                loading="eager"
                                decoding="sync"
                            >
                        @else
                            <span class="thermal-label__missing">No barcode</span>
                        @endif
                    </div>

                    @if ($scanCode !== '')
                        <footer class="thermal-label__code">{{ $scanCode }}</footer>
                    @endif
                </section>
            @endforeach
        </div>
    @endif

    <script>
        function printBarcodeLabels() {
            const images = Array.from(document.querySelectorAll('.label-barcode-img'));
            if (images.length === 0) {
                window.print();
                return;
            }
            Promise.all(images.map(function (img) {
                if (img.complete && img.naturalWidth > 0) {
                    return Promise.resolve();
                }
                return new Promise(function (resolve) {
                    img.addEventListener('load', resolve, { once: true });
                    img.addEventListener('error', resolve, { once: true });
                });
            })).then(function () {
                window.print();
            });
        }
    </script>
</body>
</html>
