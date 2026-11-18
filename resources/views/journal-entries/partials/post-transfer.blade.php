<div
    x-show="tab === 'transfer'"
    x-cloak
    class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm ring-1 ring-slate-200"
    x-data="{
        transferAmount: @js(old('entry_kind') === 'transfer' ? (string) old('amount', '') : ''),
        numberToWords(n) {
            if (n === 0) {
                return 'Zero';
            }

            const ones = ['', 'One', 'Two', 'Three', 'Four', 'Five', 'Six', 'Seven', 'Eight', 'Nine', 'Ten', 'Eleven', 'Twelve', 'Thirteen', 'Fourteen', 'Fifteen', 'Sixteen', 'Seventeen', 'Eighteen', 'Nineteen'];
            const tens = ['', '', 'Twenty', 'Thirty', 'Forty', 'Fifty', 'Sixty', 'Seventy', 'Eighty', 'Ninety'];
            const chunk = (num) => {
                let text = '';
                if (num >= 100) {
                    text += ones[Math.floor(num / 100)] + ' Hundred';
                    num %= 100;
                    if (num > 0) {
                        text += ' ';
                    }
                }
                if (num >= 20) {
                    text += tens[Math.floor(num / 10)];
                    if (num % 10 > 0) {
                        text += ' ' + ones[num % 10];
                    }
                } else if (num > 0) {
                    text += ones[num];
                }

                return text;
            };

            let amount = Math.floor(n);
            let words = '';
            const crore = Math.floor(amount / 10000000);
            amount %= 10000000;
            const lakh = Math.floor(amount / 100000);
            amount %= 100000;
            const thousand = Math.floor(amount / 1000);
            amount %= 1000;

            if (crore > 0) {
                words += chunk(crore) + ' Crore ';
            }
            if (lakh > 0) {
                words += chunk(lakh) + ' Lakh ';
            }
            if (thousand > 0) {
                words += chunk(thousand) + ' Thousand ';
            }
            if (amount > 0) {
                words += chunk(amount);
            }

            return words.trim();
        },
        amountInWords(value) {
            const num = Math.round((parseFloat(value) || 0) * 100) / 100;
            if (!Number.isFinite(num) || num <= 0) {
                return '';
            }

            const taka = Math.floor(num);
            const poisha = Math.round((num - taka) * 100);
            let text = this.numberToWords(taka) + ' Taka';
            if (poisha > 0) {
                text += ' and ' + this.numberToWords(poisha) + ' Poisha';
            }

            return text + ' Only';
        },
    }"
>
    <div class="border-b border-slate-200 bg-slate-50/80 px-5 py-4 sm:px-6">
        <h2 class="text-base font-semibold text-slate-900">Wallet transfer</h2>
        <p class="mt-1 text-xs text-slate-500">Move funds from one wallet to another at the same branch.</p>
    </div>

    <form action="{{ route('journal-entries.store') }}" method="post" class="space-y-5 p-5 sm:p-6">
        @csrf
        <input type="hidden" name="entry_kind" value="transfer">
        <input type="hidden" name="branch_id" :value="branchId">
        <input type="hidden" name="entry_date" :value="entryDate">

        <div class="grid gap-4 sm:grid-cols-2">
            <div>
                <label for="tr-from" class="block text-sm font-medium text-slate-700">From wallet</label>
                <select name="from_wallet_id" id="tr-from" data-wallet-role="transfer-from" class="journal-wallet-select mt-1.5 block w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm shadow-sm" required></select>
                @error('from_wallet_id')<p class="mt-1.5 text-xs text-rose-600">{{ $message }}</p>@enderror
            </div>
            <div>
                <label for="tr-to" class="block text-sm font-medium text-slate-700">To wallet</label>
                <select name="to_wallet_id" id="tr-to" data-wallet-role="transfer-to" class="journal-wallet-select mt-1.5 block w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm shadow-sm" required></select>
                @error('to_wallet_id')<p class="mt-1.5 text-xs text-rose-600">{{ $message }}</p>@enderror
            </div>
        </div>

        <div class="grid gap-4 sm:grid-cols-2">
            <div>
                <label for="tr-amount" class="block text-sm font-medium text-slate-700">Amount</label>
                <input id="tr-amount" type="number" step="0.01" min="0.01" name="amount" x-model="transferAmount" class="mt-1.5 block w-full rounded-lg border border-slate-300 px-3 py-2 font-mono text-sm shadow-sm" placeholder="0.00" required>
                <p class="mt-1.5 min-h-[1.25rem] text-xs italic text-slate-600" x-show="amountInWords(transferAmount)" x-text="amountInWords(transferAmount)"></p>
                @error('amount')<p class="mt-1.5 text-xs text-rose-600">{{ $message }}</p>@enderror
            </div>
            <div>
                <label for="tr-memo" class="block text-sm font-medium text-slate-700">Memo <span class="font-normal text-slate-400">(optional)</span></label>
                <input id="tr-memo" name="memo" value="{{ old('entry_kind') === 'transfer' ? old('memo') : '' }}" maxlength="500" class="mt-1.5 block w-full rounded-lg border border-slate-300 px-3 py-2 text-sm shadow-sm" placeholder="Reason for transfer">
            </div>
        </div>

        @if($errors->any() && old('entry_kind') === 'transfer')
            <div class="rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-800">
                <ul class="list-disc space-y-1 pl-5">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <div class="flex flex-wrap items-center justify-between gap-3 border-t border-slate-100 pt-5">
            <a href="{{ route('journal-entries.index', $journalIndexQuery) }}" class="text-sm font-medium text-slate-500 hover:text-slate-900">Cancel</a>
            <button type="submit" class="rounded-lg bg-slate-900 px-5 py-2.5 text-sm font-semibold text-white shadow-sm hover:bg-slate-800">Post transfer</button>
        </div>
    </form>
</div>
