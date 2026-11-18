@props([
    'title' => null,
    'meta' => null,
    /** Show a short hint on small screens when tables scroll horizontally */
    'showMobileHint' => true,
])

<div {{ $attributes->class([
    'index-table-shell relative mb-5 w-full min-w-0 max-w-full overflow-hidden rounded-2xl border border-slate-200/95 bg-white shadow-sm shadow-slate-900/[0.05] ring-1 ring-slate-900/[0.035]',
]) }}>
    @if (filled($title) || filled($meta) || isset($toolbar))
        <div class="flex flex-col gap-2 border-b border-slate-100 bg-gradient-to-r from-slate-50 via-white to-indigo-50/20 px-4 py-3.5 sm:flex-row sm:items-center sm:justify-between sm:px-5">
            <div class="min-w-0 flex-1 space-y-0.5">
                @if (filled($title))
                    <h2 class="text-sm font-semibold tracking-tight text-slate-900">{{ $title }}</h2>
                @endif
                @if (filled($meta))
                    <p class="text-xs font-medium text-slate-500">{{ $meta }}</p>
                @endif
            </div>
            @isset($toolbar)
                <div class="flex shrink-0 flex-wrap items-center gap-2">{{ $toolbar }}</div>
            @endisset
        </div>
    @endif

    <div class="relative min-w-0 w-full">
        <div class="index-table-scroll">
            {{ $slot }}
        </div>
        @if ($showMobileHint)
            <p class="border-t border-slate-50 px-4 py-2 text-[11px] leading-snug text-slate-400 sm:hidden">
                Scroll sideways to see all columns.
            </p>
        @endif
    </div>
</div>
