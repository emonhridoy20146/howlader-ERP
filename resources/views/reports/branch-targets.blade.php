@extends('layouts.app')
@section('title', 'Branch targets')
@section('page-title', 'Branch targets')
@section('content')
@php
    $exportQuery = array_filter([
        'year' => $filters['year'],
        'month' => $filters['month'],
        'branch_id' => $filters['branch_id'],
        'view' => $filters['view'],
    ], static fn ($v) => $v !== null && $v !== '');
    $colCount = 1 + ($showMonthly ? 4 : 0) + ($showAnnual ? 4 : 0);
@endphp
<div class="space-y-4">
    <p class="flex justify-end">
        <a href="{{ route('reports.index', ['report' => 'branch-targets']) }}" class="text-sm font-medium text-emerald-600 hover:text-emerald-700">← Back to reports</a>
    </p>
    <p class="text-sm text-slate-600">
        Sales and collection target progress by branch. Monthly uses the selected month; annual uses the selected year. Limited to branches you can access.
    </p>

    <form method="get" action="{{ route('reports.branch-targets') }}" class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm ring-1 ring-slate-200">
        @include('reports.partials.branch-targets-filter-fields', ['filters' => $filters, 'filterBranches' => $filterBranches])
        <div class="mt-4 flex flex-wrap items-center gap-2 border-t border-slate-100 pt-3">
            <span class="text-xs font-medium text-slate-500">Export</span>
            <a target="_blank" rel="noopener noreferrer" href="{{ route('reports.print', array_merge(['slug' => 'branch-targets'], $exportQuery)) }}" class="rounded-lg border border-slate-300 bg-white px-3 py-1.5 text-xs font-medium text-slate-800 hover:bg-slate-50">Print</a>
            <a href="{{ route('reports.export', array_merge(['slug' => 'branch-targets', 'format' => 'xlsx'], $exportQuery)) }}" class="rounded-lg border border-slate-300 bg-white px-3 py-1.5 text-xs font-medium text-slate-800 hover:bg-slate-50">Excel</a>
        </div>
        <div class="mt-4 flex flex-wrap gap-2">
            <button type="submit" class="rounded-lg bg-slate-800 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-700">Apply</button>
            <a href="{{ route('reports.branch-targets') }}" class="rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50">Reset</a>
        </div>
    </form>

    <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
        @if ($showMonthly)
            <x-status-card label="This month · sales">
                <span class="text-emerald-800">{{ $kpis['monthly_sales_pct'] !== null ? number_format($kpis['monthly_sales_pct'], 1).'%' : '—' }}</span>
                <p class="mt-1 text-xs font-normal tabular-nums text-slate-500">{{ number_format($kpis['monthly_sales_actual'], 0) }} / {{ number_format($kpis['monthly_sales_target'], 0) }}</p>
            </x-status-card>
            <x-status-card label="This month · collection">
                <span class="text-sky-800">{{ $kpis['monthly_collection_pct'] !== null ? number_format($kpis['monthly_collection_pct'], 1).'%' : '—' }}</span>
                <p class="mt-1 text-xs font-normal tabular-nums text-slate-500">{{ number_format($kpis['monthly_collection_actual'], 0) }} / {{ number_format($kpis['monthly_collection_target'], 0) }}</p>
            </x-status-card>
        @endif
        @if ($showAnnual)
            <x-status-card label="Annual · sales">
                <span class="text-emerald-900">{{ $kpis['annual_sales_pct'] !== null ? number_format($kpis['annual_sales_pct'], 1).'%' : '—' }}</span>
                <p class="mt-1 text-xs font-normal tabular-nums text-slate-500">{{ number_format($kpis['annual_sales_actual'], 0) }} / {{ number_format($kpis['annual_sales_target'], 0) }}</p>
            </x-status-card>
            <x-status-card label="Annual · collection">
                <span class="text-sky-900">{{ $kpis['annual_collection_pct'] !== null ? number_format($kpis['annual_collection_pct'], 1).'%' : '—' }}</span>
                <p class="mt-1 text-xs font-normal tabular-nums text-slate-500">{{ number_format($kpis['annual_collection_actual'], 0) }} / {{ number_format($kpis['annual_collection_target'], 0) }}</p>
            </x-status-card>
        @endif
    </div>

    <div class="overflow-x-auto overflow-y-hidden rounded-2xl bg-white shadow-sm ring-1 ring-slate-200">
        <table class="min-w-full text-sm whitespace-nowrap">
            <thead class="border-b border-slate-200 bg-slate-50 text-left text-xs font-semibold uppercase tracking-wider text-slate-700">
                <tr>
                    <th class="px-4 py-3" rowspan="2">Branch</th>
                    @if ($showMonthly)
                        <th class="px-4 py-3 text-center" colspan="4">{{ $monthLabel }}</th>
                    @endif
                    @if ($showAnnual)
                        <th class="px-4 py-3 text-center" colspan="4">Annual {{ $filters['year'] }}</th>
                    @endif
                </tr>
                <tr>
                    @if ($showMonthly)
                        <th class="px-4 py-3 text-right">Sales %</th>
                        <th class="px-4 py-3 text-right">Sales actual / target</th>
                        <th class="px-4 py-3 text-right">Collection %</th>
                        <th class="px-4 py-3 text-right">Collection actual / target</th>
                    @endif
                    @if ($showAnnual)
                        <th class="px-4 py-3 text-right">Sales %</th>
                        <th class="px-4 py-3 text-right">Sales actual / target</th>
                        <th class="px-4 py-3 text-right">Collection %</th>
                        <th class="px-4 py-3 text-right">Collection actual / target</th>
                    @endif
                </tr>
            </thead>
            <tbody>
                @php $shown = 0; @endphp
                @forelse ($rows as $row)
                    @continue(! $row['has_target'])
                    @php $shown++; $loc = $row['location']; $m = $row['monthly']; $a = $row['annual']; @endphp
                    <tr class="border-b border-slate-100 transition-colors hover:bg-slate-50/70 last:border-0">
                        <td class="px-4 py-3 font-medium text-slate-900">
                            {{ $loc->name }}
                            <span class="ml-1 text-xs font-normal text-slate-500">({{ $loc->code }} · {{ ucfirst($loc->type) }})</span>
                        </td>
                        @if ($showMonthly)
                            <td class="px-4 py-3 text-right tabular-nums font-medium">{{ ($m['has_target'] ?? false) && $m['sales']['pct'] !== null ? number_format($m['sales']['pct'], 1).'%' : '—' }}</td>
                            <td class="px-4 py-3 text-right tabular-nums text-slate-600">
                                @if ($m['has_target'] ?? false)
                                    {{ number_format($m['sales']['actual'], 0) }} / {{ number_format($m['sales']['target'], 0) }}
                                @else
                                    —
                                @endif
                            </td>
                            <td class="px-4 py-3 text-right tabular-nums font-medium">{{ ($m['has_target'] ?? false) && $m['collection']['pct'] !== null ? number_format($m['collection']['pct'], 1).'%' : '—' }}</td>
                            <td class="px-4 py-3 text-right tabular-nums text-slate-600">
                                @if ($m['has_target'] ?? false)
                                    {{ number_format($m['collection']['actual'], 0) }} / {{ number_format($m['collection']['target'], 0) }}
                                @else
                                    —
                                @endif
                            </td>
                        @endif
                        @if ($showAnnual)
                            <td class="px-4 py-3 text-right tabular-nums font-medium">{{ ($a['has_target'] ?? false) && $a['sales']['pct'] !== null ? number_format($a['sales']['pct'], 1).'%' : '—' }}</td>
                            <td class="px-4 py-3 text-right tabular-nums text-slate-600">
                                @if ($a['has_target'] ?? false)
                                    {{ number_format($a['sales']['actual'], 0) }} / {{ number_format($a['sales']['target'], 0) }}
                                @else
                                    —
                                @endif
                            </td>
                            <td class="px-4 py-3 text-right tabular-nums font-medium">{{ ($a['has_target'] ?? false) && $a['collection']['pct'] !== null ? number_format($a['collection']['pct'], 1).'%' : '—' }}</td>
                            <td class="px-4 py-3 text-right tabular-nums text-slate-600">
                                @if ($a['has_target'] ?? false)
                                    {{ number_format($a['collection']['actual'], 0) }} / {{ number_format($a['collection']['target'], 0) }}
                                @else
                                    —
                                @endif
                            </td>
                        @endif
                    </tr>
                @empty
                    <tr>
                        <td colspan="{{ $colCount }}" class="bg-slate-50/50 px-4 py-12 text-center text-sm font-medium text-slate-500">No branches in scope.</td>
                    </tr>
                @endforelse
                @if ($rows->isNotEmpty() && $shown === 0)
                    <tr>
                        <td colspan="{{ $colCount }}" class="bg-slate-50/50 px-4 py-12 text-center text-sm font-medium text-slate-500">No targets set for the selected period.</td>
                    </tr>
                @endif
            </tbody>
        </table>
    </div>
    <p class="text-xs text-slate-500">{{ $kpis['monthly_with_targets'] }} branch{{ $kpis['monthly_with_targets'] === 1 ? '' : 'es' }} with monthly targets · {{ $kpis['annual_with_targets'] }} with annual targets · {{ $filterSummary }}</p>
</div>
@endsection
