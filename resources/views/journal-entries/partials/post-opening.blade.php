<div x-show="tab === 'opening'" x-cloak class="overflow-hidden rounded-2xl border border-emerald-200 bg-white shadow-sm ring-1 ring-emerald-900/10">
    <div class="border-b border-emerald-100 bg-emerald-50/80 px-5 py-4 sm:px-6">
        <h2 class="text-base font-semibold text-emerald-950">Opening balance</h2>
        <p class="mt-1 text-xs text-emerald-800/80">Debit the wallet and credit opening equity for initial float.</p>
    </div>

    <form action="{{ route('journal-entries.store') }}" method="post" class="space-y-5 p-5 sm:p-6">
        @csrf
        <input type="hidden" name="entry_kind" value="wallet_opening">
        <input type="hidden" name="branch_id" :value="branchId">
        <input type="hidden" name="entry_date" :value="entryDate">

        <div class="grid gap-4 sm:grid-cols-2">
            <div>
                <label for="ob-wallet" class="block text-sm font-medium text-emerald-950">Wallet</label>
                <select name="wallet_id" id="ob-wallet" data-wallet-role="opening" data-initial-wallet="{{ $obOpeningInitialWallet }}" class="journal-wallet-select mt-1.5 block w-full rounded-lg border border-emerald-200 bg-emerald-50/40 px-3 py-2 text-sm shadow-sm" required></select>
                @error('wallet_id')<p class="mt-1.5 text-xs text-rose-600">{{ $message }}</p>@enderror
            </div>
            <div>
                <label for="ob-amount" class="block text-sm font-medium text-emerald-950">Opening amount</label>
                <input id="ob-amount" type="number" step="0.01" min="0.01" name="amount" value="{{ old('entry_kind') === 'wallet_opening' ? old('amount') : '' }}" class="mt-1.5 block w-full rounded-lg border border-emerald-200 bg-emerald-50/40 px-3 py-2 font-mono text-sm shadow-sm" placeholder="0.00" required>
                @error('amount')<p class="mt-1.5 text-xs text-rose-600">{{ $message }}</p>@enderror
            </div>
            <div class="sm:col-span-2">
                <label for="ob-memo" class="block text-sm font-medium text-emerald-950">Memo <span class="font-normal text-emerald-700/70">(optional)</span></label>
                <input id="ob-memo" name="memo" value="{{ old('entry_kind') === 'wallet_opening' ? old('memo') : '' }}" maxlength="500" class="mt-1.5 block w-full rounded-lg border border-emerald-200 bg-emerald-50/40 px-3 py-2 text-sm shadow-sm" placeholder="e.g. Physical cash count">
                @error('memo')<p class="mt-1.5 text-xs text-rose-600">{{ $message }}</p>@enderror
            </div>
        </div>

        @if($errors->any() && old('entry_kind') === 'wallet_opening')
            <div class="rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-800">
                <ul class="list-disc space-y-1 pl-5">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <div class="flex flex-wrap items-center justify-between gap-3 border-t border-emerald-100 pt-5">
            <a href="{{ route('journal-entries.index', $journalIndexQuery) }}" class="text-sm font-medium text-emerald-700 hover:text-emerald-950">Cancel</a>
            <button type="submit" class="rounded-lg bg-emerald-700 px-5 py-2.5 text-sm font-semibold text-white shadow-sm hover:bg-emerald-800">Post opening balance</button>
        </div>
    </form>
</div>
