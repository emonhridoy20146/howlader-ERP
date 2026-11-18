@if ($paginator->hasPages())
    <nav
        role="navigation"
        aria-label="{{ __('Pagination Navigation') }}"
        class="app-pagination-nav mt-4 flex items-stretch justify-between gap-2 rounded-xl border border-slate-200/95 bg-white px-3 py-2.5 shadow-sm ring-1 ring-slate-900/[0.06] sm:px-4"
    >
        @if ($paginator->onFirstPage())
            <span class="inline-flex flex-1 items-center justify-center rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-center text-sm font-medium text-slate-400">
                {!! __('pagination.previous') !!}
            </span>
        @else
            <a
                href="{{ $paginator->previousPageUrl() }}"
                rel="prev"
                class="inline-flex flex-1 items-center justify-center rounded-lg border border-slate-200 bg-white px-3 py-2 text-center text-sm font-medium text-slate-800 shadow-sm transition hover:border-slate-300 hover:bg-slate-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500/35"
            >
                {!! __('pagination.previous') !!}
            </a>
        @endif

        @if ($paginator->hasMorePages())
            <a
                href="{{ $paginator->nextPageUrl() }}"
                rel="next"
                class="inline-flex flex-1 items-center justify-center rounded-lg border border-slate-200 bg-white px-3 py-2 text-center text-sm font-medium text-slate-800 shadow-sm transition hover:border-slate-300 hover:bg-slate-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500/35"
            >
                {!! __('pagination.next') !!}
            </a>
        @else
            <span class="inline-flex flex-1 items-center justify-center rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-center text-sm font-medium text-slate-400">
                {!! __('pagination.next') !!}
            </span>
        @endif
    </nav>
@endif
