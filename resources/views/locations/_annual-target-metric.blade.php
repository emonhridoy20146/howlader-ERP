@php
    $barTone = match ($tone ?? 'indigo') {
        'emerald' => 'bg-emerald-500',
        default => 'bg-indigo-500',
    };
    $pct = $metric['pct'];
    $barWidth = $pct !== null ? min(100, $pct) : 0;
@endphp

<div class="rounded-2xl border border-slate-200 bg-white p-5">
    <div class="flex items-start justify-between gap-3">
        <div>
            <p class="text-xs font-semibold uppercase tracking-wider text-slate-500">{{ $label }}</p>
            <p class="mt-1 font-mono text-2xl font-bold tabular-nums text-slate-900">{{ number_format($metric['actual'], 2) }}</p>
            <p class="mt-1 text-xs text-slate-500">
                @if ($metric['target'] > 0)
                    of {{ number_format($metric['target'], 2) }} target
                @else
                    No target set
                @endif
            </p>
        </div>
        @if ($pct !== null)
            <span class="rounded-full bg-slate-100 px-2.5 py-1 text-xs font-bold tabular-nums text-slate-700">{{ number_format($pct, 1) }}%</span>
        @endif
    </div>
    @if ($metric['target'] > 0)
        <div class="mt-4 h-2.5 overflow-hidden rounded-full bg-slate-100">
            <div class="{{ $barTone }} h-full rounded-full transition-all" style="width: {{ $barWidth }}%"></div>
        </div>
        <p class="mt-2 text-xs text-slate-500">
            @if ($metric['remaining'] > 0)
                {{ number_format($metric['remaining'], 2) }} remaining to reach target
            @else
                Target reached
            @endif
        </p>
    @endif

</div>
