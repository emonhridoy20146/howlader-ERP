@extends('layouts.app')
@section('title', 'Post journal')
@section('page-title', 'Post journal entry')

@php
    $defaultBranchId = (string) (old('branch_id') ?: $journalDefaultBranchId);
    $defaultEntryDate = old('entry_date', now()->toDateString());
    $journalIndexQuery = array_filter(['wallet_id' => request()->query('wallet_id')], fn ($v) => $v !== null && $v !== '');
    $obOpeningInitialWallet = old('entry_kind') === 'wallet_opening' ? (string) old('wallet_id', '') : ($prefillWalletId !== null ? (string) $prefillWalletId : '');

    $tabForKind = [
        'expense' => 'expense',
        'income' => 'income',
        'transfer' => 'transfer',
        'wallet_opening' => 'opening',
    ];
    $initialTab = $tabForKind[$initialKind] ?? 'expense';

    $postTabs = collect([
        'expense' => ['kind' => 'expense', 'label' => 'Operating expense', 'hint' => 'Pay from wallet'],
        'income' => ['kind' => 'income', 'label' => 'Other income', 'hint' => 'Deposit to wallet'],
        'transfer' => ['kind' => 'transfer', 'label' => 'Wallet transfer', 'hint' => 'Between two wallets'],
        'opening' => ['kind' => 'wallet_opening', 'label' => 'Opening balance', 'hint' => 'Initial wallet float'],
    ])->filter(fn (array $tab) => in_array($tab['kind'], $allowedKinds, true));
@endphp

@section('content')
<script type="application/json" id="wallets-json">@json($walletsGrouped)</script>
<script type="application/json" id="expense-accounts-json">@json($expenseAccounts)</script>
<script type="application/json" id="income-accounts-json">@json($incomeAccounts)</script>

<div
    class="mx-auto w-full max-w-4xl space-y-5"
    x-data="{
        tab: @js($initialTab),
        branchId: @js($defaultBranchId),
        entryDate: @js($defaultEntryDate),
        kindForTab(value) {
            return ({ expense: 'expense', income: 'income', transfer: 'transfer', opening: 'wallet_opening' })[value] ?? 'expense';
        },
    }"
>
    @if ($prefillWalletSummary)
        <div class="rounded-xl border border-sky-200 bg-sky-50 px-4 py-3 text-sm text-sky-950 shadow-sm">
            <p class="font-medium">Wallet: {{ $prefillWalletSummary }}</p>
            <p class="mt-1 text-xs text-sky-900/85">Branch is preset from this wallet. Change it below if the wallet is shared across branches.</p>
        </div>
    @endif

    @cannot('manage-owner-capital-wallet')
        <div class="rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-950">
            <p class="font-medium">{{ $ownerCapitalWalletCode }} owner wallet</p>
            <p class="mt-1 text-xs text-amber-900/90">You cannot pay expenses from or post opening balances to {{ $ownerCapitalWalletCode }}. You can deposit other income to that wallet, or transfer funds <strong>into</strong> it from another wallet.</p>
        </div>
    @endcannot

    <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm ring-1 ring-slate-200">
        <h2 class="text-sm font-semibold text-slate-900">Transaction details</h2>
        <p class="mt-1 text-xs text-slate-500">Branch and date apply to whichever entry type you post.</p>
        <div class="mt-4 grid gap-4 sm:grid-cols-2">
            <label class="block">
                <span class="text-sm font-medium text-slate-700">Branch</span>
                <select
                    id="journal-shared-branch"
                    x-model="branchId"
                    class="mt-1.5 block w-full rounded-lg border border-slate-300 bg-slate-50 px-3 py-2 text-sm shadow-sm focus:border-slate-500 focus:outline-none focus:ring-2 focus:ring-slate-200"
                >
                    @foreach ($locations as $location)
                        <option value="{{ $location->id }}">{{ $location->name }}</option>
                    @endforeach
                </select>
            </label>
            <label class="block">
                <span class="text-sm font-medium text-slate-700">Date</span>
                <input
                    id="journal-shared-date"
                    type="date"
                    x-model="entryDate"
                    class="mt-1.5 block w-full rounded-lg border border-slate-300 bg-slate-50 px-3 py-2 text-sm shadow-sm focus:border-slate-500 focus:outline-none focus:ring-2 focus:ring-slate-200"
                >
            </label>
        </div>
    </div>

    @if ($postTabs->count() > 1)
        <div class="rounded-xl border border-slate-200 bg-slate-50 p-1 sm:p-1.5" role="tablist" aria-label="Journal entry type">
            <div class="grid grid-cols-1 gap-1 sm:grid-cols-{{ $postTabs->count() }}">
                @foreach ($postTabs as $tabKey => $tab)
                    <button
                        type="button"
                        role="tab"
                        :aria-selected="tab === @js($tabKey)"
                        @click="tab = @js($tabKey)"
                        :class="tab === @js($tabKey) ? 'bg-white text-slate-900 shadow-sm ring-1 ring-slate-200' : 'text-slate-600 hover:bg-white/60 hover:text-slate-900'"
                        class="rounded-lg px-3 py-2.5 text-left text-sm font-medium transition sm:text-center"
                    >
                        <span class="block">{{ $tab['label'] }}</span>
                        <span class="mt-0.5 block text-[0.65rem] font-normal text-slate-500 sm:mt-0">{{ $tab['hint'] }}</span>
                    </button>
                @endforeach
            </div>
        </div>
    @endif

    @if (in_array('expense', $allowedKinds, true))
        @include('journal-entries.partials.post-expense', ['journalIndexQuery' => $journalIndexQuery])
    @endif

    @if (in_array('income', $allowedKinds, true))
        @include('journal-entries.partials.post-income', ['journalIndexQuery' => $journalIndexQuery])
    @endif

    @if (in_array('transfer', $allowedKinds, true))
        @include('journal-entries.partials.post-transfer', ['journalIndexQuery' => $journalIndexQuery])
    @endif

    @if (in_array('wallet_opening', $allowedKinds, true))
        @include('journal-entries.partials.post-opening', [
            'journalIndexQuery' => $journalIndexQuery,
            'obOpeningInitialWallet' => $obOpeningInitialWallet,
        ])
    @endif
</div>

@include('journal-entries.partials.post-wallet-sync', [
    'prefillWalletId' => $prefillWalletId,
    'obOpeningInitialWallet' => $obOpeningInitialWallet,
])
@endsection
