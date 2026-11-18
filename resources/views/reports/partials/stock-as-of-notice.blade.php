@props(['filters'])
@php
    $asOfLabel = app(\App\Services\ReportDataService::class)->stockAsOfDisplayLabel($filters);
@endphp
@if ($asOfLabel !== null)
    <div class="rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-950">
        Showing stock as of <strong>{{ $asOfLabel }}</strong>
        (ledger snapshot). Reserved/on-hold quantities reflect current data only when no date is selected.
    </div>
@endif
