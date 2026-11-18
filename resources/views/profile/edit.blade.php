@extends('layouts.app')

@section('title', 'My profile')
@section('page-title', 'My profile')

@section('content')
<div class="mx-auto max-w-3xl space-y-6">
    <nav class="text-sm text-slate-500">
        <span class="text-slate-700">Account</span>
        <span class="mx-1.5 text-slate-300" aria-hidden="true">/</span>
        <span class="text-slate-700">Profile</span>
    </nav>

    <div class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
        <div class="border-b border-slate-100 bg-gradient-to-r from-slate-50 to-indigo-50/40 px-6 py-5">
            <h2 class="text-xl font-semibold text-slate-900">Profile details</h2>
            <p class="mt-1 text-sm text-slate-600">Update how you appear in the app and your contact email. Roles and branch access are managed by an administrator.</p>
        </div>
    </div>

    @if (session('status'))
        <div class="rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-900 shadow-sm">
            {{ session('status') }}
        </div>
    @endif

    <form method="post" action="{{ route('profile.update') }}" class="space-y-6" enctype="multipart/form-data">
        @csrf
        @method('PUT')

        <section class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
            <div class="border-b border-slate-100 px-5 py-4">
                <h3 class="text-sm font-semibold text-slate-900">Your information</h3>
            </div>
            <div class="grid gap-4 p-5 sm:grid-cols-2">
                <x-model-photo-upload :existing-url="$user->photoUrl()" :label="__('Profile photo')" />
                <label class="block sm:col-span-2">
                    <span class="text-sm font-medium text-slate-700">Username</span>
                    <input name="name" value="{{ old('name', $user->name) }}" autocomplete="username" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm shadow-sm focus:border-slate-500 focus:outline-none focus:ring-2 focus:ring-slate-200" required>
                    @error('name')
                        <p class="mt-1 text-xs text-rose-600">{{ $message }}</p>
                    @enderror
                </label>
                <label class="block sm:col-span-2">
                    <span class="text-sm font-medium text-slate-700">Full name</span>
                    <input name="full_name" value="{{ old('full_name', $user->full_name) }}" autocomplete="name" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm shadow-sm focus:border-slate-500 focus:outline-none focus:ring-2 focus:ring-slate-200" placeholder="Optional">
                    @error('full_name')
                        <p class="mt-1 text-xs text-rose-600">{{ $message }}</p>
                    @enderror
                </label>
                <label class="block sm:col-span-2">
                    <span class="text-sm font-medium text-slate-700">Email</span>
                    <input type="email" name="email" value="{{ old('email', $user->email) }}" autocomplete="email" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm shadow-sm focus:border-slate-500 focus:outline-none focus:ring-2 focus:ring-slate-200" required>
                    @error('email')
                        <p class="mt-1 text-xs text-rose-600">{{ $message }}</p>
                    @enderror
                </label>
                <label class="block sm:col-span-2">
                    <span class="text-sm font-medium text-slate-700">Phone</span>
                    <input name="phone" value="{{ old('phone', $user->phone) }}" autocomplete="tel" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm shadow-sm focus:border-slate-500 focus:outline-none focus:ring-2 focus:ring-slate-200">
                    @error('phone')
                        <p class="mt-1 text-xs text-rose-600">{{ $message }}</p>
                    @enderror
                </label>
            </div>
        </section>

        <section class="overflow-hidden rounded-2xl border border-slate-200 bg-slate-50/60 shadow-sm">
            <div class="border-b border-slate-200/80 px-5 py-4">
                <h3 class="text-sm font-semibold text-slate-900">Access (read-only)</h3>
                <p class="mt-0.5 text-xs text-slate-500">Ask an administrator if you need different roles or branches.</p>
            </div>
            <dl class="grid gap-4 p-5 sm:grid-cols-2">
                <div>
                    <dt class="text-xs font-medium uppercase tracking-wide text-slate-500">Roles</dt>
                    <dd class="mt-1 text-sm text-slate-800">
                        @if ($user->roles->isEmpty())
                            <span class="text-slate-500">None assigned</span>
                        @else
                            <ul class="flex flex-wrap gap-1">
                                @foreach ($user->roles as $role)
                                    <li><span class="inline-flex rounded-full border border-indigo-200 bg-white px-2 py-0.5 text-xs font-medium text-indigo-900">{{ $role->name }}</span></li>
                                @endforeach
                            </ul>
                        @endif
                    </dd>
                </div>
                <div>
                    <dt class="text-xs font-medium uppercase tracking-wide text-slate-500">Default branch</dt>
                    <dd class="mt-1 text-sm text-slate-800">{{ $user->branch ? $user->branch->name : '—' }}</dd>
                </div>
                <div class="sm:col-span-2">
                    <dt class="text-xs font-medium uppercase tracking-wide text-slate-500">Accessible locations</dt>
                    <dd class="mt-1 text-sm text-slate-800">
                        @if ($user->accessibleLocations->isEmpty())
                            <span class="text-slate-500">None listed</span>
                        @else
                            <ul class="flex flex-wrap gap-1">
                                @foreach ($user->accessibleLocations as $loc)
                                    <li><span class="inline-flex rounded-full border border-emerald-200 bg-white px-2 py-0.5 text-xs font-medium text-emerald-900">{{ $loc->name }}</span></li>
                                @endforeach
                            </ul>
                        @endif
                    </dd>
                </div>
            </dl>
        </section>

        <div class="flex flex-wrap items-center justify-between gap-3">
            <a href="{{ route('profile.password.edit') }}" class="text-sm font-medium text-indigo-600 hover:text-indigo-800">Change password →</a>
            <button type="submit" class="rounded-lg bg-slate-900 px-5 py-2 text-sm font-medium text-white shadow-sm hover:bg-slate-800 focus:outline-none focus:ring-2 focus:ring-slate-400 focus:ring-offset-2">
                Save profile
            </button>
        </div>
    </form>
</div>
@endsection
