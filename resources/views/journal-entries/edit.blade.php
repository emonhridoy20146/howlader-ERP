@extends('layouts.app')
@section('title', 'Edit '.$entry->document_no)
@section('page-title', 'Edit journal entry')

@section('content')
@php
    $isTransfer = $formData['source'] === 'manual_transfer';
    $indexQuery = array_filter(['wallet_id' => request()->query('wallet_id')], fn ($v) => $v !== null && $v !== '');
@endphp

<div class="mx-auto w-full max-w-xl space-y-5">
    <div class="flex flex-wrap items-center gap-3 text-sm">
        <a href="{{ route('journal-entries.index', $indexQuery) }}" class="text-slate-600 underline">← Back to journal</a>
        <span class="text-slate-400">·</span>
        <a href="{{ route('journal-entries.show', $entry) }}" class="text-slate-600 underline">{{ $entry->document_no }}</a>
    </div>

    @cannot('manage-owner-capital-wallet')
        <div class="rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-950">
            <p class="font-medium">{{ $ownerCapitalWalletCode }} owner wallet</p>
            <p class="mt-1 text-xs text-amber-900/90">You cannot pay expenses from or post opening balances to {{ $ownerCapitalWalletCode }}.</p>
        </div>
    @endcannot

    <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm ring-1 ring-slate-200">
        <div class="mb-4">
            <p class="text-xs font-medium uppercase tracking-wide text-slate-500">Entry type</p>
            <p class="mt-1 text-sm font-semibold text-slate-900">{{ $sources[$formData['source']] ?? $formData['source'] }}</p>
            <p class="mt-1 text-xs text-slate-500">Branch: {{ $entry->branch?->name }} · {{ $entry->status === 'draft' ? 'Draft (not yet in wallet balance)' : 'Posted' }}</p>
        </div>

        <form method="post" action="{{ route('journal-entries.update', $entry).($indexQuery ? '?'.http_build_query($indexQuery) : '') }}" class="space-y-4">
            @csrf
            @method('PUT')

            <label class="block">
                <span class="text-sm font-medium text-slate-700">Date</span>
                <input
                    type="date"
                    name="entry_date"
                    value="{{ old('entry_date', $formData['entry_date']) }}"
                    required
                    class="mt-1.5 block w-full rounded-lg border border-slate-300 bg-slate-50 px-3 py-2 text-sm shadow-sm focus:border-slate-500 focus:outline-none focus:ring-2 focus:ring-slate-200"
                >
                @error('entry_date')
                    <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
                @enderror
            </label>

            @if ($isTransfer)
                <label class="block">
                    <span class="text-sm font-medium text-slate-700">From wallet</span>
                    <select name="from_wallet_id" required class="mt-1.5 block w-full rounded-lg border border-slate-300 bg-slate-50 px-3 py-2 text-sm shadow-sm focus:border-slate-500 focus:outline-none focus:ring-2 focus:ring-slate-200">
                        @foreach ($wallets as $wallet)
                            <option value="{{ $wallet->id }}" @selected((string) old('from_wallet_id', $formData['from_wallet_id'] ?? '') === (string) $wallet->id)>{{ $wallet->code }} — {{ $wallet->name }}</option>
                        @endforeach
                    </select>
                    @error('from_wallet_id')
                        <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
                    @enderror
                </label>

                <label class="block">
                    <span class="text-sm font-medium text-slate-700">To wallet</span>
                    <select name="to_wallet_id" required class="mt-1.5 block w-full rounded-lg border border-slate-300 bg-slate-50 px-3 py-2 text-sm shadow-sm focus:border-slate-500 focus:outline-none focus:ring-2 focus:ring-slate-200">
                        @foreach ($wallets as $wallet)
                            <option value="{{ $wallet->id }}" @selected((string) old('to_wallet_id', $formData['to_wallet_id'] ?? '') === (string) $wallet->id)>{{ $wallet->code }} — {{ $wallet->name }}</option>
                        @endforeach
                    </select>
                    @error('to_wallet_id')
                        <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
                    @enderror
                </label>
            @else
                <label class="block">
                    <span class="text-sm font-medium text-slate-700">Wallet</span>
                    <select name="wallet_id" required class="mt-1.5 block w-full rounded-lg border border-slate-300 bg-slate-50 px-3 py-2 text-sm shadow-sm focus:border-slate-500 focus:outline-none focus:ring-2 focus:ring-slate-200">
                        @foreach ($wallets as $wallet)
                            <option value="{{ $wallet->id }}" @selected((string) old('wallet_id', $formData['wallet_id'] ?? '') === (string) $wallet->id)>{{ $wallet->code }} — {{ $wallet->name }}</option>
                        @endforeach
                    </select>
                    @error('wallet_id')
                        <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
                    @enderror
                </label>
            @endif

            <label class="block">
                <span class="text-sm font-medium text-slate-700">Amount</span>
                <input
                    type="number"
                    name="amount"
                    value="{{ old('amount', $formData['amount']) }}"
                    step="0.01"
                    min="0.01"
                    required
                    class="mt-1.5 block w-full rounded-lg border border-slate-300 bg-slate-50 px-3 py-2 text-sm shadow-sm focus:border-slate-500 focus:outline-none focus:ring-2 focus:ring-slate-200"
                >
                @error('amount')
                    <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
                @enderror
            </label>

            <label class="block">
                <span class="text-sm font-medium text-slate-700">Description</span>
                <input
                    type="text"
                    name="description"
                    value="{{ old('description', $formData['description']) }}"
                    maxlength="500"
                    class="mt-1.5 block w-full rounded-lg border border-slate-300 bg-slate-50 px-3 py-2 text-sm shadow-sm focus:border-slate-500 focus:outline-none focus:ring-2 focus:ring-slate-200"
                >
                @error('description')
                    <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
                @enderror
            </label>

            <p class="text-xs text-slate-500">Changing the wallet or amount updates the underlying journal lines. Posted entries immediately affect wallet balances.</p>

            <div class="flex flex-wrap gap-3 pt-2">
                <button type="submit" class="rounded-lg bg-slate-900 px-4 py-2 text-sm font-medium text-white shadow-sm hover:bg-slate-800">Save changes</button>
                <a href="{{ route('journal-entries.index', $indexQuery) }}" class="rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm font-medium text-slate-700 shadow-sm hover:bg-slate-50">Cancel</a>
            </div>
        </form>
    </div>
</div>
@endsection
