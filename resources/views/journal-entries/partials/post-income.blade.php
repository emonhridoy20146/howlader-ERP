<div x-show="tab === 'income'" x-cloak class="rounded-2xl border border-slate-200 bg-white shadow-sm ring-1 ring-slate-200" x-data="{
    incomeLines: [ { id: Date.now(), ledger_account_id: '', amount: '', memo: '' } ],
    incomeAccounts: JSON.parse(document.getElementById('income-accounts-json').textContent),
    incomeAccountLabel(accountId) {
        const acc = this.incomeAccounts.find((row) => String(row.id) === String(accountId));
        return acc ? `${acc.code} — ${acc.name}` : 'Select account head…';
    },
    filterIncomeAccounts(search) {
        const query = String(search || '').trim().toLowerCase();
        if (query === '') {
            return this.incomeAccounts;
        }

        return this.incomeAccounts.filter((acc) => {
            const haystack = `${acc.code} ${acc.name}`.toLowerCase();
            return haystack.includes(query);
        });
    },
    showLedgerModal: false,
    isSubmittingLedger: false,
    ledgerErrors: {},
    newLedger: { code: '', name: '', type: 'income', is_active: true },
    addIncomeLine() {
        this.incomeLines.push({ id: Date.now(), ledger_account_id: '', amount: '', memo: '' });
    },
    removeIncomeLine(index) {
        if (this.incomeLines.length > 1) {
            this.incomeLines.splice(index, 1);
        }
    },
    get totalAmount() {
        return this.incomeLines.reduce((sum, row) => sum + (parseFloat(row.amount) || 0), 0);
    },
    formatCurrency(amount) {
        return new Intl.NumberFormat(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(amount);
    },
    openLedgerModal() {
        this.showLedgerModal = true;
        this.newLedger = { code: '', name: '', type: 'income', is_active: true };
        this.ledgerErrors = {};
    },
    closeLedgerModal() {
        this.showLedgerModal = false;
    },
    async submitLedger() {
        this.isSubmittingLedger = true;
        this.ledgerErrors = {};
        try {
            const response = await fetch('{{ route('ledger-accounts.store') }}', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': '{{ csrf_token() }}'
                },
                body: JSON.stringify(this.newLedger)
            });
            const result = await response.json();
            if (!response.ok) {
                if (response.status === 422) {
                    this.ledgerErrors = result.errors || {};
                } else {
                    alert(result.message || 'An error occurred.');
                }
            } else {
                this.incomeAccounts.push(result.account);
                this.incomeAccounts.sort((a, b) => a.code.localeCompare(b.code));
                this.incomeLines[this.incomeLines.length - 1].ledger_account_id = result.account.id;
                this.closeLedgerModal();
            }
        } catch (e) {
            alert('A network error occurred.');
        } finally {
            this.isSubmittingLedger = false;
        }
    }
}">
    <div class="border-b border-slate-200 bg-slate-50/80 px-5 py-4 sm:px-6">
        <h2 class="text-base font-semibold text-slate-900">Other income</h2>
        <p class="mt-1 text-xs text-slate-500">Debit the receiving wallet and credit income account(s).</p>
    </div>

    <form action="{{ route('journal-entries.store') }}" method="post" class="p-5 sm:p-6 space-y-5">
        @csrf
        <input type="hidden" name="entry_kind" value="income">
        <input type="hidden" name="branch_id" :value="branchId">
        <input type="hidden" name="entry_date" :value="entryDate">

        <div>
            <label for="inc-wallet" class="block text-sm font-medium text-slate-700">Deposit to wallet</label>
            <select name="wallet_id" id="inc-wallet" data-wallet-role="income-to" class="journal-wallet-select mt-1.5 block w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm shadow-sm focus:border-slate-500 focus:outline-none focus:ring-2 focus:ring-slate-200" required></select>
            @error('wallet_id')<p class="mt-1.5 text-xs text-rose-600">{{ $message }}</p>@enderror
        </div>

        <div class="rounded-xl border border-slate-200">
            <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-slate-200">
                <thead class="bg-slate-50">
                    <tr>
                        <th scope="col" class="min-w-[14rem] py-2.5 pl-4 pr-3 text-left text-xs font-semibold text-slate-600 sm:pl-5">Account head</th>
                        <th scope="col" class="px-3 py-2.5 text-left text-xs font-semibold text-slate-600">Memo</th>
                        <th scope="col" class="px-3 py-2.5 text-right text-xs font-semibold text-slate-600">Amount</th>
                        <th scope="col" class="w-10 py-2.5 pr-4 sm:pr-5"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100 bg-white">
                    <template x-for="(line, index) in incomeLines" :key="line.id">
                        <tr
                            x-data="{
                                open: false,
                                search: '',
                                panelStyle: '',
                                positionPanel() {
                                    const btn = this.$refs.accountTrigger;
                                    if (! btn) {
                                        return;
                                    }
                                    const rect = btn.getBoundingClientRect();
                                    const maxHeight = 240;
                                    const gap = 4;
                                    const spaceBelow = window.innerHeight - rect.bottom - gap;
                                    const spaceAbove = rect.top - gap;
                                    const openUpward = spaceBelow < 160 && spaceAbove > spaceBelow;
                                    const height = Math.min(maxHeight, Math.max(120, openUpward ? spaceAbove : spaceBelow));
                                    const top = openUpward ? rect.top - height - gap : rect.bottom + gap;
                                    this.panelStyle = `top:${Math.max(gap, top)}px;left:${rect.left}px;width:${rect.width}px;max-height:${height}px;`;
                                },
                                togglePanel() {
                                    this.open = ! this.open;
                                    if (this.open) {
                                        this.$nextTick(() => this.positionPanel());
                                    } else {
                                        this.search = '';
                                    }
                                },
                                closePanel() {
                                    this.open = false;
                                    this.search = '';
                                },
                            }"
                            @click.outside="closePanel()"
                            @scroll.window="if (open) positionPanel()"
                            @resize.window="if (open) positionPanel()"
                        >
                            <td class="py-3 pl-4 pr-3 align-top sm:pl-5">
                                <div class="relative min-w-[12rem]">
                                    <input type="hidden" :name="`income_lines[${index}][ledger_account_id]`" x-model="line.ledger_account_id" required>

                                    <button
                                        type="button"
                                        x-ref="accountTrigger"
                                        @click="togglePanel()"
                                        class="flex w-full items-center justify-between rounded-lg border border-slate-300 bg-white px-2 py-2 text-left text-sm focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500"
                                        :class="open ? 'ring-1 ring-indigo-500 border-indigo-500' : ''"
                                    >
                                        <span class="truncate" :class="line.ledger_account_id ? 'text-slate-800' : 'text-slate-500'" x-text="incomeAccountLabel(line.ledger_account_id)"></span>
                                        <svg class="h-4 w-4 shrink-0 text-slate-400 transition-transform" :class="open ? 'rotate-180' : ''" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
                                        </svg>
                                    </button>

                                    <div
                                        x-cloak
                                        x-show="open"
                                        x-transition:enter="transition ease-out duration-100"
                                        x-transition:enter-start="opacity-0 scale-95"
                                        x-transition:enter-end="opacity-100 scale-100"
                                        x-transition:leave="transition ease-in duration-75"
                                        x-transition:leave-start="opacity-100 scale-100"
                                        x-transition:leave-end="opacity-0 scale-95"
                                        class="fixed z-[200] overflow-auto rounded-lg border border-slate-200 bg-white py-1 shadow-xl ring-1 ring-slate-200/80 overscroll-contain"
                                        :style="panelStyle"
                                    >
                                        <div class="sticky top-0 z-10 mb-1 border-b border-slate-100 bg-white px-2 pb-2 pt-1">
                                            <input
                                                type="text"
                                                x-model="search"
                                                placeholder="Search code or account head…"
                                                class="w-full rounded-md border border-slate-300 bg-slate-50 px-3 py-1.5 text-sm focus:border-indigo-500 focus:bg-white focus:outline-none focus:ring-1 focus:ring-indigo-500"
                                                @keydown.escape.stop="closePanel()"
                                            >
                                        </div>

                                        <template x-for="acc in filterIncomeAccounts(search)" :key="acc.id">
                                            <button
                                                type="button"
                                                @click="line.ledger_account_id = String(acc.id); closePanel()"
                                                class="w-full px-3 py-2 text-left text-sm text-slate-800 transition-colors hover:bg-slate-50"
                                                :class="String(line.ledger_account_id) === String(acc.id) ? 'bg-indigo-50/50 font-medium text-indigo-700' : ''"
                                            >
                                                <span x-text="`${acc.code} — ${acc.name}`"></span>
                                            </button>
                                        </template>

                                        <div x-show="filterIncomeAccounts(search).length === 0" class="px-3 py-4 text-center text-sm text-slate-500">
                                            No account heads found.
                                        </div>
                                    </div>
                                </div>
                                <template x-if="index === incomeLines.length - 1">
                                    <button type="button" @click="openLedgerModal()" class="mt-1.5 text-xs font-medium text-sky-600 hover:text-sky-800">+ New income account</button>
                                </template>
                            </td>
                            <td class="px-3 py-3 align-top">
                                <input type="text" :name="`income_lines[${index}][memo]`" x-model="line.memo" maxlength="500" class="block w-full rounded-lg border border-slate-300 px-2 py-2 text-sm" placeholder="Optional">
                            </td>
                            <td class="px-3 py-3 align-top">
                                <input type="number" step="0.01" min="0.01" :name="`income_lines[${index}][amount]`" x-model="line.amount" class="block w-full rounded-lg border border-slate-300 px-2 py-2 text-right font-mono text-sm" placeholder="0.00" required>
                            </td>
                            <td class="py-3 pr-4 text-right align-top sm:pr-5">
                                <button type="button" @click="removeIncomeLine(index)" x-show="incomeLines.length > 1" class="rounded-md p-1.5 text-slate-400 hover:bg-rose-50 hover:text-rose-600" title="Remove line">
                                    <svg class="h-5 w-5" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M8.75 1A2.75 2.75 0 0 0 6 3.75v.443c-.795.077-1.584.176-2.365.298a.75.75 0 1 0 .23 1.482l.149-.022.841 10.518A2.75 2.75 0 0 0 7.596 19h4.807a2.75 2.75 0 0 0 2.742-2.53l.841-10.52.149.023a.75.75 0 0 0 .23-1.482A41.03 41.03 0 0 0 14 4.193V3.75A2.75 2.75 0 0 0 11.25 1h-2.5Z" clip-rule="evenodd" /></svg>
                                </button>
                            </td>
                        </tr>
                    </template>
                </tbody>
                <tfoot class="bg-slate-50">
                    <tr>
                        <th scope="row" colspan="2" class="py-2.5 pl-4 pr-3 text-right text-sm font-semibold text-slate-800 sm:pl-5">Total</th>
                        <td class="px-3 py-2.5 text-right text-sm font-bold font-mono text-slate-900" x-text="formatCurrency(totalAmount)"></td>
                        <td></td>
                    </tr>
                </tfoot>
            </table>
            </div>
            <div class="border-t border-slate-200 bg-slate-50 px-4 py-2.5 sm:px-5">
                <button type="button" @click="addIncomeLine()" class="text-sm font-medium text-slate-700 hover:text-slate-900">+ Add line</button>
            </div>
        </div>

        @if($errors->any() && old('entry_kind') === 'income')
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
            <button type="submit" class="rounded-lg bg-slate-900 px-5 py-2.5 text-sm font-semibold text-white shadow-sm hover:bg-slate-800">Post income</button>
        </div>
    </form>

    <div x-show="showLedgerModal" class="relative z-50" x-cloak>
        <div x-show="showLedgerModal" x-transition.opacity class="fixed inset-0 bg-slate-900/50 backdrop-blur-sm"></div>
        <div class="fixed inset-0 z-10 flex items-end justify-center p-4 sm:items-center">
            <div x-show="showLedgerModal" @click.away="closeLedgerModal()" class="w-full max-w-lg rounded-xl bg-white p-5 shadow-xl">
                <h3 class="text-base font-semibold text-slate-900">New income account</h3>
                <div class="mt-4 space-y-3">
                    <label class="block text-sm">
                        <span class="font-medium text-slate-700">Code <span class="font-normal text-slate-400">(optional)</span></span>
                        <input type="text" x-model="newLedger.code" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
                    </label>
                    <label class="block text-sm">
                        <span class="font-medium text-slate-700">Name</span>
                        <input type="text" x-model="newLedger.name" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm" placeholder="e.g. Service income">
                    </label>
                </div>
                <div class="mt-5 flex justify-end gap-2">
                    <button type="button" @click="closeLedgerModal()" class="rounded-lg border border-slate-300 px-4 py-2 text-sm font-medium text-slate-700">Cancel</button>
                    <button type="button" @click="submitLedger()" :disabled="isSubmittingLedger" class="rounded-lg bg-slate-900 px-4 py-2 text-sm font-semibold text-white disabled:opacity-50">Create</button>
                </div>
            </div>
        </div>
    </div>
</div>
