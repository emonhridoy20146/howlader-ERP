@props(['filters'])
@php
    $collectorOptions = ($collectors ?? app(\App\Services\ReportDataService::class)->paymentCollectorOptions())
        ->map(fn ($u) => ['value' => (string) $u->id, 'label' => $u->displayName()])
        ->prepend(['value' => 'unknown', 'label' => 'Unknown (no collector recorded)'])
        ->values()->all();
@endphp
<div class="sm:col-span-2">
    <label class="mb-1 block text-xs font-medium text-slate-600">Collected by</label>
    <x-searchable-select name="collector_id" :options="$collectorOptions" :selected="$filters['collector_id'] ?? ''" placeholder="All collectors" />
</div>
