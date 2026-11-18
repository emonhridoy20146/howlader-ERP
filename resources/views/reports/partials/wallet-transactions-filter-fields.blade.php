<div class="space-y-4">
    <div>
        <label for="wallet_id" class="block text-sm font-medium leading-6 text-slate-900">Wallet (Head)</label>
        <div class="mt-2">
            <select id="wallet_id" name="wallet_id" class="block w-full rounded-md border-0 py-1.5 pl-3 pr-10 text-slate-900 ring-1 ring-inset ring-slate-300 focus:ring-2 focus:ring-emerald-600 sm:text-sm sm:leading-6">
                <option value="">Select a wallet</option>
                @foreach($filterWallets as $wallet)
                    <option value="{{ $wallet->id }}" @selected($filters['wallet_id'] === (string) $wallet->id)>{{ $wallet->name }} ({{ $wallet->code }})</option>
                @endforeach
            </select>
        </div>
    </div>
    
    <div>
        <label for="date_from" class="block text-sm font-medium leading-6 text-slate-900">Date From</label>
        <div class="mt-2">
            <input type="date" name="date_from" id="date_from" value="{{ $filters['date_from'] }}" class="block w-full rounded-md border-0 py-1.5 text-slate-900 ring-1 ring-inset ring-slate-300 placeholder:text-slate-400 focus:ring-2 focus:ring-inset focus:ring-emerald-600 sm:text-sm sm:leading-6">
        </div>
    </div>
    
    <div>
        <label for="date_to" class="block text-sm font-medium leading-6 text-slate-900">Date To</label>
        <div class="mt-2">
            <input type="date" name="date_to" id="date_to" value="{{ $filters['date_to'] }}" class="block w-full rounded-md border-0 py-1.5 text-slate-900 ring-1 ring-inset ring-slate-300 placeholder:text-slate-400 focus:ring-2 focus:ring-inset focus:ring-emerald-600 sm:text-sm sm:leading-6">
        </div>
    </div>

    <div>
        <label for="source" class="block text-sm font-medium leading-6 text-slate-900">Source</label>
        <div class="mt-2">
            <select id="source" name="source" class="block w-full rounded-md border-0 py-1.5 pl-3 pr-10 text-slate-900 ring-1 ring-inset ring-slate-300 focus:ring-2 focus:ring-emerald-600 sm:text-sm sm:leading-6">
                <option value="">All sources</option>
                @foreach (\App\Models\JournalEntry::sources() as $value => $label)
                    <option value="{{ $value }}" @selected(($filters['source'] ?? '') === $value)>{{ $label }}</option>
                @endforeach
            </select>
        </div>
    </div>
</div>
