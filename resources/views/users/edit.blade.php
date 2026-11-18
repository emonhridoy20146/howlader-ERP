@extends('layouts.app')

@section('title', 'Edit user')
@section('page-title', 'Edit user')

@section('content')
<div class="mx-auto max-w-5xl space-y-6">
    <nav class="text-sm text-slate-500">
        <a href="{{ route('users.index') }}" class="font-medium text-indigo-600 hover:text-indigo-800">Users</a>
        <span class="mx-1.5 text-slate-300" aria-hidden="true">/</span>
        <span class="text-slate-700">Edit</span>
    </nav>

    <div class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
        <div class="flex flex-col gap-4 border-b border-slate-100 bg-gradient-to-r from-slate-50 to-emerald-50/30 px-6 py-5 sm:flex-row sm:items-start sm:justify-between">
            <div class="min-w-0">
                <p class="text-xs font-semibold uppercase tracking-[0.2em] text-slate-500">Administration</p>
                <h2 class="mt-1 truncate text-xl font-semibold text-slate-900">Edit user</h2>
                <p class="mt-1 text-sm text-slate-600">
                    <span class="font-medium text-slate-800">{{ $user->displayName() }}</span>
                    <span class="text-slate-400">·</span>
                    <span class="break-all text-slate-500">{{ $user->email }}</span>
                </p>
            </div>
            <div class="flex shrink-0 flex-col items-stretch gap-2 sm:items-end">
                <div class="rounded-xl border border-slate-200 bg-white px-3 py-2 text-xs text-slate-600 shadow-sm">
                    <p class="font-medium text-slate-800">User ID</p>
                    <p class="mt-0.5 font-mono text-slate-500">{{ $user->id }}</p>
                </div>
                @include('users.partials.impersonate-action', [
                    'user' => $user,
                    'label' => 'Login as this user',
                    'buttonClass' => 'inline-flex items-center justify-center rounded-xl border border-indigo-200 bg-indigo-50 px-4 py-2 text-sm font-medium text-indigo-900 shadow-sm transition hover:bg-indigo-100',
                ])
            </div>
        </div>
    </div>

    @include('users.partials.form', ['action' => route('users.update', $user), 'method' => 'PUT', 'user' => $user])
</div>
@endsection
