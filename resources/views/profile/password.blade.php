@extends('layouts.app')

@section('title', 'Change password')
@section('page-title', 'Change password')

@section('content')
<div class="mx-auto max-w-lg space-y-6">
    <nav class="text-sm text-slate-500">
        <span class="text-slate-700">Account</span>
        <span class="mx-1.5 text-slate-300" aria-hidden="true">/</span>
        <span class="text-slate-700">Password</span>
    </nav>

    <div class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
        <div class="border-b border-slate-100 bg-gradient-to-r from-slate-50 to-amber-50/40 px-6 py-5">
            <h2 class="text-xl font-semibold text-slate-900">Change password</h2>
            <p class="mt-1 text-sm text-slate-600">Enter your current password, then choose a new one (at least 8 characters).</p>
        </div>
    </div>

    @if (session('status'))
        <div class="rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-900 shadow-sm">
            {{ session('status') }}
        </div>
    @endif

    <form method="post" action="{{ route('profile.password.update') }}" class="space-y-5 rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
        @csrf
        @method('PUT')

        <label class="block">
            <span class="text-sm font-medium text-slate-700">Current password</span>
            <input type="password" name="current_password" autocomplete="current-password" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm shadow-sm focus:border-slate-500 focus:outline-none focus:ring-2 focus:ring-slate-200" required>
            @error('current_password')
                <p class="mt-1 text-xs text-rose-600">{{ $message }}</p>
            @enderror
        </label>
        <label class="block">
            <span class="text-sm font-medium text-slate-700">New password</span>
            <input type="password" name="password" autocomplete="new-password" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm shadow-sm focus:border-slate-500 focus:outline-none focus:ring-2 focus:ring-slate-200" required minlength="8">
            @error('password')
                <p class="mt-1 text-xs text-rose-600">{{ $message }}</p>
            @enderror
        </label>
        <label class="block">
            <span class="text-sm font-medium text-slate-700">Confirm new password</span>
            <input type="password" name="password_confirmation" autocomplete="new-password" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm shadow-sm focus:border-slate-500 focus:outline-none focus:ring-2 focus:ring-slate-200" required minlength="8">
        </label>

        <div class="flex flex-wrap items-center justify-between gap-3 border-t border-slate-100 pt-5">
            <a href="{{ route('profile.edit') }}" class="text-sm font-medium text-slate-600 hover:text-slate-900">← Back to profile</a>
            <button type="submit" class="rounded-lg bg-slate-900 px-5 py-2 text-sm font-medium text-white shadow-sm hover:bg-slate-800 focus:outline-none focus:ring-2 focus:ring-slate-400 focus:ring-offset-2">
                Update password
            </button>
        </div>
    </form>
</div>
@endsection
