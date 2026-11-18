@extends('layouts.app')

@section('title', 'Create user')
@section('page-title', 'Create user')

@section('content')
<div class="mx-auto max-w-5xl space-y-6">
    <nav class="text-sm text-slate-500">
        <a href="{{ route('users.index') }}" class="font-medium text-indigo-600 hover:text-indigo-800">Users</a>
        <span class="mx-1.5 text-slate-300" aria-hidden="true">/</span>
        <span class="text-slate-700">New</span>
    </nav>

    <div class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
        <div class="border-b border-slate-100 bg-gradient-to-r from-slate-50 to-indigo-50/40 px-6 py-5">
            <p class="text-xs font-semibold uppercase tracking-[0.2em] text-slate-500">Administration</p>
            <h2 class="mt-1 text-xl font-semibold text-slate-900">Create user</h2>
            <p class="mt-1 text-sm text-slate-600">Add an account, choose roles, and pick which branches this person can access.</p>
        </div>
    </div>

    @include('users.partials.form', ['action' => route('users.store'), 'method' => 'POST', 'user' => null])
</div>
@endsection
