@props(['hasActiveFilters' => !empty(array_filter(request()->except(['page', 'limit', 'sort', 'direction', 'drawer', 'edit'])))])

@php
    $activeFiltersCount = count(array_filter(request()->except(['page', 'limit', 'sort', 'direction', 'drawer', 'edit'])));
@endphp
<div x-data="{ open: false }">
    <!-- Active Filters Banner on Index Page -->
    @if($activeFiltersCount > 0)
        <div class="mb-4 flex items-center gap-3 rounded-xl border border-emerald-100 bg-emerald-50 px-4 py-3 text-sm font-medium text-emerald-800 shadow-sm">
            <span class="flex h-6 w-6 items-center justify-center rounded-full bg-emerald-200/50 text-emerald-600">
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 4a1 1 0 011-1h16a1 1 0 011 1v2.586a1 1 0 01-.293.707l-6.414 6.414a1 1 0 00-.293.707V17l-4 4v-6.586a1 1 0 00-.293-.707L3.293 7.293A1 1 0 013 6.586V4z" />
                </svg>
            </span>
            <span>Showing results for <strong>{{ $activeFiltersCount }}</strong> active filter{{ $activeFiltersCount !== 1 ? 's' : '' }}</span>
            <button type="button" @click="open = true" class="ml-auto text-xs font-bold uppercase tracking-wider text-emerald-700 underline-offset-2 transition hover:text-emerald-900 hover:underline focus:outline-none">
                Modify Filters
            </button>
        </div>
    @endif


    <!-- Floating Button -->
    <button type="button" @click="open = true" class="fixed right-0 top-1/2 z-[60] -translate-y-1/2 rounded-l-2xl bg-slate-900 p-3.5 text-white shadow-xl transition-transform hover:-translate-x-1 hover:bg-slate-800 focus:outline-none focus:ring-2 focus:ring-slate-900 focus:ring-offset-2">
        <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 3c2.755 0 5.455.232 8.083.678.533.09.917.556.917 1.096v1.044a2.25 2.25 0 0 1-.659 1.591l-5.432 5.432a2.25 2.25 0 0 0-.659 1.591v2.927a2.25 2.25 0 0 1-1.244 2.013L9.75 21v-6.568a2.25 2.25 0 0 0-.659-1.591L3.659 7.409A2.25 2.25 0 0 1 3 5.818V4.774c0-.54.384-1.006.917-1.096A48.32 48.32 0 0 1 12 3Z" />
        </svg>
        @if($hasActiveFilters)
            <span class="absolute left-2 top-2 h-3 w-3 rounded-full bg-rose-500 ring-2 ring-slate-900"></span>
        @endif
    </button>

    <!-- Overlay -->
    <div x-show="open" x-cloak class="fixed inset-0 z-[70] bg-slate-900/40 backdrop-blur-sm transition-opacity" @click="open = false"
        x-transition:enter="ease-out duration-300" x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100"
        x-transition:leave="ease-in duration-200" x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0"></div>

    <!-- Drawer -->
    <div x-show="open" x-cloak
        x-transition:enter="transition ease-out duration-300" x-transition:enter-start="translate-x-full" x-transition:enter-end="translate-x-0"
        x-transition:leave="transition ease-in duration-300" x-transition:leave-start="translate-x-0" x-transition:leave-end="translate-x-full"
        class="fixed inset-y-0 right-0 z-[80] flex w-full max-w-sm flex-col border-l border-slate-200 bg-white shadow-2xl">
        
        <div class="flex items-start justify-between border-b border-slate-100 bg-slate-50/50 px-6 py-5">
            <div>
                <h2 class="text-lg font-semibold text-slate-800">Advanced Filters</h2>
                @if($activeFiltersCount > 0)
                    <p class="mt-1 text-xs font-medium text-emerald-600">Showing results for {{ $activeFiltersCount }} active filter{{ $activeFiltersCount !== 1 ? 's' : '' }}</p>
                @endif
            </div>
            <button type="button" @click="open = false" class="rounded-full p-2 text-slate-400 transition-colors hover:bg-slate-200 hover:text-slate-700 focus:outline-none -mt-1 -mr-2">
                <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                </svg>
            </button>
        </div>
        
        <div class="flex-1 overflow-y-auto p-6">
            <div class="[&_form>div]:grid [&_form>div]:grid-cols-1 [&_form>div]:gap-5 [&_input]:w-full [&_input]:rounded-xl [&_input]:border-slate-200 [&_input]:bg-slate-50 [&_input]:px-4 [&_input]:py-2.5 [&_input]:text-sm [&_input]:transition-all focus:[&_input]:border-emerald-500 focus:[&_input]:bg-white focus:[&_input]:ring-1 focus:[&_input]:ring-emerald-500 [&_select]:w-full [&_select]:rounded-xl [&_select]:border-slate-200 [&_select]:bg-slate-50 [&_select]:px-4 [&_select]:py-2.5 [&_select]:text-sm [&_select]:transition-all focus:[&_select]:border-emerald-500 focus:[&_select]:bg-white focus:[&_select]:ring-1 focus:[&_select]:ring-emerald-500 [&_label]:mb-1.5 [&_label]:block [&_label]:text-sm [&_label]:font-medium [&_label]:text-slate-700">
                {{ $slot }}
            </div>
        </div>
    </div>
</div>
