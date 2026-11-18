@props([
    'inputId' => 'nav-global-search',
    'wrapperClass' => 'relative z-30 w-full max-w-lg self-center lg:justify-self-center',
    'dropdownClass' => 'absolute left-1/2 top-full z-50 mt-1 w-[min(100vw-2rem,36rem)] max-h-[min(24rem,70vh)] -translate-x-1/2 overflow-y-auto overscroll-contain rounded-xl border border-slate-200 bg-white py-1 shadow-lg ring-1 ring-slate-900/5',
])

<div
    class="{{ $wrapperClass }}"
    x-data="navSearch(@js(route('search.live')), @js(route('search')))"
    @click.outside="close()"
>
    <label for="{{ $inputId }}" class="sr-only">Search</label>
    <div class="group relative flex w-full items-center">
        <div class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3.5 text-slate-400 transition-colors group-focus-within:text-indigo-500">
            <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-5.197-5.197m0 0A7.5 7.5 0 105.196 5.196a7.5 7.5 0 0010.607 10.607z" />
            </svg>
        </div>
        <input
            id="{{ $inputId }}"
            type="search"
            x-model="q"
            @input.debounce.300ms="liveQuery()"
            @focus="onFocus()"
            @keydown.escape="close()"
            @keydown.enter.prevent="goFullSearch()"
            placeholder="Search customer code, name, invoice, serial…"
            autocomplete="off"
            class="block w-full rounded-full border-0 bg-slate-50/50 py-2.5 pl-10 pr-12 text-sm text-slate-900 shadow-sm ring-1 ring-inset ring-slate-300 transition placeholder:text-slate-400 hover:bg-white focus:bg-white focus:ring-2 focus:ring-inset focus:ring-indigo-500"
        >
        <div class="absolute inset-y-0 right-1.5 flex items-center">
            <button
                type="button"
                @click="goFullSearch()"
                title="Go to search"
                class="flex h-7 w-7 items-center justify-center rounded-full text-slate-400 transition-colors hover:bg-indigo-50 hover:text-indigo-600 focus:outline-none focus:ring-2 focus:ring-indigo-500"
            >
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M13.5 4.5L21 12m0 0l-7.5 7.5M21 12H3" />
                </svg>
            </button>
        </div>
    </div>
    <div
        x-cloak
        x-show="open"
        x-transition.opacity.duration.150ms
        class="{{ $dropdownClass }}"
    >
        <div x-show="loading" class="px-4 py-3 text-sm text-slate-500">Searching…</div>
        <div x-show="!loading && empty" class="px-4 py-3 text-sm text-slate-500">No matches.</div>
        <template x-for="group in groups" :key="group.label">
            <div class="border-b border-slate-100 py-2 last:border-b-0">
                <p class="px-3 pb-1 text-xs font-semibold uppercase tracking-[0.15em] text-slate-500" x-text="group.label"></p>
                <template x-for="(item, idx) in group.items" :key="group.label + '-' + idx + '-' + item.title">
                    <a
                        :href="item.url"
                        class="block px-3 py-2 text-sm hover:bg-slate-50"
                        @click="close()"
                    >
                        <span class="font-medium text-slate-900" x-text="item.title"></span>
                        <span class="mt-0.5 block text-xs text-slate-500" x-show="item.subtitle" x-text="item.subtitle"></span>
                    </a>
                </template>
            </div>
        </template>
        <a
            x-show="!loading && q.trim().length >= 2"
            :href="fullSearchHref()"
            class="block border-t border-slate-100 px-3 py-2.5 text-center text-xs font-semibold text-slate-700 hover:bg-slate-50"
            @click="close()"
        >View all results</a>
    </div>
</div>
