@extends('layouts.app')

@section('title', 'Users')
@section('page-title', 'Users')

@section('content')
@php
    $pageActive = $users->filter(fn ($u) => $u->is_active)->count();
    $pageInactive = $users->count() - $pageActive;
@endphp
<div class="space-y-6">
    <div class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
        <div class="flex flex-col gap-4 border-b border-slate-100 bg-gradient-to-br from-slate-50 via-white to-indigo-50/30 p-6 sm:flex-row sm:items-center sm:justify-between">
            <div class="min-w-0">
                <p class="text-xs font-semibold uppercase tracking-[0.2em] text-slate-500">Administration</p>
                <h2 class="mt-1 text-xl font-semibold tracking-tight text-slate-900">Users</h2>
                <p class="mt-1 max-w-xl text-sm text-slate-600">Manage accounts, roles, and which branches each user can work in.</p>
            </div>
            <a href="{{ route('users.create') }}" class="inline-flex shrink-0 items-center justify-center gap-2 rounded-xl bg-slate-900 px-4 py-2.5 text-sm font-medium text-white shadow-sm transition hover:bg-slate-800 focus:outline-none focus:ring-2 focus:ring-slate-400 focus:ring-offset-2">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" class="h-5 w-5" aria-hidden="true">
                    <path d="M10.75 4.75a.75.75 0 0 0-1.5 0v4.5h-4.5a.75.75 0 0 0 0 1.5h4.5v4.5a.75.75 0 0 0 1.5 0v-4.5h4.5a.75.75 0 0 0 0-1.5h-4.5v-4.5Z" />
                </svg>
                New user
            </a>
        </div>
        <div class="flex flex-wrap gap-2 px-6 py-3 text-xs font-medium text-slate-600">
            <span class="inline-flex items-center gap-1.5 rounded-full border border-slate-200 bg-white px-3 py-1 shadow-sm">
                <span class="h-1.5 w-1.5 rounded-full bg-slate-400" aria-hidden="true"></span>
                Total <span class="tabular-nums text-slate-900">{{ $users->total() }}</span>
            </span>
            <span class="inline-flex items-center gap-1.5 rounded-full border border-emerald-200 bg-emerald-50/80 px-3 py-1 text-emerald-800 shadow-sm" title="Counts on this page only">
                <span class="h-1.5 w-1.5 rounded-full bg-emerald-500" aria-hidden="true"></span>
                Active on page <span class="tabular-nums font-semibold">{{ $pageActive }}</span>
            </span>
            <span class="inline-flex items-center gap-1.5 rounded-full border border-rose-200 bg-rose-50/80 px-3 py-1 text-rose-800 shadow-sm" title="Counts on this page only">
                <span class="h-1.5 w-1.5 rounded-full bg-rose-500" aria-hidden="true"></span>
                Inactive on page <span class="tabular-nums font-semibold">{{ $pageInactive }}</span>
            </span>
        </div>
    </div>

    @if (session('status'))
        <div class="rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-900 shadow-sm">
            {{ session('status') }}
        </div>
    @endif

    <x-index-table-shell class="mb-4">
            <table class="index-data-table min-w-[42rem] sm:min-w-full text-left text-sm text-slate-700">
                <thead class="border-b border-slate-200 bg-slate-50/90 text-xs font-semibold uppercase tracking-wide text-slate-500">
                    <tr>
                        <th class="px-5 py-3.5">User</th>
                        <th class="px-5 py-3.5">Email</th>
                        <th class="px-5 py-3.5">Roles</th>
                        <th class="px-5 py-3.5">Locations</th>
                        <th class="px-5 py-3.5 text-right">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse ($users as $user)
                        @php
                            $name = $user->displayName();
                            $initials = str($name)->explode(' ')->take(2)->map(fn ($w) => strtoupper(mb_substr($w, 0, 1)))->implode('');
                            if ($initials === '') {
                                $initials = strtoupper(mb_substr($user->email ?? '?', 0, 1));
                            }
                        @endphp
                        <tr class="transition hover:bg-slate-50/80">
                            <td class="px-5 py-4">
                                <div class="flex items-center gap-3">
                                    <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-gradient-to-br from-slate-600 to-slate-800 text-xs font-bold text-white shadow-inner" aria-hidden="true">
                                        {{ $initials }}
                                    </div>
                                    <div class="min-w-0">
                                        <p class="truncate font-medium text-slate-900">{{ $name }}</p>
                                        <p class="mt-0.5 flex flex-wrap items-center gap-2">
                                            @if ($user->is_active)
                                                <span class="inline-flex items-center rounded-full bg-emerald-100 px-2 py-0.5 text-[11px] font-medium text-emerald-800">Active</span>
                                            @else
                                                <span class="inline-flex items-center rounded-full bg-slate-200 px-2 py-0.5 text-[11px] font-medium text-slate-700">Inactive</span>
                                            @endif
                                        </p>
                                    </div>
                                </div>
                            </td>
                            <td class="px-5 py-4">
                                <span class="text-slate-800">{{ $user->email }}</span>
                            </td>
                            <td class="px-5 py-4">
                                @if ($user->roles->isEmpty())
                                    <span class="text-slate-400">—</span>
                                @else
                                    <div class="flex max-w-xs flex-wrap gap-1">
                                        @foreach ($user->roles as $role)
                                            <span class="inline-flex rounded-full border border-indigo-200 bg-indigo-50 px-2 py-0.5 text-xs font-medium text-indigo-900">{{ $role->name }}</span>
                                        @endforeach
                                    </div>
                                @endif
                            </td>
                            <td class="px-5 py-4">
                                @if ($user->accessibleLocations->isEmpty())
                                    <span class="text-slate-400">None</span>
                                @else
                                    <div class="flex max-w-md flex-wrap gap-1">
                                        @foreach ($user->accessibleLocations->take(4) as $loc)
                                            <span class="inline-flex rounded-full border border-emerald-200 bg-emerald-50 px-2 py-0.5 text-xs font-medium text-emerald-900">{{ $loc->name }}</span>
                                        @endforeach
                                        @if ($user->accessibleLocations->count() > 4)
                                            <span class="inline-flex rounded-full bg-slate-100 px-2 py-0.5 text-xs font-medium text-slate-600">+{{ $user->accessibleLocations->count() - 4 }}</span>
                                        @endif
                                    </div>
                                @endif
                            </td>
                            <td class="px-5 py-4 text-right">
                                <x-index-row-actions-menu>
                                    <a href="{{ route('users.edit', $user) }}">Edit</a>
                                    @include('users.partials.impersonate-action', ['user' => $user, 'stopPropagation' => true])
                                </x-index-row-actions-menu>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="px-5 py-16 text-center">
                                <div class="mx-auto max-w-sm">
                                    <div class="mx-auto flex h-12 w-12 items-center justify-center rounded-full bg-slate-100 text-slate-400">
                                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="h-6 w-6" aria-hidden="true">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 6a3.75 3.75 0 1 1-7.5 0 3.75 3.75 0 0 1 7.5 0ZM4.501 20.118a7.5 7.5 0 0 1 14.998 0A17.933 17.933 0 0 1 12 21.75c-2.676 0-5.216-.584-7.499-1.632Z" />
                                        </svg>
                                    </div>
                                    <p class="mt-4 text-sm font-medium text-slate-900">No users yet</p>
                                    <p class="mt-1 text-sm text-slate-500">Create the first account to invite your team.</p>
                                    <a href="{{ route('users.create') }}" class="mt-4 inline-flex rounded-lg bg-slate-900 px-4 py-2 text-sm font-medium text-white hover:bg-slate-800">Create user</a>
                                </div>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
    </x-index-table-shell>
        @if ($users->hasPages())
            <div class="overflow-hidden rounded-2xl border border-slate-200 bg-slate-50/50 px-4 py-3 shadow-sm">
                <x-pagination-limit-selector />
                {{ $users->links() }}
            </div>
        @endif
</div>
@endsection
