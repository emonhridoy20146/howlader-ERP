@props([
    'label' => 'Row actions',
])

<div
    x-data="indexRowActionsMenu()"
    class="relative flex justify-end"
    @keydown.escape.window="close()"
    @scroll.window="close()"
>
    <button
        x-ref="trigger"
        type="button"
        class="inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-lg border border-slate-200 bg-white text-slate-600 shadow-sm transition hover:border-slate-300 hover:bg-slate-50 hover:text-slate-900 focus:outline-none focus:ring-2 focus:ring-indigo-500/30"
        @click.stop="toggle()"
        :aria-expanded="open"
        aria-haspopup="menu"
    >
        <span class="sr-only">{{ $label }}</span>
        <svg xmlns="http://www.w3.org/2000/svg" fill="currentColor" viewBox="0 0 24 24" class="h-5 w-5" aria-hidden="true">
            <path d="M12 6.75a1.75 1.75 0 1 1 0-3.5 1.75 1.75 0 0 1 0 3.5Zm0 7a1.75 1.75 0 1 1 0-3.5 1.75 1.75 0 0 1 0 3.5Zm0 7a1.75 1.75 0 1 1 0-3.5 1.75 1.75 0 0 1 0 3.5Z" />
        </svg>
    </button>
    <template x-teleport="body">
        <div
            x-ref="panel"
            x-show="open"
            x-cloak
            x-transition:enter="transition ease-out duration-100"
            x-transition:enter-start="opacity-0 scale-95"
            x-transition:enter-end="opacity-100 scale-100"
            x-transition:leave="transition ease-in duration-75"
            x-transition:leave-start="opacity-100 scale-100"
            x-transition:leave-end="opacity-0 scale-95"
            role="menu"
            class="index-actions-menu-panel fixed z-[320] rounded-xl border border-slate-200 bg-white py-1 shadow-xl ring-1 ring-slate-900/5"
            :style="`top:${top}px;left:${left}px;width:${panelWidth}px`"
            @click.stop
        >
            {{ $slot }}
        </div>
    </template>
</div>
