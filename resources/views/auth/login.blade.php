@extends('layouts.auth')

@section('title', 'Login')

@section('content')
    <div class="text-center">
        <div class="mb-5 flex flex-col items-center gap-3">
        
            <p class="text-lg font-semibold tracking-tight text-slate-900">Howloader Electronics</p>
        </div>
        <p class="text-[11px] font-semibold uppercase tracking-[0.22em] text-slate-500">Secure access</p>
        <h1 class="mt-3 text-2xl font-semibold tracking-tight text-slate-900">Sign in</h1>
        <p class="mt-2 text-sm text-slate-600">Use your assigned account credentials.</p>
    </div>

    <form action="{{ route('login.attempt') }}" method="post" class="mt-8 space-y-5">
        @csrf

        <div>
            <label for="email" class="text-sm font-medium text-slate-800">Email</label>
            <input
                id="email"
                name="email"
                type="email"
                value="{{ old('email') }}"
                required
                autofocus
                class="mt-1.5 w-full rounded-xl border border-slate-200 bg-slate-50/80 px-3.5 py-2.5 text-slate-900 shadow-inner shadow-slate-900/5 outline-none transition placeholder:text-slate-400 focus:border-amber-400/80 focus:bg-white focus:ring-2 focus:ring-amber-400/30"
                placeholder="you@company.com"
            >
            @error('email')
                <p class="mt-1.5 text-sm text-rose-600">{{ $message }}</p>
            @enderror
        </div>

        <div>
            <label for="password" class="text-sm font-medium text-slate-800">Password</label>
            <input
                id="password"
                name="password"
                type="password"
                required
                class="mt-1.5 w-full rounded-xl border border-slate-200 bg-slate-50/80 px-3.5 py-2.5 text-slate-900 shadow-inner shadow-slate-900/5 outline-none transition focus:border-amber-400/80 focus:bg-white focus:ring-2 focus:ring-amber-400/30"
                placeholder="••••••••"
            >
        </div>

        <label class="flex cursor-pointer items-center gap-2.5 text-sm text-slate-600">
            <input type="checkbox" name="remember" value="1" class="size-4 rounded border-slate-300 text-amber-600 focus:ring-amber-500/40">
            Keep me signed in
        </label>

        <button type="submit" class="w-full rounded-xl bg-slate-900 px-4 py-3 text-sm font-semibold text-white shadow-md shadow-slate-900/20 transition hover:bg-slate-800 focus:outline-none focus:ring-2 focus:ring-slate-900 focus:ring-offset-2">
            Sign in
        </button>
    </form>
@endsection
