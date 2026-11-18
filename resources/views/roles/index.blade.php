@extends('layouts.app')

@section('title', 'Roles')
@section('page-title', 'Roles')

@section('content')
<div class="space-y-5">
    <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <h2 class="text-lg font-semibold text-slate-900">Role and permission management</h2>
                <p class="text-sm text-slate-500">Every permission the application checks can be granted individually.</p>
            </div>
            <a href="{{ route('roles.create') }}" class="rounded-lg bg-slate-900 px-4 py-2 text-sm font-medium text-white shadow-sm hover:bg-slate-700">New role</a>
        </div>
        <div class="mt-4 flex flex-wrap items-center gap-2 text-xs font-medium text-slate-600">
            <span class="rounded-full bg-slate-100 px-2.5 py-1">Roles: {{ $roles->count() }}</span>
            <span class="rounded-full bg-indigo-50 px-2.5 py-1 text-indigo-700">Permissions assigned: {{ $roles->sum('permissions_count') }}</span>
        </div>
    </div>

    @if (session('status'))
        <div class="rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">
            {{ session('status') }}
        </div>
    @endif

    <div class="grid gap-6 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
        @forelse ($roles as $role)
            <a href="{{ route('roles.edit', $role) }}" class="group relative block transition-all duration-300 hover:-translate-y-1 hover:drop-shadow-md focus:outline-none focus:ring-2 focus:ring-sky-500 focus:ring-offset-2 rounded-xl">
                <!-- Folder Shadow / Back layer -->
                <div class="absolute inset-0 top-3 rounded-xl bg-sky-200/50 shadow-sm transition-colors group-hover:bg-sky-300/50"></div>
                
                <!-- Folder Tab -->
                <div class="relative w-2/5 max-w-[100px] h-5 rounded-t-xl bg-sky-400 transition-colors group-hover:bg-sky-500"></div>
                
                <!-- Folder Body -->
                <div class="relative flex h-36 flex-col justify-between rounded-b-xl rounded-tr-xl bg-gradient-to-br from-sky-50 to-sky-100 p-5 shadow-sm ring-1 ring-sky-200 transition-all group-hover:from-white group-hover:to-sky-50 group-hover:ring-sky-300">
                    <div>
                        <div class="flex items-start justify-between">
                            <h3 class="font-semibold text-slate-800 line-clamp-1 group-hover:text-sky-700 transition-colors">{{ $role->name }}</h3>
                            <svg class="h-5 w-5 shrink-0 text-sky-400 opacity-0 transition-all duration-300 group-hover:opacity-100 group-hover:translate-x-1" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" />
                            </svg>
                        </div>
                        <p class="mt-1 text-xs font-medium text-slate-400 uppercase tracking-wider">{{ $role->slug }}</p>
                    </div>
                    
                    <div class="mt-auto flex items-center gap-4 text-xs font-medium text-slate-600 border-t border-sky-200/60 pt-3">
                        <div class="flex items-center gap-1.5" title="Users assigned">
                            <svg class="h-4 w-4 text-sky-500" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M15 19.128a9.38 9.38 0 0 0 2.625.372 9.337 9.337 0 0 0 4.121-.952 4.125 4.125 0 0 0-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 0 1 8.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0 1 11.964-3.07M12 6.375a3.375 3.375 0 1 1-6.75 0 3.375 3.375 0 0 1 6.75 0Zm8.25 2.25a2.625 2.625 0 1 1-5.25 0 2.625 2.625 0 0 1 5.25 0Z" />
                            </svg>
                            <span>{{ $role->users_count }}</span>
                        </div>
                        <div class="flex items-center gap-1.5" title="Permissions assigned">
                            <svg class="h-4 w-4 text-emerald-500" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M16.5 10.5V6.75a4.5 4.5 0 1 0-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 0 0 2.25-2.25v-6.75a2.25 2.25 0 0 0-2.25-2.25H6.75a2.25 2.25 0 0 0-2.25 2.25v6.75a2.25 2.25 0 0 0 2.25 2.25Z" />
                            </svg>
                            <span>{{ $role->permissions_count }}</span>
                        </div>
                        @php
                            $sensitiveCount = $role->permissions->pluck('slug')->intersect(\App\Support\PermissionRegistry::sensitiveSlugs())->count();
                        @endphp
                        @if ($role->slug === 'admin')
                            <span class="rounded-full bg-rose-100 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-rose-700" title="Bypasses every permission check">Full access</span>
                        @elseif ($sensitiveCount > 0)
                            <span class="rounded-full bg-amber-100 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-amber-800" title="Sensitive permissions granted">{{ $sensitiveCount }} sensitive</span>
                        @endif
                    </div>
                </div>
            </a>
        @empty
            <div class="col-span-full rounded-2xl border border-dashed border-slate-300 bg-slate-50 py-16 text-center">
                <svg class="mx-auto h-12 w-12 text-slate-400" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                </svg>
                <h3 class="mt-4 text-sm font-semibold text-slate-900">No roles found</h3>
                <p class="mt-1 text-sm text-slate-500">Create a role to start assigning permissions.</p>
                <a href="{{ route('roles.create') }}" class="mt-4 inline-flex items-center rounded-lg bg-sky-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-sky-500">Create Role</a>
            </div>
        @endforelse
    </div>
</div>
@endsection
