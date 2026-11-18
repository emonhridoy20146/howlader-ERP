@php
    $journalSources = \App\Models\JournalEntry::sources();
    $headerGradient = $location->type === 'branch'
        ? 'bg-gradient-to-br from-emerald-500 to-teal-800'
        : 'bg-gradient-to-br from-slate-600 to-slate-900';
    $f = $filters;
@endphp

<div class="space-y-5">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <a href="{{ route('locations.index') }}" class="rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-700 hover:bg-slate-50">Back to locations</a>
        <a href="{{ route('locations.index', ['drawer' => 'edit', 'edit' => $location->id]) }}" class="rounded-lg border border-indigo-200 bg-indigo-50 px-3 py-2 text-sm font-medium text-indigo-700 hover:bg-indigo-100">Edit location</a>
    </div>

    {{-- Wallet-style summary card --}}
    <section class="overflow-hidden rounded-2xl bg-white shadow-sm ring-1 ring-slate-200">
        <div class="relative {{ $headerGradient }} p-6 text-white sm:p-8">
            <div class="absolute -right-6 -top-6 opacity-10">
                <svg class="h-40 w-40" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M15 10.5a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z" />
                    <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 10.5c0 7.142-7.5 11.25-7.5 11.25S4.5 17.642 4.5 10.5a7.5 7.5 0 1 1 15 0Z" />
                </svg>
            </div>
            <div class="relative z-10 flex flex-col gap-6 lg:flex-row lg:items-start lg:justify-between">
                <div>
                    <p class="text-[10px] font-bold uppercase tracking-[0.2em] text-white/80">Location</p>
                    <h2 class="mt-1 text-2xl font-bold tracking-tight sm:text-3xl">{{ $location->name }}</h2>
                    <p class="mt-2 font-mono text-sm text-white/90">{{ $location->code }}</p>
                    <p class="mt-2 text-sm text-white/90">
                        <span class="rounded bg-white/15 px-2 py-0.5 text-xs font-semibold uppercase tracking-wide">{{ ucfirst($location->type) }}</span>
                        <span class="mx-2 text-white/50">·</span>
                        <span class="font-medium">{{ $location->is_active ? 'Active' : 'Inactive' }}</span>
                    </p>
                    <dl class="mt-5 grid gap-3 border-t border-white/20 pt-4 text-sm sm:grid-cols-2">
                        <div>
                            <dt class="text-[10px] font-bold uppercase tracking-wider text-white/60">Branch manager</dt>
                            <dd class="mt-0.5 font-medium text-white">
                                @if ($location->managerUser)
                                    {{ $location->managerUser->name }}
                                    <span class="block text-xs font-normal text-white/75">{{ $location->managerUser->email }}</span>
                                @else
                                    —
                                @endif
                            </dd>
                        </div>
                        <div>
                            <dt class="text-[10px] font-bold uppercase tracking-wider text-white/60">Manager name</dt>
                            <dd class="mt-0.5 font-medium text-white">{{ $location->manager_name ?: '—' }}</dd>
                        </div>
                        <div>
                            <dt class="text-[10px] font-bold uppercase tracking-wider text-white/60">Phone</dt>
                            <dd class="mt-0.5 font-medium text-white">{{ $location->phone ?: '—' }}</dd>
                        </div>
                        <div class="sm:col-span-2">
                            <dt class="text-[10px] font-bold uppercase tracking-wider text-white/60">Address</dt>
                            <dd class="mt-0.5 max-w-xl font-medium leading-relaxed text-white/95">{!! $location->address ? nl2br(e($location->address)) : '—' !!}</dd>
                        </div>
                    </dl>
                </div>
                <div class="grid w-full gap-3 self-start sm:grid-cols-2 lg:w-auto lg:min-w-[20rem]">
                    <div class="rounded-xl bg-white/10 p-4 backdrop-blur-sm">
                        <p class="text-[10px] font-bold uppercase tracking-widest text-white/70">Net position</p>
                        <p class="mt-1 font-mono text-2xl font-bold tabular-nums">{{ number_format($financialBalances['current_balance'], 2) }}</p>
                        <p class="mt-1 text-xs text-white/70">Stock + wallets − unpaid invoices</p>
                    </div>
                    <div class="rounded-xl bg-white/10 p-4 backdrop-blur-sm">
                        <p class="text-[10px] font-bold uppercase tracking-widest text-white/70">Stock value</p>
                        <p class="mt-1 font-mono text-2xl font-bold tabular-nums">{{ number_format($financialBalances['stocks'], 2) }}</p>
                        <p class="mt-1 text-xs text-white/70">At default purchase cost</p>
                    </div>
                </div>
            </div>
        </div>
        <div class="grid gap-px bg-slate-200 sm:grid-cols-3">
            <div class="bg-white px-4 py-3 text-center sm:text-left">
                <p class="text-xs font-medium uppercase tracking-wide text-slate-500">Unpaid invoices</p>
                <p class="mt-0.5 font-mono text-lg font-semibold text-amber-700 tabular-nums">{{ number_format($financialBalances['invoices'], 2) }}</p>
            </div>
            <div class="bg-white px-4 py-3 text-center sm:text-left">
                <p class="text-xs font-medium uppercase tracking-wide text-slate-500">Wallet ledgers</p>
                <p class="mt-0.5 font-mono text-lg font-semibold text-emerald-700 tabular-nums">{{ number_format($financialBalances['ledgers'], 2) }}</p>
            </div>
            <div class="bg-white px-4 py-3 text-center sm:text-left">
                <p class="text-xs font-medium uppercase tracking-wide text-slate-500">Operating expenses</p>
                <p class="mt-0.5 font-mono text-lg font-semibold text-rose-700 tabular-nums">{{ number_format($financialBalances['expenses'], 2) }}</p>
            </div>
        </div>
    </section>

    <section id="location-activity" class="scroll-mt-4 rounded-2xl bg-white shadow-sm ring-1 ring-slate-200">
        <div class="border-b border-slate-200 px-3 pt-3 sm:px-4">
            <nav class="flex flex-wrap gap-2" aria-label="Location sections">
                <a href="{{ $locationTabUrls['overview'] }}" class="inline-flex rounded-lg px-3 py-2 text-sm font-medium {{ $activeLocationTab === 'overview' ? 'bg-slate-900 text-white' : 'bg-slate-100 text-slate-700 hover:bg-slate-200' }}">Overview</a>
                <a href="{{ $locationTabUrls['transactions'] }}" class="inline-flex rounded-lg px-3 py-2 text-sm font-medium {{ $activeLocationTab === 'transactions' ? 'bg-slate-900 text-white' : 'bg-slate-100 text-slate-700 hover:bg-slate-200' }}">Journal ({{ $journalEntries->total() }})</a>
                <a href="{{ $locationTabUrls['stock'] }}" class="inline-flex rounded-lg px-3 py-2 text-sm font-medium {{ $activeLocationTab === 'stock' ? 'bg-slate-900 text-white' : 'bg-slate-100 text-slate-700 hover:bg-slate-200' }}">Stock ({{ $stockItems->total() }})</a>
                <a href="{{ $locationTabUrls['sales'] }}" class="inline-flex rounded-lg px-3 py-2 text-sm font-medium {{ $activeLocationTab === 'sales' ? 'bg-slate-900 text-white' : 'bg-slate-100 text-slate-700 hover:bg-slate-200' }}">Sales ({{ $salesInvoices->total() }})</a>
                <a href="{{ $locationTabUrls['expenses'] }}" class="inline-flex rounded-lg px-3 py-2 text-sm font-medium {{ $activeLocationTab === 'expenses' ? 'bg-slate-900 text-white' : 'bg-slate-100 text-slate-700 hover:bg-slate-200' }}">Expenses ({{ $expenseEntries->total() }})</a>
                <a href="{{ $locationTabUrls['purchasing'] }}" class="inline-flex rounded-lg px-3 py-2 text-sm font-medium {{ $activeLocationTab === 'purchasing' ? 'bg-slate-900 text-white' : 'bg-slate-100 text-slate-700 hover:bg-slate-200' }}">PO &amp; receipts</a>
                <a href="{{ $locationTabUrls['transfers'] }}" class="inline-flex rounded-lg px-3 py-2 text-sm font-medium {{ $activeLocationTab === 'transfers' ? 'bg-slate-900 text-white' : 'bg-slate-100 text-slate-700 hover:bg-slate-200' }}">Transfers ({{ $stockTransfers->total() }})</a>
                <a href="{{ $locationTabUrls['access'] }}" class="inline-flex rounded-lg px-3 py-2 text-sm font-medium {{ $activeLocationTab === 'access' ? 'bg-slate-900 text-white' : 'bg-slate-100 text-slate-700 hover:bg-slate-200' }}">Access &amp; payments</a>
                @if (in_array($location->type, ['branch', 'warehouse'], true))
                    <a href="{{ $locationTabUrls['targets'] }}" class="inline-flex rounded-lg px-3 py-2 text-sm font-medium {{ $activeLocationTab === 'targets' ? 'bg-slate-900 text-white' : 'bg-slate-100 text-slate-700 hover:bg-slate-200' }}">Targets</a>
                @endif
            </nav>
        </div>

        {{-- Overview --}}
        <div class="p-4 sm:p-5 @if($activeLocationTab !== 'overview') hidden @endif">
            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                <x-status-card label="Stock Value">
                    <span class="text-indigo-700">{{ number_format($financialBalances['stocks'], 2) }}</span>
                </x-status-card>
                <x-status-card label="Unpaid Invoices">
                    <span class="text-amber-700">{{ number_format($financialBalances['invoices'], 2) }}</span>
                </x-status-card>
                <x-status-card label="Ledger Balances">
                    <span class="text-emerald-700">{{ number_format($financialBalances['ledgers'], 2) }}</span>
                </x-status-card>
                <x-status-card label="Expenses">
                    <span class="text-rose-700">{{ number_format($financialBalances['expenses'], 2) }}</span>
                </x-status-card>
            </div>
            <div class="mt-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                <x-status-card label="Stock Rows">{{ $kpis['stock_rows'] }}</x-status-card>
                <x-status-card label="On Hand">{{ number_format($kpis['on_hand']) }}</x-status-card>
                <x-status-card label="Reserved">{{ number_format($kpis['reserved']) }}</x-status-card>
                <x-status-card label="Available">{{ number_format($kpis['available']) }}</x-status-card>
                <x-status-card label="Purchase Orders">{{ $kpis['purchase_orders'] }}</x-status-card>
                <x-status-card label="Goods Receipts">{{ $kpis['goods_receipts'] }}</x-status-card>
                <x-status-card label="Sales Invoices">{{ $kpis['sales_invoices'] }}</x-status-card>
                <x-status-card label="Transfers (Source)">{{ $kpis['transfers_as_source'] }}</x-status-card>
                <x-status-card label="Transfers (Destination)">{{ $kpis['transfers_as_destination'] }}</x-status-card>
            </div>
            @if(in_array($location->type, ['branch', 'warehouse'], true) && !empty($annualTargetProgress) && (($annualTargetProgress['sales']['target'] ?? 0) > 0 || ($annualTargetProgress['collection']['target'] ?? 0) > 0))
                <div class="mt-8">
                    <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
                        <h3 class="text-base font-semibold text-slate-900">{{ $targetYear }} target progress</h3>
                        <a href="{{ $locationTabUrls['targets'] }}" class="text-sm font-medium text-indigo-600 hover:text-indigo-800">Manage targets →</a>
                    </div>
                    <div class="grid gap-4 lg:grid-cols-2">
                        @include('locations._annual-target-metric', [
                            'label' => 'Sales',
                            'tone' => 'indigo',
                            'metric' => $annualTargetProgress['sales'],
                        ])
                        @include('locations._annual-target-metric', [
                            'label' => 'Collection',
                            'tone' => 'emerald',
                            'metric' => $annualTargetProgress['collection'],
                        ])
                    </div>
                </div>
            @endif
            @if($location->type === 'branch')
                <div class="mt-8">
                    <div class="mb-3 flex items-center gap-2">
                        <svg class="h-5 w-5 text-indigo-500" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M10.5 6a7.5 7.5 0 1 0 7.5 7.5h-7.5V6Z" />
                            <path stroke-linecap="round" stroke-linejoin="round" d="M13.5 10.5H21A7.5 7.5 0 0 0 13.5 3v7.5Z" />
                        </svg>
                        <h3 class="text-base font-semibold text-slate-900">Branch performance (last 14 days)</h3>
                    </div>
                    <div class="rounded-2xl border border-slate-200 bg-white p-4">
                        <div class="relative h-72 w-full sm:h-80">
                            <canvas id="performanceChart"></canvas>
                        </div>
                    </div>
                </div>
            @endif
            <div class="mt-8">
                <div class="mb-2 flex flex-wrap items-center justify-between gap-2">
                    <h3 class="text-sm font-semibold text-slate-900">Top stock (preview)</h3>
                    <a href="{{ $locationTabUrls['stock'] }}" class="text-sm font-medium text-indigo-600 hover:text-indigo-800">View all stock →</a>
                </div>
                <div class="overflow-x-auto rounded-xl ring-1 ring-slate-200">
                    <table class="min-w-full divide-y divide-slate-100 text-sm">
                        <thead class="bg-slate-50 text-left text-xs uppercase tracking-wider text-slate-500">
                            <tr><th class="px-4 py-3 font-medium">Item</th><th class="px-4 py-3 font-medium text-right">On hand</th></tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            @forelse($stockPreviewItems as $item)
                                <tr>
                                    <td class="px-4 py-3"><p class="font-medium text-slate-800">{{ $item->name }}</p><p class="text-xs text-slate-500">{{ $item->sku }}</p></td>
                                    <td class="px-4 py-3 text-right font-medium text-slate-700">{{ number_format($item->on_hand_qty) }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="2" class="px-4 py-10 text-center text-sm text-slate-500">No stock records.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        {{-- Journal / transactions --}}
        <div class="p-4 sm:p-5 @if($activeLocationTab !== 'transactions') hidden @endif">
            <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
                <p class="text-sm text-slate-600">Journal entries posted to this branch.</p>
                @can('viewAny', \App\Models\JournalEntry::class)
                    <a href="{{ route('journal-entries.index', ['location_id' => $location->id]) }}" class="text-sm font-medium text-indigo-600 hover:text-indigo-800">Open full journal →</a>
                @endcan
            </div>
            <form method="get" action="{{ route('locations.show', $location) }}" class="mb-4 flex flex-col gap-3 rounded-xl border border-slate-200 bg-slate-50/80 p-4 sm:flex-row sm:flex-wrap sm:items-end">
                <input type="hidden" name="tab" value="transactions">
                <input type="hidden" name="per_page" value="{{ $perPage }}">
                <div class="flex min-w-[8rem] flex-col gap-1">
                    <label class="text-xs font-medium text-slate-600" for="journal_date_from">From</label>
                    <input type="date" id="journal_date_from" name="journal_date_from" value="{{ $f['journal_date_from'] }}" class="rounded-lg border border-slate-300 px-2 py-1.5 text-sm">
                </div>
                <div class="flex min-w-[8rem] flex-col gap-1">
                    <label class="text-xs font-medium text-slate-600" for="journal_date_to">To</label>
                    <input type="date" id="journal_date_to" name="journal_date_to" value="{{ $f['journal_date_to'] }}" class="rounded-lg border border-slate-300 px-2 py-1.5 text-sm">
                </div>
                <div class="flex min-w-[12rem] flex-col gap-1">
                    <label class="text-xs font-medium text-slate-600" for="journal_source">Source</label>
                    <select id="journal_source" name="journal_source" class="rounded-lg border border-slate-300 px-2 py-1.5 text-sm">
                        <option value="">Any</option>
                        @foreach ($journalSources as $val => $label)
                            <option value="{{ $val }}" @selected($f['journal_source'] === $val)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="flex min-w-[10rem] flex-1 flex-col gap-1 sm:min-w-[14rem]">
                    <label class="text-xs font-medium text-slate-600" for="journal_q">Search</label>
                    <input type="text" id="journal_q" name="journal_q" value="{{ $f['journal_q'] }}" placeholder="Document or description" class="rounded-lg border border-slate-300 px-2 py-1.5 text-sm">
                </div>
                <div class="flex flex-wrap gap-2">
                    <button type="submit" class="rounded-lg bg-slate-900 px-3 py-2 text-sm font-medium text-white hover:bg-slate-800">Apply</button>
                    <a href="{{ route('locations.show', $location) }}?tab=transactions#location-activity" class="rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-700 hover:bg-slate-50">Reset</a>
                </div>
            </form>
            <x-pagination-limit-selector :page-params="$locationPaginationPageParams" fragment="location-activity" />
            <div class="overflow-x-auto rounded-xl ring-1 ring-slate-200">
                <table class="min-w-full text-sm whitespace-nowrap">
                    <thead class="border-b border-slate-200 bg-slate-50 text-left text-xs font-semibold uppercase tracking-wider text-slate-700">
                        <tr>
                            <th class="px-3 py-2">Date</th>
                            <th class="px-3 py-2">Document</th>
                            <th class="px-3 py-2">Source</th>
                            <th class="px-3 py-2">Customer</th>
                            <th class="px-3 py-2">Description</th>
                            <th class="px-3 py-2 text-right">Debit</th>
                            <th class="px-3 py-2 text-right">Credit</th>
                            <th class="px-3 py-2 text-right"></th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($journalEntries as $entry)
                            <tr class="border-b border-slate-100 last:border-0">
                                <td class="px-3 py-2 text-slate-600">{{ $entry->entry_date->format('M j, Y') }}</td>
                                <td class="px-3 py-2 font-mono text-xs text-slate-800">{{ $entry->document_no ?: '—' }}</td>
                                <td class="px-3 py-2">{{ $journalSources[$entry->source] ?? $entry->source }}</td>
                                <td class="max-w-[10rem] truncate px-3 py-2 text-slate-800" title="{{ $entry->linkedCustomer()?->name ?? '' }}">{{ $entry->linkedCustomer()?->name ?? '—' }}</td>
                                <td class="max-w-[14rem] truncate px-3 py-2 text-slate-600" title="{{ $entry->description }}">{{ $entry->description ?: '—' }}</td>
                                <td class="px-3 py-2 text-right tabular-nums">{{ number_format((float) ($entry->lines_debit_sum ?? 0), 2) }}</td>
                                <td class="px-3 py-2 text-right tabular-nums">{{ number_format((float) ($entry->lines_credit_sum ?? 0), 2) }}</td>
                                <td class="px-3 py-2 text-right">
                                    @can('view', $entry)
                                        <a href="{{ route('journal-entries.show', $entry) }}" class="text-xs font-medium text-indigo-600 hover:underline">View</a>
                                    @endcan
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="8" class="px-3 py-10 text-center text-slate-500">No journal entries for this location.</td></tr>
                        @endforelse
                    </tbody>
                    <tfoot>
                        <tr class="border-t-2 border-slate-200 bg-slate-50/90 font-semibold text-slate-900">
                            <td colspan="5" class="px-3 py-2 text-right text-xs font-bold uppercase tracking-wide text-slate-600">Total (filtered)</td>
                            <td class="px-3 py-2 text-right tabular-nums">{{ number_format($locationTableTotals['journal']['debit'], 2) }}</td>
                            <td class="px-3 py-2 text-right tabular-nums">{{ number_format($locationTableTotals['journal']['credit'], 2) }}</td>
                            <td class="px-3 py-2"></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
            <div class="mt-3">{{ $journalEntries->links() }}</div>
        </div>

        {{-- Stock --}}
        <div class="p-4 sm:p-5 @if($activeLocationTab !== 'stock') hidden @endif">
            <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
                <p class="text-sm text-slate-600">Inventory at this location, ranked by on-hand quantity.</p>
                <a href="{{ route('reports.stock-by-location', ['location_id' => $location->id]) }}" class="text-sm font-medium text-indigo-600 hover:text-indigo-800">Stock report →</a>
            </div>
            <form method="get" action="{{ route('locations.show', $location) }}" class="mb-4 flex flex-col gap-3 rounded-xl border border-slate-200 bg-slate-50/80 p-4 sm:flex-row sm:flex-wrap sm:items-end">
                <input type="hidden" name="tab" value="stock">
                <input type="hidden" name="per_page" value="{{ $perPage }}">
                <div class="flex min-w-[12rem] flex-1 flex-col gap-1 sm:min-w-[16rem]">
                    <label class="text-xs font-medium text-slate-600" for="stock_q">Search product / SKU</label>
                    <input type="text" id="stock_q" name="stock_q" value="{{ $f['stock_q'] }}" placeholder="Name or SKU contains…" class="rounded-lg border border-slate-300 px-2 py-1.5 text-sm">
                </div>
                <div class="flex flex-wrap gap-2">
                    <button type="submit" class="rounded-lg bg-slate-900 px-3 py-2 text-sm font-medium text-white hover:bg-slate-800">Apply</button>
                    <a href="{{ route('locations.show', $location) }}?tab=stock#location-activity" class="rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-700 hover:bg-slate-50">Reset</a>
                </div>
            </form>
            <x-pagination-limit-selector :page-params="$locationPaginationPageParams" fragment="location-activity" />
            <div class="overflow-x-auto rounded-xl ring-1 ring-slate-200">
                <table class="min-w-full text-sm whitespace-nowrap">
                    <thead class="border-b border-slate-200 bg-slate-50 text-left text-xs font-semibold uppercase tracking-wider text-slate-700">
                        <tr>
                            <th class="px-3 py-2">Product</th>
                            <th class="px-3 py-2">SKU</th>
                            <th class="px-3 py-2 text-right">On hand</th>
                            <th class="px-3 py-2 text-right">Reserved</th>
                            <th class="px-3 py-2 text-right">Available</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($stockItems as $item)
                            <tr class="border-b border-slate-100 last:border-0">
                                <td class="px-3 py-2 font-medium text-slate-900">{{ $item->name }}</td>
                                <td class="px-3 py-2 text-slate-600">{{ $item->sku }}</td>
                                <td class="px-3 py-2 text-right tabular-nums">{{ number_format($item->on_hand_qty) }}</td>
                                <td class="px-3 py-2 text-right tabular-nums">{{ number_format($item->reserved_qty) }}</td>
                                <td class="px-3 py-2 text-right font-medium tabular-nums">{{ number_format($item->available_qty) }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="px-3 py-10 text-center text-slate-500">No inventory at this location.</td></tr>
                        @endforelse
                    </tbody>
                    <tfoot>
                        <tr class="border-t-2 border-slate-200 bg-slate-50/90 font-semibold text-slate-900">
                            <td colspan="2" class="px-3 py-2 text-right text-xs font-bold uppercase tracking-wide text-slate-600">Total (filtered)</td>
                            <td class="px-3 py-2 text-right tabular-nums">{{ number_format($locationTableTotals['stock']['on_hand']) }}</td>
                            <td class="px-3 py-2 text-right tabular-nums">{{ number_format($locationTableTotals['stock']['reserved']) }}</td>
                            <td class="px-3 py-2 text-right tabular-nums">{{ number_format($locationTableTotals['stock']['available']) }}</td>
                        </tr>
                    </tfoot>
                </table>
            </div>
            <div class="mt-3">{{ $stockItems->links() }}</div>
        </div>

        {{-- Sales --}}
        <div class="p-4 sm:p-5 @if($activeLocationTab !== 'sales') hidden @endif">
            @php
                $locationSalesHref = static function (array $overrides) use ($location): string {
                    $q = array_merge(
                        request()->except(['sales_date_from', 'sales_date_to', 'sales_range', 'sales_page']),
                        $overrides
                    );
                    $q = array_filter($q, static fn ($v) => $v !== '' && $v !== null);

                    return route('locations.show', $location).($q !== [] ? '?'.http_build_query($q) : '').'#location-activity';
                };
            @endphp

            <div class="mb-4 flex flex-wrap items-center justify-between gap-2">
                <div>
                    <p class="text-sm font-semibold text-slate-900">Sales for this branch</p>
                    <p class="text-sm text-slate-600">Total matches the invoices below when search and date filters apply.</p>
                </div>
                @can('viewAny', \App\Models\SalesInvoice::class)
                    <a href="{{ route('sales.invoices.index') }}" class="text-sm font-medium text-indigo-600 hover:text-indigo-800">Sales list →</a>
                @endcan
            </div>

            <div class="mb-4 rounded-xl border border-emerald-100 bg-gradient-to-br from-emerald-50/95 to-white p-4 ring-1 ring-emerald-900/10 sm:p-5">
                <p class="text-[10px] font-bold uppercase tracking-widest text-emerald-900/65">Total sales (filtered)</p>
                <p class="mt-1 font-mono text-2xl font-bold tabular-nums text-emerald-950 sm:text-3xl">{{ number_format($salesTotals['grand_total'], 2) }}</p>
                <p class="mt-2 text-sm text-slate-600">{{ number_format($salesTotals['invoice_count']) }} invoice{{ $salesTotals['invoice_count'] === 1 ? '' : 's' }} · <span class="font-medium text-slate-800">{{ $salesTotals['period_description'] }}</span></p>
            </div>

            <div class="mb-3 flex flex-wrap items-center gap-2">
                <span class="text-xs font-medium text-slate-500">Period</span>
                @php $sr = $f['sales_range']; @endphp
                <a href="{{ $locationSalesHref(['tab' => 'sales', 'sales_range' => 'all_time']) }}" class="inline-flex rounded-lg border px-2.5 py-1 text-xs font-medium {{ $sr === 'all_time' ? 'border-emerald-600 bg-emerald-600 text-white' : 'border-slate-200 bg-white text-slate-700 hover:bg-slate-50' }}">All time</a>
                <a href="{{ $locationSalesHref(['tab' => 'sales', 'sales_range' => 'this_month']) }}" class="inline-flex rounded-lg border px-2.5 py-1 text-xs font-medium {{ $sr === 'this_month' ? 'border-emerald-600 bg-emerald-600 text-white' : 'border-slate-200 bg-white text-slate-700 hover:bg-slate-50' }}">This month</a>
                <a href="{{ $locationSalesHref(['tab' => 'sales', 'sales_range' => 'months_3']) }}" class="inline-flex rounded-lg border px-2.5 py-1 text-xs font-medium {{ $sr === 'months_3' ? 'border-emerald-600 bg-emerald-600 text-white' : 'border-slate-200 bg-white text-slate-700 hover:bg-slate-50' }}">Last 3 months</a>
                <a href="{{ $locationSalesHref(['tab' => 'sales', 'sales_range' => 'months_6']) }}" class="inline-flex rounded-lg border px-2.5 py-1 text-xs font-medium {{ $sr === 'months_6' ? 'border-emerald-600 bg-emerald-600 text-white' : 'border-slate-200 bg-white text-slate-700 hover:bg-slate-50' }}">Last 6 months</a>
                <a href="{{ $locationSalesHref(['tab' => 'sales', 'sales_range' => 'months_12']) }}" class="inline-flex rounded-lg border px-2.5 py-1 text-xs font-medium {{ $sr === 'months_12' ? 'border-emerald-600 bg-emerald-600 text-white' : 'border-slate-200 bg-white text-slate-700 hover:bg-slate-50' }}">Last 12 months</a>
            </div>

            <form method="get" action="{{ route('locations.show', $location) }}" class="mb-4 flex flex-col gap-3 rounded-xl border border-slate-200 bg-slate-50/80 p-4 sm:flex-row sm:flex-wrap sm:items-end">
                <input type="hidden" name="tab" value="sales">
                <input type="hidden" name="sales_range" value="custom">
                <input type="hidden" name="per_page" value="{{ $perPage }}">
                <div class="flex min-w-[8rem] flex-col gap-1">
                    <label class="text-xs font-medium text-slate-600" for="sales_date_from">From</label>
                    <input type="date" id="sales_date_from" name="sales_date_from" value="{{ $f['sales_date_from'] }}" class="rounded-lg border border-slate-300 px-2 py-1.5 text-sm">
                </div>
                <div class="flex min-w-[8rem] flex-col gap-1">
                    <label class="text-xs font-medium text-slate-600" for="sales_date_to">To</label>
                    <input type="date" id="sales_date_to" name="sales_date_to" value="{{ $f['sales_date_to'] }}" class="rounded-lg border border-slate-300 px-2 py-1.5 text-sm">
                </div>
                <div class="flex min-w-[10rem] flex-1 flex-col gap-1 sm:min-w-[14rem]">
                    <label class="text-xs font-medium text-slate-600" for="sales_q">Search</label>
                    <input type="text" id="sales_q" name="sales_q" value="{{ $f['sales_q'] }}" placeholder="Invoice # or customer" class="rounded-lg border border-slate-300 px-2 py-1.5 text-sm">
                </div>
                <div class="flex flex-wrap gap-2">
                    <button type="submit" class="rounded-lg bg-slate-900 px-3 py-2 text-sm font-medium text-white hover:bg-slate-800">Apply custom range</button>
                    <a href="{{ $locationSalesHref(['tab' => 'sales', 'sales_range' => 'all_time']) }}" class="rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-700 hover:bg-slate-50">Reset</a>
                </div>
            </form>
            <x-pagination-limit-selector :page-params="$locationPaginationPageParams" fragment="location-activity" />
            <div class="overflow-x-auto rounded-xl ring-1 ring-slate-200">
                <table class="min-w-full text-sm whitespace-nowrap">
                    <thead class="border-b border-slate-200 bg-slate-50 text-left text-xs font-semibold uppercase tracking-wider text-slate-700">
                        <tr>
                            <th class="px-3 py-2">Invoice</th>
                            <th class="px-3 py-2">Customer</th>
                            <th class="px-3 py-2">Date</th>
                            <th class="px-3 py-2 text-right">Amount</th>
                            <th class="px-3 py-2 text-right"></th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($salesInvoices as $inv)
                            <tr class="border-b border-slate-100 last:border-0">
                                <td class="px-3 py-2 font-mono text-xs">{{ $inv->invoice_number }}</td>
                                <td class="px-3 py-2">{{ $inv->customer?->name ?? 'Walk-in' }}</td>
                                <td class="px-3 py-2 text-slate-600">{{ $inv->invoice_date?->format('M j, Y') }}</td>
                                <td class="px-3 py-2 text-right tabular-nums font-medium">{{ number_format($inv->grand_total, 2) }}</td>
                                <td class="px-3 py-2 text-right">
                                    @can('view', $inv)
                                        <a href="{{ route('sales.invoices.show', $inv) }}" class="text-xs font-medium text-indigo-600 hover:underline">View</a>
                                    @endcan
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="px-3 py-10 text-center text-slate-500">No invoices for this branch.</td></tr>
                        @endforelse
                    </tbody>
                    <tfoot>
                        <tr class="border-t-2 border-slate-200 bg-slate-50/90 font-semibold text-slate-900">
                            <td colspan="3" class="px-3 py-2 text-right text-xs font-bold uppercase tracking-wide text-slate-600">Total (filtered)</td>
                            <td class="px-3 py-2 text-right tabular-nums">{{ number_format($salesTotals['grand_total'], 2) }}</td>
                            <td class="px-3 py-2"></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
            <div class="mt-3">{{ $salesInvoices->links() }}</div>
        </div>

        {{-- Expenses --}}
        <div class="p-4 sm:p-5 @if($activeLocationTab !== 'expenses') hidden @endif">
            <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
                <p class="text-sm text-slate-600">Manual operating expenses at this branch.</p>
                @can('viewAny', \App\Models\JournalEntry::class)
                    <a href="{{ route('journal-entries.index', ['location_id' => $location->id, 'source' => 'manual_expense']) }}" class="text-sm font-medium text-indigo-600 hover:text-indigo-800">Filter journal →</a>
                @endcan
            </div>
            <form method="get" action="{{ route('locations.show', $location) }}" class="mb-4 flex flex-col gap-3 rounded-xl border border-slate-200 bg-slate-50/80 p-4 sm:flex-row sm:flex-wrap sm:items-end">
                <input type="hidden" name="tab" value="expenses">
                <input type="hidden" name="per_page" value="{{ $perPage }}">
                <div class="flex min-w-[8rem] flex-col gap-1">
                    <label class="text-xs font-medium text-slate-600" for="expense_date_from">From</label>
                    <input type="date" id="expense_date_from" name="expense_date_from" value="{{ $f['expense_date_from'] }}" class="rounded-lg border border-slate-300 px-2 py-1.5 text-sm">
                </div>
                <div class="flex min-w-[8rem] flex-col gap-1">
                    <label class="text-xs font-medium text-slate-600" for="expense_date_to">To</label>
                    <input type="date" id="expense_date_to" name="expense_date_to" value="{{ $f['expense_date_to'] }}" class="rounded-lg border border-slate-300 px-2 py-1.5 text-sm">
                </div>
                <div class="flex min-w-[10rem] flex-1 flex-col gap-1 sm:min-w-[14rem]">
                    <label class="text-xs font-medium text-slate-600" for="expense_q">Description contains</label>
                    <input type="text" id="expense_q" name="expense_q" value="{{ $f['expense_q'] }}" placeholder="Search description" class="rounded-lg border border-slate-300 px-2 py-1.5 text-sm">
                </div>
                <div class="flex flex-wrap gap-2">
                    <button type="submit" class="rounded-lg bg-slate-900 px-3 py-2 text-sm font-medium text-white hover:bg-slate-800">Apply</button>
                    <a href="{{ route('locations.show', $location) }}?tab=expenses#location-activity" class="rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-700 hover:bg-slate-50">Reset</a>
                </div>
            </form>
            <x-pagination-limit-selector :page-params="$locationPaginationPageParams" fragment="location-activity" />
            <div class="overflow-x-auto rounded-xl ring-1 ring-slate-200">
                <table class="min-w-full text-sm whitespace-nowrap">
                    <thead class="border-b border-slate-200 bg-slate-50 text-left text-xs font-semibold uppercase tracking-wider text-slate-700">
                        <tr>
                            <th class="px-3 py-2">Date</th>
                            <th class="px-3 py-2">Description</th>
                            <th class="px-3 py-2 text-right">Amount</th>
                            <th class="px-3 py-2 text-right"></th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($expenseEntries as $exp)
                            <tr class="border-b border-slate-100 last:border-0">
                                <td class="px-3 py-2 text-slate-600">{{ $exp->entry_date->format('M j, Y') }}</td>
                                <td class="max-w-[20rem] truncate px-3 py-2" title="{{ $exp->description }}">{{ $exp->description ?: '—' }}</td>
                                <td class="px-3 py-2 text-right font-medium tabular-nums">{{ number_format((float) ($exp->expense_debit_sum ?? 0), 2) }}</td>
                                <td class="px-3 py-2 text-right">
                                    @can('view', $exp)
                                        <a href="{{ route('journal-entries.show', $exp) }}" class="text-xs font-medium text-rose-600 hover:underline">View</a>
                                    @endcan
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="px-3 py-10 text-center text-slate-500">No expenses match your filters.</td></tr>
                        @endforelse
                    </tbody>
                    <tfoot>
                        <tr class="border-t-2 border-slate-200 bg-slate-50/90 font-semibold text-slate-900">
                            <td colspan="2" class="px-3 py-2 text-right text-xs font-bold uppercase tracking-wide text-slate-600">Total (filtered)</td>
                            <td class="px-3 py-2 text-right tabular-nums">{{ number_format($locationTableTotals['expenses']['amount'], 2) }}</td>
                            <td class="px-3 py-2"></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
            <div class="mt-3">{{ $expenseEntries->links() }}</div>
        </div>

        {{-- Purchasing --}}
        <div class="p-4 sm:p-5 @if($activeLocationTab !== 'purchasing') hidden @endif">
            <x-pagination-limit-selector :page-params="$locationPaginationPageParams" fragment="location-activity" />
            <div class="space-y-10">
                <div>
                    <div class="mb-2 flex flex-wrap items-center justify-between gap-2">
                        <h3 class="text-sm font-semibold text-slate-900">Purchase orders (destination) · {{ $purchaseOrders->total() }} total</h3>
                        @can('viewAny', \App\Models\PurchaseOrder::class)
                            <a href="{{ route('purchase-orders.index', ['location_id' => $location->id]) }}" class="text-sm font-medium text-indigo-600 hover:text-indigo-800">All POs →</a>
                        @endcan
                    </div>
                    <form method="get" action="{{ route('locations.show', $location) }}" class="mb-3 flex flex-col gap-3 rounded-xl border border-slate-200 bg-slate-50/80 p-4 sm:flex-row sm:flex-wrap sm:items-end">
                        <input type="hidden" name="tab" value="purchasing">
                        <input type="hidden" name="per_page" value="{{ $perPage }}">
                        <input type="hidden" name="grn_date_from" value="{{ $f['grn_date_from'] }}">
                        <input type="hidden" name="grn_date_to" value="{{ $f['grn_date_to'] }}">
                        <input type="hidden" name="grn_q" value="{{ $f['grn_q'] }}">
                        <div class="flex min-w-[8rem] flex-col gap-1">
                            <label class="text-xs font-medium text-slate-600" for="po_date_from">Ordered from</label>
                            <input type="date" id="po_date_from" name="po_date_from" value="{{ $f['po_date_from'] }}" class="rounded-lg border border-slate-300 px-2 py-1.5 text-sm">
                        </div>
                        <div class="flex min-w-[8rem] flex-col gap-1">
                            <label class="text-xs font-medium text-slate-600" for="po_date_to">Ordered to</label>
                            <input type="date" id="po_date_to" name="po_date_to" value="{{ $f['po_date_to'] }}" class="rounded-lg border border-slate-300 px-2 py-1.5 text-sm">
                        </div>
                        <div class="flex min-w-[10rem] flex-col gap-1">
                            <label class="text-xs font-medium text-slate-600" for="po_status">Status</label>
                            <select id="po_status" name="po_status" class="rounded-lg border border-slate-300 px-2 py-1.5 text-sm">
                                <option value="">Any</option>
                                @foreach (['draft', 'approved', 'partially_received', 'received', 'cancelled'] as $st)
                                    <option value="{{ $st }}" @selected($f['po_status'] === $st)>{{ ucfirst(str_replace('_', ' ', $st)) }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="flex min-w-[10rem] flex-1 flex-col gap-1 sm:min-w-[12rem]">
                            <label class="text-xs font-medium text-slate-600" for="po_q">Search</label>
                            <input type="text" id="po_q" name="po_q" value="{{ $f['po_q'] }}" placeholder="PO # or supplier" class="rounded-lg border border-slate-300 px-2 py-1.5 text-sm">
                        </div>
                        <div class="flex flex-wrap gap-2">
                            <button type="submit" class="rounded-lg bg-slate-900 px-3 py-2 text-sm font-medium text-white hover:bg-slate-800">Apply PO filters</button>
                        </div>
                    </form>
                    <div class="overflow-x-auto rounded-xl ring-1 ring-slate-200">
                        <table class="min-w-full text-sm whitespace-nowrap">
                            <thead class="border-b border-slate-200 bg-slate-50 text-left text-xs font-semibold uppercase tracking-wider text-slate-700">
                                <tr>
                                    <th class="px-3 py-2">PO</th>
                                    <th class="px-3 py-2">Supplier</th>
                                    <th class="px-3 py-2">Status</th>
                                    <th class="px-3 py-2 text-right">Total</th>
                                    <th class="px-3 py-2 text-right"></th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse($purchaseOrders as $po)
                                    <tr class="border-b border-slate-100 last:border-0">
                                        <td class="px-3 py-2 font-mono text-xs">{{ $po->po_number }}</td>
                                        <td class="px-3 py-2">{{ $po->supplier?->name ?? '—' }}</td>
                                        <td class="px-3 py-2">{{ ucfirst(str_replace('_', ' ', $po->status)) }}</td>
                                        <td class="px-3 py-2 text-right tabular-nums">{{ number_format((float) $po->total_amount, 2) }}</td>
                                        <td class="px-3 py-2 text-right">
                                            @can('view', $po)
                                                <a href="{{ route('purchase-orders.show', $po) }}" class="text-xs font-medium text-indigo-600 hover:underline">View</a>
                                            @endcan
                                        </td>
                                    </tr>
                                @empty
                                    <tr><td colspan="5" class="px-3 py-8 text-center text-slate-500">No purchase orders to this location.</td></tr>
                                @endforelse
                            </tbody>
                            <tfoot>
                                <tr class="border-t-2 border-slate-200 bg-slate-50/90 font-semibold text-slate-900">
                                    <td colspan="3" class="px-3 py-2 text-right text-xs font-bold uppercase tracking-wide text-slate-600">Total (filtered)</td>
                                    <td class="px-3 py-2 text-right tabular-nums">{{ number_format($locationTableTotals['purchase_orders']['total_amount'], 2) }}</td>
                                    <td class="px-3 py-2"></td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                    <div class="mt-3">{{ $purchaseOrders->links() }}</div>
                </div>
                <div>
                    <div class="mb-2 flex flex-wrap items-center justify-between gap-2">
                        <h3 class="text-sm font-semibold text-slate-900">Goods receipts · {{ $goodsReceipts->total() }} total</h3>
                        @can('viewAny', \App\Models\GoodsReceipt::class)
                            <a href="{{ route('goods-receipts.index', ['location_id' => $location->id]) }}" class="text-sm font-medium text-indigo-600 hover:text-indigo-800">All receipts →</a>
                        @endcan
                    </div>
                    <form method="get" action="{{ route('locations.show', $location) }}" class="mb-3 flex flex-col gap-3 rounded-xl border border-slate-200 bg-slate-50/80 p-4 sm:flex-row sm:flex-wrap sm:items-end">
                        <input type="hidden" name="tab" value="purchasing">
                        <input type="hidden" name="per_page" value="{{ $perPage }}">
                        <input type="hidden" name="po_date_from" value="{{ $f['po_date_from'] }}">
                        <input type="hidden" name="po_date_to" value="{{ $f['po_date_to'] }}">
                        <input type="hidden" name="po_status" value="{{ $f['po_status'] }}">
                        <input type="hidden" name="po_q" value="{{ $f['po_q'] }}">
                        <div class="flex min-w-[8rem] flex-col gap-1">
                            <label class="text-xs font-medium text-slate-600" for="grn_date_from">Received from</label>
                            <input type="date" id="grn_date_from" name="grn_date_from" value="{{ $f['grn_date_from'] }}" class="rounded-lg border border-slate-300 px-2 py-1.5 text-sm">
                        </div>
                        <div class="flex min-w-[8rem] flex-col gap-1">
                            <label class="text-xs font-medium text-slate-600" for="grn_date_to">Received to</label>
                            <input type="date" id="grn_date_to" name="grn_date_to" value="{{ $f['grn_date_to'] }}" class="rounded-lg border border-slate-300 px-2 py-1.5 text-sm">
                        </div>
                        <div class="flex min-w-[10rem] flex-1 flex-col gap-1 sm:min-w-[12rem]">
                            <label class="text-xs font-medium text-slate-600" for="grn_q">Search</label>
                            <input type="text" id="grn_q" name="grn_q" value="{{ $f['grn_q'] }}" placeholder="GRN, DN ref, supplier" class="rounded-lg border border-slate-300 px-2 py-1.5 text-sm">
                        </div>
                        <div class="flex flex-wrap gap-2">
                            <button type="submit" class="rounded-lg bg-slate-900 px-3 py-2 text-sm font-medium text-white hover:bg-slate-800">Apply GRN filters</button>
                            <a href="{{ route('locations.show', $location) }}?tab=purchasing#location-activity" class="rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-700 hover:bg-slate-50">Reset all</a>
                        </div>
                    </form>
                    <div class="overflow-x-auto rounded-xl ring-1 ring-slate-200">
                        <table class="min-w-full text-sm whitespace-nowrap">
                            <thead class="border-b border-slate-200 bg-slate-50 text-left text-xs font-semibold uppercase tracking-wider text-slate-700">
                                <tr>
                                    <th class="px-3 py-2">GRN</th>
                                    <th class="px-3 py-2">Supplier</th>
                                    <th class="px-3 py-2">Received</th>
                                    <th class="px-3 py-2">Status</th>
                                    <th class="px-3 py-2 text-right"></th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse($goodsReceipts as $grn)
                                    <tr class="border-b border-slate-100 last:border-0">
                                        <td class="px-3 py-2 font-mono text-xs">{{ $grn->grn_number }}</td>
                                        <td class="px-3 py-2">{{ $grn->supplier?->name ?? '—' }}</td>
                                        <td class="px-3 py-2 text-slate-600">{{ $grn->received_at?->format('M j, Y') }}</td>
                                        <td class="px-3 py-2">{{ ucfirst($grn->status) }}</td>
                                        <td class="px-3 py-2 text-right">
                                            @can('view', $grn)
                                                <a href="{{ route('goods-receipts.show', $grn) }}" class="text-xs font-medium text-indigo-600 hover:underline">View</a>
                                            @endcan
                                        </td>
                                    </tr>
                                @empty
                                    <tr><td colspan="5" class="px-3 py-8 text-center text-slate-500">No goods receipts at this location.</td></tr>
                                @endforelse
                            </tbody>
                            <tfoot>
                                <tr class="border-t-2 border-slate-200 bg-slate-50/90 font-semibold text-slate-900">
                                    <td colspan="5" class="px-3 py-2 text-right text-xs font-bold uppercase tracking-wide text-slate-600">Receipts matched (filtered): <span class="text-sm tabular-nums text-slate-900">{{ number_format($goodsReceipts->total()) }}</span></td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                    <div class="mt-3">{{ $goodsReceipts->links() }}</div>
                </div>
            </div>
        </div>

        {{-- Transfers --}}
        <div class="p-4 sm:p-5 @if($activeLocationTab !== 'transfers') hidden @endif">
            <div class="mb-3 flex flex-col gap-2 sm:flex-row sm:flex-wrap sm:items-center sm:justify-between">
                <p class="text-sm text-slate-600">Transfers where this location is source or destination.</p>
                @can('viewAny', \App\Models\StockTransfer::class)
                    <div class="flex flex-wrap gap-3">
                        <a href="{{ route('transfers.index', ['source_location_id' => $location->id]) }}" class="text-sm font-medium text-indigo-600 hover:text-indigo-800">From this location →</a>
                        <a href="{{ route('transfers.index', ['destination_location_id' => $location->id]) }}" class="text-sm font-medium text-indigo-600 hover:text-indigo-800">To this location →</a>
                    </div>
                @endcan
            </div>
            <form method="get" action="{{ route('locations.show', $location) }}" class="mb-4 flex flex-col gap-3 rounded-xl border border-slate-200 bg-slate-50/80 p-4 sm:flex-row sm:flex-wrap sm:items-end">
                <input type="hidden" name="tab" value="transfers">
                <input type="hidden" name="per_page" value="{{ $perPage }}">
                <div class="flex min-w-[8rem] flex-col gap-1">
                    <label class="text-xs font-medium text-slate-600" for="transfer_date_from">Requested from</label>
                    <input type="date" id="transfer_date_from" name="transfer_date_from" value="{{ $f['transfer_date_from'] }}" class="rounded-lg border border-slate-300 px-2 py-1.5 text-sm">
                </div>
                <div class="flex min-w-[8rem] flex-col gap-1">
                    <label class="text-xs font-medium text-slate-600" for="transfer_date_to">Requested to</label>
                    <input type="date" id="transfer_date_to" name="transfer_date_to" value="{{ $f['transfer_date_to'] }}" class="rounded-lg border border-slate-300 px-2 py-1.5 text-sm">
                </div>
                <div class="flex min-w-[10rem] flex-1 flex-col gap-1 sm:min-w-[12rem]">
                    <label class="text-xs font-medium text-slate-600" for="transfer_q">Transfer #</label>
                    <input type="text" id="transfer_q" name="transfer_q" value="{{ $f['transfer_q'] }}" placeholder="Contains…" class="rounded-lg border border-slate-300 px-2 py-1.5 text-sm">
                </div>
                <div class="flex flex-wrap gap-2">
                    <button type="submit" class="rounded-lg bg-slate-900 px-3 py-2 text-sm font-medium text-white hover:bg-slate-800">Apply</button>
                    <a href="{{ route('locations.show', $location) }}?tab=transfers#location-activity" class="rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-700 hover:bg-slate-50">Reset</a>
                </div>
            </form>
            <x-pagination-limit-selector :page-params="$locationPaginationPageParams" fragment="location-activity" />
            <div class="overflow-x-auto rounded-xl ring-1 ring-slate-200">
                <table class="min-w-full text-sm whitespace-nowrap">
                    <thead class="border-b border-slate-200 bg-slate-50 text-left text-xs font-semibold uppercase tracking-wider text-slate-700">
                        <tr>
                            <th class="px-3 py-2">Transfer</th>
                            <th class="px-3 py-2">From</th>
                            <th class="px-3 py-2">To</th>
                            <th class="px-3 py-2">Status</th>
                            <th class="px-3 py-2 text-right"></th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($stockTransfers as $t)
                            <tr class="border-b border-slate-100 last:border-0">
                                <td class="px-3 py-2 font-mono text-xs">{{ $t->transfer_number }}</td>
                                <td class="px-3 py-2">{{ $t->sourceLocation?->code ?? '—' }}</td>
                                <td class="px-3 py-2">{{ $t->destinationLocation?->code ?? '—' }}</td>
                                <td class="px-3 py-2">{{ ucfirst(str_replace('_', ' ', $t->status)) }}</td>
                                <td class="px-3 py-2 text-right">
                                    @can('view', $t)
                                        <a href="{{ route('transfers.show', $t) }}" class="text-xs font-medium text-indigo-600 hover:underline">View</a>
                                    @endcan
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="px-3 py-10 text-center text-slate-500">No transfers involving this location.</td></tr>
                        @endforelse
                    </tbody>
                    <tfoot>
                        <tr class="border-t-2 border-slate-200 bg-slate-50/90 font-semibold text-slate-900">
                            <td colspan="5" class="px-3 py-2 text-right text-xs font-bold uppercase tracking-wide text-slate-600">Transfers matched (filtered): <span class="text-sm tabular-nums text-slate-900">{{ number_format($stockTransfers->total()) }}</span></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
            <div class="mt-3">{{ $stockTransfers->links() }}</div>
        </div>

        {{-- Access & payments --}}
        <div class="p-4 sm:p-5 @if($activeLocationTab !== 'access') hidden @endif">
            <div class="grid gap-5 lg:grid-cols-2">
                <div class="overflow-hidden rounded-xl ring-1 ring-slate-200">
                    <div class="border-b border-slate-200 bg-slate-50 px-4 py-3 text-sm font-semibold text-slate-800">Payment methods</div>
                    <div class="p-4 text-sm text-slate-700">
                        @if ($location->paymentMethods->isEmpty())
                            <p class="text-slate-500">No location-specific payment methods configured.</p>
                        @else
                            <ul class="space-y-2">
                                @foreach ($location->paymentMethods as $method)
                                    <li class="flex items-center justify-between gap-2">
                                        <span>{{ $method->name }}</span>
                                        <span class="text-xs {{ $method->is_active ? 'text-emerald-700' : 'text-rose-700' }}">{{ $method->is_active ? 'Active' : 'Inactive' }}</span>
                                    </li>
                                @endforeach
                            </ul>
                        @endif
                    </div>
                </div>
                <div class="overflow-hidden rounded-xl ring-1 ring-slate-200">
                    <div class="border-b border-slate-200 bg-slate-50 px-4 py-3 text-sm font-semibold text-slate-800">Wallets</div>
                    <div class="p-4 text-sm text-slate-700">
                        @if ($location->wallets->isEmpty())
                            <p class="text-slate-500">No wallets linked to this location.</p>
                        @else
                            <ul class="space-y-2">
                                @foreach ($location->wallets as $wallet)
                                    <li class="flex flex-wrap items-center justify-between gap-2">
                                        <span>{{ $wallet->code }} — {{ $wallet->name }}</span>
                                        @can('view', $wallet)
                                            <a href="{{ route('wallets.show', $wallet) }}" class="text-xs font-medium text-indigo-600 hover:underline">View</a>
                                        @endcan
                                    </li>
                                @endforeach
                            </ul>
                        @endif
                    </div>
                </div>
                <div class="overflow-hidden rounded-xl ring-1 ring-slate-200">
                    <div class="border-b border-slate-200 bg-slate-50 px-4 py-3 text-sm font-semibold text-slate-800">Primary branch users</div>
                    <div class="p-4 text-sm text-slate-700">
                        @if ($location->users->isEmpty())
                            <p class="text-slate-500">No users assigned with this location as primary branch.</p>
                        @else
                            <ul class="space-y-2">
                                @foreach ($location->users as $user)
                                    <li>{{ $user->name }} <span class="text-xs text-slate-500">({{ $user->email }})</span></li>
                                @endforeach
                            </ul>
                        @endif
                    </div>
                </div>
                <div class="overflow-hidden rounded-xl ring-1 ring-slate-200">
                    <div class="border-b border-slate-200 bg-slate-50 px-4 py-3 text-sm font-semibold text-slate-800">Accessible by users</div>
                    <div class="p-4 text-sm text-slate-700">
                        @if ($location->accessibleByUsers->isEmpty())
                            <p class="text-slate-500">No user access mappings found.</p>
                        @else
                            <ul class="space-y-2">
                                @foreach ($location->accessibleByUsers as $user)
                                    <li>{{ $user->name }} <span class="text-xs text-slate-500">({{ $user->email }})</span></li>
                                @endforeach
                            </ul>
                        @endif
                    </div>
                </div>
            </div>
        </div>

        @if (in_array($location->type, ['branch', 'warehouse'], true))
            <div class="p-4 sm:p-5 @if($activeLocationTab !== 'targets') hidden @endif">
                @include('locations._annual-targets')
                @if (! empty($monthlyTargetProgress))
                    @include('locations._monthly-targets')
                @endif
            </div>
        @endif
    </section>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
    document.addEventListener('DOMContentLoaded', function() {
        const chartEl = document.getElementById('performanceChart');
        if (!chartEl) return;

        const ctx = chartEl.getContext('2d');
        new Chart(ctx, {
            type: 'line',
            data: {
                labels: @json($chartLabels ?? []),
                datasets: [
                    {
                        label: 'Sales Revenue',
                        data: @json($chartSales ?? []),
                        borderColor: '#10b981',
                        backgroundColor: 'rgba(16, 185, 129, 0.1)',
                        borderWidth: 2,
                        fill: true,
                        tension: 0.4
                    },
                    {
                        label: 'Operating Expenses',
                        data: @json($chartExpenses ?? []),
                        borderColor: '#f43f5e',
                        backgroundColor: 'rgba(244, 63, 94, 0.1)',
                        borderWidth: 2,
                        fill: true,
                        tension: 0.4
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { position: 'top' },
                    tooltip: {
                        mode: 'index',
                        intersect: false,
                    }
                },
                scales: {
                    y: { beginAtZero: true },
                    x: {
                        grid: { display: false }
                    }
                },
                interaction: {
                    mode: 'nearest',
                    axis: 'x',
                    intersect: false
                }
            }
        });
    });
</script>
