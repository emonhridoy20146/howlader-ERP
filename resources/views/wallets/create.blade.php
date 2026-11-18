@extends('layouts.app')
@section('title', 'Create Wallet')
@section('page-title', 'Create Wallet')
@section('content')
<form action="{{ route('wallets.store') }}" method="post" class="space-y-4 rounded-2xl bg-white p-5 shadow-sm ring-1 ring-slate-200">
    @csrf
    <div class="rounded-lg border border-slate-200 bg-slate-50/80 p-3 text-sm text-slate-700">
        <p class="font-medium text-slate-800">Branches</p>
        <p class="mt-1 text-xs text-slate-600">Leave all unchecked to use this wallet at <strong>every</strong> branch. Otherwise choose one or more branches.</p>
        <div class="mt-3 grid gap-2 sm:grid-cols-2">
            @foreach($locations as $location)
                <label class="flex items-center gap-2 text-sm">
                    <input type="checkbox" name="location_ids[]" value="{{ $location->id }}" @checked(in_array((string) $location->id, array_map('strval', old('location_ids', [])), true))>
                    <span>{{ $location->name }}</span>
                </label>
            @endforeach
        </div>
        @error('location_ids')<p class="mt-2 text-xs text-rose-600">{{ $message }}</p>@enderror
        @error('location_ids.*')<p class="mt-2 text-xs text-rose-600">{{ $message }}</p>@enderror
    </div>
    <div class="grid gap-4 md:grid-cols-2">
        <div>
            <label class="text-sm font-medium">Kind</label>
            <select name="kind" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2" required>
                @foreach($kinds as $value => $label)
                    <option value="{{ $value }}" @selected(old('kind', 'cash') === $value)>{{ $label }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="text-sm font-medium">Code</label>
            <input name="code" value="{{ old('code') }}" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 font-mono text-sm uppercase" required maxlength="30" pattern="[A-Za-z0-9_-]+" placeholder="e.g. CASH1">
            @error('code')<p class="mt-1 text-xs text-rose-600">{{ $message }}</p>@enderror
        </div>
        <div class="md:col-span-2">
            <label class="text-sm font-medium">Name</label>
            <input name="name" value="{{ old('name') }}" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2" required>
        </div>
        <div>
            <label class="text-sm font-medium">Bank name (optional)</label>
            <input name="bank_name" value="{{ old('bank_name') }}" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2">
        </div>
        <div>
            <label class="text-sm font-medium">Account number (optional)</label>
            <input name="account_number" value="{{ old('account_number') }}" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2">
        </div>
        <div class="flex items-center gap-2 md:col-span-2">
            <input type="checkbox" name="is_active" value="1" @checked(old('is_active', true))>
            <span class="text-sm">Active</span>
        </div>
    </div>
    <div>
        <label class="text-sm font-medium">Notes</label>
        <textarea name="notes" rows="2" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2">{{ old('notes') }}</textarea>
    </div>
    <div><button class="rounded-lg bg-slate-900 px-4 py-2 text-white">Save</button></div>
</form>
@endsection
