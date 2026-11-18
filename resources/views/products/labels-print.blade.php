@php
    $labelFormat = $labelFormat ?? \App\Support\SerialLabelBarcode::FORMAT_QR;
    $isBarcode = $labelFormat === \App\Support\SerialLabelBarcode::FORMAT_BARCODE;
@endphp

@if ($isBarcode)
    @include('products.partials.labels-print-barcode', [
        'serials' => $serials,
        'codeSvgs' => $codeSvgs,
        'labelsBackUrl' => $labelsBackUrl ?? null,
        'labelsFormatUrls' => $labelsFormatUrls ?? [],
        'labelToolbarNote' => $labelToolbarNote ?? null,
        'labelEmptyMessage' => $labelEmptyMessage ?? null,
        'labelFilterLocations' => $labelFilterLocations ?? collect(),
        'labelFilterProducts' => $labelFilterProducts ?? collect(),
        'labelLocationId' => $labelLocationId ?? null,
        'labelProductId' => $labelProductId ?? null,
        'labelProductIds' => $labelProductIds ?? [],
        'labelProductSearch' => $labelProductSearch ?? '',
        'labelFormat' => $labelFormat,
    ])
@else
    @include('products.partials.labels-print-qr', [
        'serials' => $serials,
        'codeSvgs' => $codeSvgs,
        'labelsBackUrl' => $labelsBackUrl ?? null,
        'labelsFormatUrls' => $labelsFormatUrls ?? [],
        'labelToolbarNote' => $labelToolbarNote ?? null,
        'labelEmptyMessage' => $labelEmptyMessage ?? null,
        'labelFilterLocations' => $labelFilterLocations ?? collect(),
        'labelFilterProducts' => $labelFilterProducts ?? collect(),
        'labelLocationId' => $labelLocationId ?? null,
        'labelProductId' => $labelProductId ?? null,
        'labelProductIds' => $labelProductIds ?? [],
        'labelProductSearch' => $labelProductSearch ?? '',
        'labelFormat' => $labelFormat,
    ])
@endif
