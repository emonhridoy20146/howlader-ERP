<!DOCTYPE html>
<html lang="en" x-data="{ sidebarOpen: window.matchMedia('(min-width: 1024px)').matches }" data-session-idle-minutes="{{ (int) config('session.lifetime') }}" data-logout-url="{{ route('logout') }}" data-csrf-token="{{ csrf_token() }}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>@yield('title', 'Dashboard') - {{ config('app.name') }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-slate-100 text-slate-900">
    <div class="lg:hidden fixed inset-x-0 top-0 z-[60] border-b border-slate-200 bg-white/95 backdrop-blur">
        <div class="flex items-center justify-between px-4 py-3">
            <button
                type="button"
                @click="sidebarOpen = !sidebarOpen"
                class="relative inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-lg border border-slate-300 text-slate-800 hover:bg-slate-50"
                :aria-expanded="sidebarOpen"
                aria-controls="app-sidebar"
            >
                <span class="sr-only" x-text="sidebarOpen ? 'Close navigation' : 'Open navigation'"></span>
                <svg x-show="!sidebarOpen" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="pointer-events-none absolute inset-0 m-auto h-6 w-6" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6.75h16.5M3.75 12h16.5m-16.5 5.25h16.5" />
                </svg>
                <svg x-cloak x-show="sidebarOpen" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="pointer-events-none absolute inset-0 m-auto h-6 w-6" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" />
                </svg>
            </button>
            <p class="text-sm font-semibold">{{ config('app.name') }}</p>
            <form action="{{ route('logout') }}" method="post">
                @csrf
                <button type="submit" title="Logout" class="rounded-full p-1.5 text-rose-500 hover:bg-rose-50 transition focus:outline-none focus:ring-2 focus:ring-rose-200">
                    <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 9V5.25A2.25 2.25 0 0013.5 3h-6a2.25 2.25 0 00-2.25 2.25v13.5A2.25 2.25 0 007.5 21h6a2.25 2.25 0 002.25-2.25V15m3 0l3-3m0 0l-3-3m3 3H9" />
                    </svg>
                </button>
            </form>
        </div>
    </div>

    <aside
        id="app-sidebar"
        class="fixed inset-y-0 left-0 z-50 flex w-64 flex-col bg-slate-800 transition-transform duration-200"
        :class="sidebarOpen ? 'translate-x-0' : '-translate-x-full lg:translate-x-0'"
    >
        <div class="shrink-0 border-b border-slate-700 px-4 py-3">
            <p class="truncate text-sm font-semibold text-white">{{ auth()->user()->displayName() }}</p>
            <p class="text-[0.65rem] uppercase tracking-[0.2em] text-slate-400">{{ auth()->user()->role }}</p>
        </div>
        <div class="shrink-0 border-b border-slate-700 px-4 py-2.5">
            <form method="post" action="{{ route('context.location') }}" class="space-y-1">
                @csrf
                <label for="sidebar-context-location" class="block text-[0.6rem] font-medium uppercase tracking-[0.15em] text-slate-400">Branch</label>
                <select
                    id="sidebar-context-location"
                    name="location_id"
                    onchange="this.form.submit()"
                    class="w-full rounded-lg border border-slate-600 bg-slate-900 px-2.5 py-1.5 text-sm text-slate-100 shadow-sm focus:border-slate-500 focus:outline-none focus:ring-2 focus:ring-slate-500/40"
                >
                    @if ($showNavLocationAllOption ?? true)
                        <option value="all" @selected($navContextLocationId === null)>All locations</option>
                    @endif
                    @foreach ($navContextLocations as $loc)
                        <option value="{{ $loc->id }}" @selected($navContextLocationId === $loc->id)>{{ $loc->name }}</option>
                    @endforeach
                </select>
            </form>
        </div>
        @include('layouts.partials.sidebar')
    </aside>

    @if ($navContextLocation)
        <div
            role="status"
            class="fixed inset-x-0 top-16 z-[48] flex h-10 w-full shrink-0 items-center border-b border-red-400 bg-gradient-to-r from-red-600 via-red-700 to-red-600 px-3 text-sm font-medium text-white shadow-[0_0_14px_3px_rgba(239,68,68,0.85),0_0_32px_8px_rgba(220,38,38,0.45),inset_0_1px_0_rgba(255,255,255,0.2)] ring-1 ring-red-300/90 sm:px-6 lg:left-64 lg:top-0"
        >
            <p class="min-w-0 w-full truncate text-center leading-tight drop-shadow-[0_1px_2px_rgba(0,0,0,0.35)]">
                You are viewing as
                <span class="font-bold text-white underline decoration-red-200/90 decoration-2 underline-offset-2">{{ $navContextLocation->name }}</span>
                branch.
            </p>
        </div>
    @endif

    <main @class([
        'min-h-screen lg:ml-64',
        $navContextLocation
            ? 'pt-[calc(4rem+2.5rem)] lg:pt-10'
            : 'pt-16 lg:pt-0',
    ])>
        <div class="border-b border-slate-200 bg-white px-4 py-4 sm:px-6">
            <div class="mx-auto flex w-full max-w-7xl flex-col gap-4 lg:grid lg:grid-cols-[minmax(0,1fr)_minmax(16rem,28rem)_auto] lg:items-center lg:gap-6">
                <div class="min-w-0 justify-self-start text-left">
                    <p class="text-xs uppercase tracking-[0.2em] text-slate-500">Operations</p>
                    <h1 class="text-xl font-semibold">@yield('page-title', 'Dashboard')</h1>
                </div>

                <div
                    class="relative z-30 w-full max-w-lg self-center lg:justify-self-center"
                    x-data="navSearch(@js(route('search.live')), @js(route('search')))"
                    @click.outside="close()"
                >
                    <label for="nav-global-search" class="sr-only">Search</label>
                    <div class="relative flex items-center w-full group">
                        <div class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3.5 text-slate-400 transition-colors group-focus-within:text-indigo-500">
                            <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-5.197-5.197m0 0A7.5 7.5 0 105.196 5.196a7.5 7.5 0 0010.607 10.607z" />
                            </svg>
                        </div>
                        <input
                            id="nav-global-search"
                            x-ref="searchInput"
                            type="search"
                            x-model="q"
                            @input.debounce.300ms="liveQuery()"
                            @focus="onFocus()"
                            @keydown.escape="close()"
                            @keydown.enter.prevent="goFullSearch()"
                            placeholder="Search everywhere..."
                            autocomplete="off"
                            class="block w-full rounded-full border-0 py-2.5 pl-10 pr-12 text-sm text-slate-900 ring-1 ring-inset ring-slate-300 bg-slate-50/50 shadow-sm transition hover:bg-white placeholder:text-slate-400 focus:bg-white focus:ring-2 focus:ring-inset focus:ring-indigo-500"
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
                        class="fixed z-[200] mt-1 max-h-[70vh] overflow-y-auto overscroll-contain rounded-xl border border-slate-200 bg-white py-1 shadow-2xl ring-1 ring-slate-900/5"
                        :style="dropdownStyle()"
                    >
                        <div x-show="loading" class="px-4 py-3 text-sm text-slate-500">Searching…</div>
                        <div x-show="!loading && empty" class="px-4 py-3 text-sm text-slate-500">No matches.</div>
                        <template x-for="group in groups" :key="group.label">
                            <div class="border-b border-slate-100 py-2 last:border-b-0">
                                <p class="px-3 pb-1 text-xs font-semibold uppercase tracking-[0.15em] text-slate-500" x-text="group.label"></p>
                                <template x-for="(item, idx) in group.items" :key="group.label + '-' + idx + '-' + item.title">
                                    <div>
                                        <template x-if="group.label === 'Products'">
                                            <a :href="item.url" class="flex items-stretch text-sm hover:bg-slate-50" @click="close()">
                                                <div class="flex min-w-0 flex-1 flex-col justify-center px-3 py-2">
                                                    <span class="font-medium text-slate-900" x-text="item.title"></span>
                                                    <span class="mt-0.5 block text-xs text-slate-500" x-show="item.subtitle" x-text="item.subtitle"></span>
                                                </div>
                                                <div class="w-56 shrink-0 border-l border-slate-100 bg-slate-50/70 px-3 py-2">
                                                    <p class="mb-0.5 text-xs font-semibold uppercase tracking-wide text-slate-400">Branch Stock</p>
                                                    <template x-if="item.stockByBranch && item.stockByBranch.length === 0">
                                                        <p class="text-xs italic text-slate-400">No stock</p>
                                                    </template>
                                                    <template x-for="(row, ri) in (item.stockByBranch || [])" :key="ri">
                                                        <div class="flex items-center justify-between gap-1 py-0.5 text-xs">
                                                            <span class="truncate text-slate-700" x-text="row.branch"></span>
                                                            <span class="shrink-0 font-semibold" :class="row.available > 0 ? 'text-emerald-600' : 'text-red-500'" x-text="row.available + ' pcs'"></span>
                                                        </div>
                                                    </template>
                                                </div>
                                            </a>
                                        </template>
                                        <template x-if="group.label !== 'Products'">
                                            <a
                                                :href="item.url"
                                                class="block px-3 py-2 text-sm hover:bg-slate-50"
                                                @click="close()"
                                            >
                                                <span class="font-medium text-slate-900" x-text="item.title"></span>
                                                <span class="mt-0.5 block text-xs text-slate-500" x-show="item.subtitle" x-text="item.subtitle"></span>
                                            </a>
                                        </template>
                                        <template x-for="(action, ai) in (item.actions || [])" :key="'a' + ai">
                                            <a :href="action.url" class="flex items-center gap-2 px-3 pb-2 text-xs font-medium text-indigo-700 hover:underline" @click="close()">
                                                <span x-text="action.label"></span>
                                                <span x-show="action.grade" class="rounded-full bg-slate-100 px-1.5 py-0.5 text-[10px] font-semibold text-slate-700" x-text="action.grade"></span>
                                            </a>
                                        </template>
                                    </div>
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

                <div class="flex flex-wrap items-center justify-center gap-1 justify-self-end lg:flex-nowrap lg:justify-self-end">
                    <a href="{{ route('profile.edit') }}" title="Profile" class="hidden rounded-full p-2 text-slate-400 hover:bg-slate-100 hover:text-slate-900 transition sm:inline-flex focus:outline-none focus:ring-2 focus:ring-slate-200">
                        <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 6a3.75 3.75 0 11-7.5 0 3.75 3.75 0 017.5 0zM4.501 20.118a7.5 7.5 0 0114.998 0A17.933 17.933 0 0112 21.75c-2.676 0-5.216-.584-7.499-1.632z" />
                        </svg>
                    </a>
                    <a href="{{ route('profile.password.edit') }}" title="Password" class="hidden rounded-full p-2 text-slate-400 hover:bg-slate-100 hover:text-slate-900 transition md:inline-flex focus:outline-none focus:ring-2 focus:ring-slate-200">
                        <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 5.25a3 3 0 013 3m3 0a6 6 0 01-7.029 5.912c-.563-.097-1.159.026-1.563.43L10.5 17.25H8.25v2.25H6v2.25H2.25v-2.818c0-.597.237-1.17.659-1.591l6.499-6.499c.404-.404.527-1 .43-1.563A6 6 0 1121.75 8.25z" />
                        </svg>
                    </a>
                    <form action="{{ route('logout') }}" method="post" class="hidden sm:block">
                        @csrf
                        <button type="submit" title="Logout" class="rounded-full p-2 text-rose-400 hover:bg-rose-50 hover:text-rose-600 transition focus:outline-none focus:ring-2 focus:ring-rose-200">
                            <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 9V5.25A2.25 2.25 0 0013.5 3h-6a2.25 2.25 0 00-2.25 2.25v13.5A2.25 2.25 0 007.5 21h6a2.25 2.25 0 002.25-2.25V15m3 0l3-3m0 0l-3-3m3 3H9" />
                            </svg>
                        </button>
                    </form>
                </div>
            </div>
        </div>

        <div class="mx-auto w-full min-w-0 max-w-[min(100%,88rem)] px-4 pb-[max(6.5rem,calc(5rem+env(safe-area-inset-bottom,0px)))] pt-4 sm:px-6 sm:pt-6">
            @yield('content')
        </div>
    </main>

    <x-quick-nav />
    @if (session('grn_post_error_popup'))
        @php($grnPostError = session('grn_post_error_popup'))
        <div
            id="grn-post-error-popup"
            class="fixed inset-0 z-[80] flex items-center justify-center bg-slate-900/55 px-4"
            role="dialog"
            aria-modal="true"
            aria-labelledby="grn-post-error-title"
        >
            <div class="w-full max-w-lg rounded-2xl border border-rose-200 bg-white p-5 shadow-2xl">
                <h3 id="grn-post-error-title" class="text-lg font-semibold text-rose-700">
                    {{ $grnPostError['title'] ?? 'Unable to post this Goods Receipt' }}
                </h3>
                <p class="mt-2 text-sm text-slate-700">
                    {{ $grnPostError['message'] ?? 'An issue occurred while posting this Goods Receipt.' }}
                </p>
                <p class="mt-3 text-sm text-slate-600">
                    {{ $grnPostError['suggestion'] ?? 'Please review this draft before trying again.' }}
                </p>
                <div class="mt-5 flex flex-wrap justify-end gap-2">
                    <button
                        type="button"
                        onclick="document.getElementById('grn-post-error-popup')?.remove()"
                        class="inline-flex rounded-lg border border-slate-300 bg-white px-3 py-1.5 text-xs font-medium text-slate-700 hover:bg-slate-50"
                    >
                        Close
                    </button>
                    @if (! empty($grnPostError['delete_url']))
                        <form method="post" action="{{ $grnPostError['delete_url'] }}" data-confirm="Delete this Goods Receipt draft?">
                            @csrf
                            @method('DELETE')
                            <button
                                type="submit"
                                class="inline-flex rounded-lg border border-rose-200 bg-rose-50 px-3 py-1.5 text-xs font-medium text-rose-700 hover:bg-rose-100"
                            >
                                Delete this Goods Receipt
                            </button>
                        </form>
                    @endif
                </div>
            </div>
        </div>
    @endif
    @if (session('swal'))
        @php($swal = session('swal'))
        <script>
            document.addEventListener('DOMContentLoaded', () => {
                const payload = @json($swal);
                if (window.Swal) {
                    window.Swal.fire({
                        icon: payload.icon || 'info',
                        title: payload.title || 'Notice',
                        text: payload.text || '',
                        confirmButtonText: payload.confirmButtonText || 'OK',
                    });
                    return;
                }
                if (payload.text) {
                    alert(payload.text);
                }
            });
        </script>
    @endif
    @if ($errors->any())
        <script>
            document.addEventListener('DOMContentLoaded', () => {
                const errors = @json($errors->toArray());
                const fieldClasses = ['border-rose-400', 'ring-1', 'ring-rose-200', 'focus:border-rose-500', 'focus:ring-rose-200'];
                const formMessages = new Map();

                Object.entries(errors).forEach(([field, messages]) => {
                    const escapedField = field.replace(/"/g, '\\"');
                    const bracketField = escapedField
                        .split('.')
                        .map((segment, index) => index === 0 ? segment : `[${segment}]`)
                        .join('');
                    const selectors = [
                        `[name="${escapedField}"]`,
                        `[name="${escapedField}[]"]`,
                        `[name="${bracketField}"]`,
                        `[name="${bracketField}[]"]`,
                    ];

                    const nodes = document.querySelectorAll(selectors.join(', '));
                    if (!nodes.length) {
                        return;
                    }

                    nodes.forEach((node) => {
                        fieldClasses.forEach((cls) => node.classList.add(cls));
                        node.setAttribute('aria-invalid', 'true');
                        const parentForm = node.closest('form');
                        if (parentForm && !formMessages.has(parentForm)) {
                            formMessages.set(parentForm, []);
                        }
                        if (parentForm && messages?.length) {
                            const existing = formMessages.get(parentForm) ?? [];
                            messages.forEach((msg) => {
                                if (!existing.includes(msg)) {
                                    existing.push(msg);
                                }
                            });
                            formMessages.set(parentForm, existing);
                        }

                        if (node.dataset.validationMessageInjected === '1') {
                            return;
                        }

                        const message = document.createElement('p');
                        message.className = 'mt-1 text-xs text-rose-700';
                        message.textContent = messages[0] ?? 'Invalid value.';
                        node.insertAdjacentElement('afterend', message);
                        node.dataset.validationMessageInjected = '1';
                    });
                });

                formMessages.forEach((messages, form) => {
                    if (!messages.length || form.dataset.formValidationSummaryInjected === '1') {
                        return;
                    }

                    const container = document.createElement('div');
                    container.className = 'mt-3 rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-700';

                    const title = document.createElement('p');
                    title.className = 'font-medium';
                    title.textContent = 'Please fix the highlighted fields.';
                    container.appendChild(title);

                    const list = document.createElement('ul');
                    list.className = 'mt-1 list-disc pl-5';
                    messages.forEach((msg) => {
                        const li = document.createElement('li');
                        li.textContent = msg;
                        list.appendChild(li);
                    });
                    container.appendChild(list);

                    form.insertAdjacentElement('afterend', container);
                    form.dataset.formValidationSummaryInjected = '1';
                });
            });
        </script>
    @endif

    @php($impersonator = \App\Support\UserImpersonation::impersonator())
    @if ($impersonator)
        <div class="fixed bottom-4 right-4 z-[70] max-w-sm">
            <form method="post" action="{{ route('impersonation.leave') }}">
                @csrf
                <button
                    type="submit"
                    class="flex w-full items-center gap-3 rounded-2xl border border-amber-300 bg-amber-50 px-4 py-3 text-left text-sm font-medium text-amber-950 shadow-lg ring-1 ring-amber-200/80 transition hover:bg-amber-100 focus:outline-none focus:ring-2 focus:ring-amber-400 focus:ring-offset-2"
                >
                    <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-amber-200 text-amber-900">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="h-5 w-5" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 9V5.25A2.25 2.25 0 0 0 13.5 3h-6a2.25 2.25 0 0 0-2.25 2.25v13.5A2.25 2.25 0 0 0 7.5 21h6a2.25 2.25 0 0 0 2.25-2.25V15M12 9h6m-6 0V5.25" />
                        </svg>
                    </span>
                    <span class="min-w-0">
                        <span class="block text-xs font-semibold uppercase tracking-wide text-amber-800/80">Impersonating</span>
                        <span class="mt-0.5 block truncate">{{ auth()->user()->displayName() }}</span>
                        <span class="mt-1 block text-xs font-normal text-amber-900/80">Log back in as {{ $impersonator->displayName() }}</span>
                    </span>
                </button>
            </form>
        </div>
    @endif
</body>
</html>
