@extends('layouts.app')

@section('title', 'Purchase Order Details')
@section('page-title', 'Purchase Order Details')

@php
    $poPaymentDrawerOpen = old('__po_payment') === '1';
    $poCostDrawerOpen = old('__po_cost') === '1';
    $poRefundDrawerOpen = old('__po_refund') === '1';
    $supplierCredit = $purchaseOrder->supplierCreditBalance();
@endphp
@section('content')
    <div class="space-y-5" x-data="{ paymentDrawerOpen: @js($poPaymentDrawerOpen), costDrawerOpen: @js($poCostDrawerOpen), refundDrawerOpen: @js($poRefundDrawerOpen), historyDrawerOpen: false }">
        @if (session('status'))
            <div class="rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">{{ session('status') }}</div>
        @endif
        @if ($errors->any())
            <div class="rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-800">
                <ul class="list-disc pl-5">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif
        <section class="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-slate-200">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <p class="text-xs uppercase tracking-[0.2em] text-slate-500">Purchase Order</p>
                    <h2 class="mt-1 font-mono text-2xl font-semibold text-slate-900">{{ $purchaseOrder->po_number }}</h2>
                    <p class="mt-2 text-sm text-slate-600">
                        Supplier: <span class="font-medium text-slate-800">{{ $purchaseOrder->supplier?->name }}</span>
                        · Warehouse: <span class="font-medium text-slate-800">{{ $purchaseOrder->destinationLocation?->name }}</span>
                    </p>
                </div>
                <div class="text-right text-sm text-slate-600">
                    <p>Status: <span class="font-semibold text-slate-900">{{ ucfirst(str_replace('_', ' ', $purchaseOrder->status)) }}</span></p>
                    <p>Ordered: <span class="font-semibold text-slate-900">{{ $purchaseOrder->ordered_at }}</span></p>
                    <p>Expected: <span class="font-semibold text-slate-900">{{ $purchaseOrder->expected_at ?? '-' }}</span></p>
                </div>
            </div>
            <div class="mt-4 flex flex-wrap gap-2">
                <a href="{{ route('purchase-orders.index') }}" class="rounded-lg border border-slate-300 px-3 py-1.5 text-sm text-slate-700 hover:bg-slate-100">Back</a>
                @can('approve', \App\Models\PurchaseOrder::class)
                    @if ($purchaseOrder->status === 'draft')
                        <form action="{{ route('purchase-orders.approve', $purchaseOrder) }}" method="post" class="inline" data-confirm="Approve this purchase order?">
                            @csrf
                            <button type="submit" class="rounded-lg border border-emerald-300 bg-emerald-600 px-3 py-1.5 text-sm font-medium text-white shadow-sm hover:bg-emerald-700">Approve</button>
                        </form>
                    @endif
                @endcan
                <a href="{{ route('purchase-orders.receipt', $purchaseOrder) }}" target="_blank" class="rounded-lg border border-emerald-200 bg-emerald-50 px-3 py-1.5 text-sm font-medium text-emerald-700 hover:bg-emerald-100">PO Receipt</a>
                @can('delete', $purchaseOrder)
                    @if ($purchaseOrder->status !== 'cancelled')
                        <form action="{{ route('purchase-orders.destroy', $purchaseOrder) }}" method="post" class="inline" data-confirm="Delete this purchase order? This will also remove linked receipts and payment journal entries.">
                            @csrf
                            @method('DELETE')
                            <button type="submit" class="rounded-lg border border-rose-200 bg-rose-50 px-3 py-1.5 text-sm font-medium text-rose-700 hover:bg-rose-100">Delete PO</button>
                        </form>
                    @endif
                @endcan
                @can('update', $purchaseOrder)
                    @if ($purchaseOrder->status !== 'cancelled')
                        <a href="{{ route('purchase-orders.edit', $purchaseOrder) }}" class="rounded-lg border border-blue-200 bg-blue-50 px-3 py-1.5 text-sm font-medium text-blue-700 hover:bg-blue-100">{{ $purchaseOrder->status === 'draft' ? 'Edit draft' : 'Edit purchase order' }}</a>
                        <button type="button" @click="costDrawerOpen = true" class="rounded-lg border border-violet-200 bg-violet-50 px-3 py-1.5 text-sm font-medium text-violet-700 hover:bg-violet-100">Add cost</button>
                        @if (! in_array($purchaseOrder->status, ['draft', 'cancelled'], true) && $amountDue > 0.009)
                            <button type="button" @click="paymentDrawerOpen = true" class="rounded-lg bg-slate-900 px-3 py-1.5 text-sm font-medium text-white shadow-sm hover:bg-slate-800">Register supplier payment</button>
                        @endif
                        @if (! in_array($purchaseOrder->status, ['draft', 'cancelled'], true) && $supplierCredit > 0.009)
                            <button type="button" @click="refundDrawerOpen = true" class="rounded-lg border border-sky-300 bg-sky-50 px-3 py-1.5 text-sm font-medium text-sky-800 hover:bg-sky-100">Refund excess to wallet</button>
                        @endif
                    @endif
                @endcan
                <button type="button" @click="historyDrawerOpen = true" class="rounded-lg border border-slate-200 bg-white px-3 py-1.5 text-sm font-medium text-slate-800 hover:bg-slate-50">Change history</button>
            </div>
            @if ($purchaseOrder->notes)
                <p class="mt-4 rounded-lg bg-slate-50 p-3 text-sm text-slate-700">{{ $purchaseOrder->notes }}</p>
            @endif
        </section>

        <section class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <div class="rounded-2xl bg-white p-4 shadow-sm ring-1 ring-slate-200">
                <p class="text-xs uppercase tracking-[0.16em] text-slate-500">Lines</p>
                <p class="mt-2 text-2xl font-semibold text-slate-900">{{ $purchaseOrder->lines->count() }}</p>
            </div>
            <div class="rounded-2xl bg-white p-4 shadow-sm ring-1 ring-slate-200">
                <p class="text-xs uppercase tracking-[0.16em] text-slate-500">Qty Ordered</p>
                <p class="mt-2 text-2xl font-semibold text-slate-900">{{ $orderedQty }}</p>
            </div>
            <div class="rounded-2xl bg-white p-4 shadow-sm ring-1 ring-slate-200">
                <p class="text-xs uppercase tracking-[0.16em] text-slate-500">Qty Received</p>
                <p class="mt-2 text-2xl font-semibold text-slate-900">{{ $receivedQty }}</p>
            </div>
            <div class="rounded-2xl bg-white p-4 shadow-sm ring-1 ring-slate-200">
                <p class="text-xs uppercase tracking-[0.16em] text-slate-500">Pending Qty</p>
                <p class="mt-2 text-2xl font-semibold text-slate-900">{{ $pendingQty }}</p>
            </div>
        </section>

        <section class="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-slate-200">
            <h3 class="text-base font-semibold text-slate-900">Purchase Order Lines</h3>
            <p class="mt-1 text-xs text-slate-500">Landed cost spreads the PO grand total (discount, tax, additional costs) across each line in proportion to its merchandise line total, then divides by qty ordered. Received column uses the same rate × qty received.</p>
            <div class="mt-3 overflow-x-auto">
                <table class="min-w-full text-sm whitespace-nowrap">
                    <thead class="bg-slate-50 border-b border-slate-200 text-left text-xs font-semibold uppercase tracking-wider text-slate-700">
                        <tr>
                            <th class="px-3 py-2">Product</th>
                            <th class="px-3 py-2">Qty Ordered</th>
                            <th class="px-3 py-2">Qty Received</th>
                            <th class="px-3 py-2">Unit Cost</th>
                            <th class="px-3 py-2">Line tax</th>
                            <th class="px-3 py-2">Line Total</th>
                            <th class="px-3 py-2">Landed line</th>
                            <th class="px-3 py-2">Landed unit</th>
                            <th class="px-3 py-2">Landed received</th>
                            <th class="px-3 py-2 text-right">Product</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($purchaseOrder->lines as $line)
                            @php $landed = $landedLineAllocations[$line->id] ?? null; @endphp
                            <tr class="border-b border-slate-100 transition-colors hover:bg-slate-50/70 last:border-0">
                                <td class="px-3 py-2">{{ $line->product?->sku }} - {{ $line->product?->name }}</td>
                                <td class="px-3 py-2">{{ $line->qty_ordered }}</td>
                                <td class="px-3 py-2">{{ $line->qty_received }}</td>
                                <td class="px-3 py-2">{{ number_format($line->unit_cost, 2) }}</td>
                                <td class="px-3 py-2">{{ number_format($line->tax_amount, 2) }}</td>
                                <td class="px-3 py-2">{{ number_format($line->line_total, 2) }}</td>
                                <td class="px-3 py-2 font-medium text-slate-800">{{ $landed ? 'Tk '.number_format($landed['landed_line_total'], 2) : '—' }}</td>
                                <td class="px-3 py-2 font-medium text-slate-800">{{ $landed ? 'Tk '.number_format($landed['landed_unit_cost'], 2) : '—' }}</td>
                                <td class="px-3 py-2 text-slate-700">{{ $landed ? 'Tk '.number_format($landed['landed_received_total'], 2) : '—' }}</td>
                                <td class="px-3 py-2 text-right">
                                    @if ($line->product)
                                        @can('update', $line->product)
                                            <a
                                                href="{{ route('products.edit', $line->product) }}"
                                                target="_blank"
                                                rel="noopener"
                                                class="whitespace-nowrap rounded-md border border-indigo-200 bg-indigo-50 px-2 py-1 text-xs font-semibold text-indigo-700 hover:bg-indigo-100"
                                                title="Open this product to change its price"
                                            >Edit product / price</a>
                                        @endcan
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="10" class="px-3 py-4 text-center text-slate-500">No lines found.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>

        <section class="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-slate-200">
            <h3 class="text-base font-semibold text-slate-900">Order totals &amp; supplier payment</h3>
            <p class="mt-1 text-xs text-slate-500">Subtotal from lines, then order-level discount and tax. Grand total includes additional costs. Paid includes supplier payments plus additional costs already paid from wallet.</p>
            <div class="mt-4 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <div class="rounded-xl border border-slate-200 bg-slate-50 p-4">
                    <p class="text-xs uppercase tracking-[0.16em] text-slate-500">Subtotal</p>
                    <p class="mt-2 text-xl font-semibold text-slate-900">Tk {{ number_format((float) $purchaseOrder->subtotal_amount, 2) }}</p>
                </div>
                <div class="rounded-xl border border-slate-200 bg-slate-50 p-4">
                    <p class="text-xs uppercase tracking-[0.16em] text-slate-500">Discount</p>
                    <p class="mt-2 text-xl font-semibold text-slate-900">Tk {{ number_format((float) $purchaseOrder->discount_amount, 2) }}</p>
                </div>
                <div class="rounded-xl border border-slate-200 bg-slate-50 p-4">
                    <p class="text-xs uppercase tracking-[0.16em] text-slate-500">Tax</p>
                    <p class="mt-2 text-xl font-semibold text-slate-900">Tk {{ number_format((float) $purchaseOrder->tax_amount, 2) }}</p>
                </div>
                <div class="rounded-xl border border-slate-200 bg-slate-50 p-4">
                    <p class="text-xs uppercase tracking-[0.16em] text-slate-500">Additional costs</p>
                    <p class="mt-2 text-xl font-semibold text-slate-900">Tk {{ number_format((float) $purchaseOrder->additional_cost_amount, 2) }}</p>
                </div>
                <div class="rounded-xl border border-emerald-200 bg-emerald-50/80 p-4">
                    <p class="text-xs uppercase tracking-[0.16em] text-emerald-800">Grand total</p>
                    <p class="mt-2 text-xl font-semibold text-emerald-900">Tk {{ number_format((float) $purchaseOrder->total_amount, 2) }}</p>
                </div>
            </div>
            @if (is_array($purchaseOrder->additional_costs) && count($purchaseOrder->additional_costs))
                <div class="mt-4 rounded-xl border border-slate-200 bg-white p-4">
                    <p class="text-xs uppercase tracking-[0.16em] text-slate-500">Additional cost breakdown</p>
                    <div class="mt-2 space-y-1 text-sm text-slate-700">
                        @foreach ($purchaseOrder->additional_costs as $cost)
                            <div class="flex flex-wrap items-center justify-between gap-2">
                                <span>
                                    {{ $cost['label'] ?? 'Misc Cost' }}
                                    <span class="text-xs text-slate-500">({{ $cost['paid_at'] ?? '—' }})</span>
                                    @if (! empty($cost['wallet_code'] ?? $cost['wallet_name'] ?? null))
                                        <span class="text-xs text-slate-500">· {{ $cost['wallet_code'] ?? '' }}{{ ($cost['wallet_code'] ?? '') && ($cost['wallet_name'] ?? '') ? ' — ' : '' }}{{ $cost['wallet_name'] ?? '' }}</span>
                                    @endif
                                </span>
                                <span class="font-medium">Tk {{ number_format((float) ($cost['amount'] ?? 0), 2) }}</span>
                            </div>
                        @endforeach
                    </div>
                </div>
            @endif
            <div class="mt-4 grid gap-4 sm:grid-cols-3">
                <div class="rounded-xl border border-slate-200 bg-white p-4">
                    <p class="text-xs uppercase tracking-[0.16em] text-slate-500">Paid toward PO</p>
                    <p class="mt-2 text-2xl font-semibold text-slate-900">Tk {{ number_format($amountPaid, 2) }}</p>
                    <p class="mt-1 text-xs text-slate-500">Supplier Tk {{ number_format($purchaseOrder->supplierPaymentsTotal(), 2) }} · Additional costs Tk {{ number_format((float) $purchaseOrder->additional_cost_amount, 2) }}</p>
                </div>
                <div class="rounded-xl border border-amber-200 bg-amber-50/80 p-4">
                    <p class="text-xs uppercase tracking-[0.16em] text-amber-900">Balance due</p>
                    <p class="mt-2 text-2xl font-semibold text-amber-950">Tk {{ number_format($amountDue, 2) }}</p>
                    @if ($supplierCredit > 0.009)
                        <p class="mt-2 text-xs font-medium text-sky-800">Excess paid: Tk {{ number_format($supplierCredit, 2) }} (paid more than current grand total)</p>
                    @endif
                </div>
                <div class="rounded-xl border border-slate-200 bg-slate-50 p-4">
                    <p class="text-xs uppercase tracking-[0.16em] text-slate-500">Line value (qty × cost)</p>
                    <p class="mt-2 text-lg font-semibold text-slate-800">Tk {{ number_format($orderedAmount, 2) }}</p>
                    <p class="mt-1 text-xs text-slate-500">Received value: Tk {{ number_format($receivedAmount, 2) }} · Pending: Tk {{ number_format($pendingAmount, 2) }}</p>
                </div>
            </div>
            @if (! in_array($purchaseOrder->status, ['draft', 'cancelled'], true))
                @can('update', $purchaseOrder)
                    <div class="mt-4 flex flex-wrap items-center gap-3 border-t border-slate-100 pt-4">
                        @if ($amountDue <= 0.009)
                            @if ($supplierCredit > 0.009)
                                <p class="text-sm text-sky-800">Nothing further is owed. Excess of <strong>Tk {{ number_format($supplierCredit, 2) }}</strong> can be refunded into any branch wallet (reduces paid total via journal).</p>
                                <button type="button" @click="refundDrawerOpen = true" class="rounded-lg border border-sky-300 bg-sky-50 px-3 py-1.5 text-sm font-medium text-sky-800 hover:bg-sky-100">Refund excess to wallet</button>
                            @else
                                <p class="text-sm text-slate-600">This purchase order is fully paid.</p>
                            @endif
                        @endif
                        @if ($supplierPaymentWallets->isEmpty())
                            <p class="max-w-xl text-sm text-amber-800">{{ $ownerCapitalWalletCode ?? 'OWNER-0001' }} wallet is required for supplier payments. Make sure it is active, has an active ledger account, and applies to this warehouse.</p>
                        @endif
                    </div>
                @endcan
            @endif
        </section>

        @can('update', $purchaseOrder)
            @if ($purchaseOrder->status !== 'cancelled')
                <div x-cloak x-show="costDrawerOpen" class="fixed inset-0 z-40" x-transition.opacity>
                    <button type="button" @click="costDrawerOpen = false" class="absolute inset-0 bg-slate-900/40" aria-label="Close"></button>
                    <div class="absolute inset-y-0 right-0 flex w-full max-w-md flex-col overflow-hidden bg-white shadow-2xl">
                        <div class="flex shrink-0 items-center justify-between border-b border-slate-200 px-5 py-4">
                            <h2 class="text-lg font-semibold text-slate-900">Add additional cost</h2>
                            <button type="button" @click="costDrawerOpen = false" class="text-sm font-medium text-slate-600 hover:text-slate-900">Close</button>
                        </div>
                        <form action="{{ route('purchase-orders.additional-costs.store', $purchaseOrder) }}" method="post" class="flex flex-1 flex-col overflow-y-auto p-5">
                            @csrf
                            <input type="hidden" name="__po_cost" value="1">
                            <div class="space-y-4">
                                <div>
                                    <label for="po_cost_label" class="text-sm font-medium text-slate-700">Cost head</label>
                                    <input id="po_cost_label" type="text" name="label" value="{{ old('label') }}" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm" maxlength="120" placeholder="Labour / Transport / Loading" required>
                                </div>
                                <div>
                                    <label for="po_cost_amount" class="text-sm font-medium text-slate-700">Amount</label>
                                    <input id="po_cost_amount" type="number" step="0.01" min="0.01" name="amount" value="{{ old('amount') }}" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm" required>
                                </div>
                                <div>
                                    <label for="po_cost_paid_at" class="text-sm font-medium text-slate-700">Payment date</label>
                                    <input id="po_cost_paid_at" type="date" name="paid_at" value="{{ old('paid_at', now()->toDateString()) }}" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm" required>
                                </div>
                                <div>
                                    <label for="po_cost_wallet_id" class="text-sm font-medium text-slate-700">Paid from wallet</label>
                                    <select id="po_cost_wallet_id" name="wallet_id" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm" required @disabled($paymentWallets->isEmpty())>
                                        <option value="">Select wallet…</option>
                                        @foreach ($paymentWallets as $w)
                                            <option value="{{ $w->id }}" @selected((string) old('wallet_id') === (string) $w->id)>{{ $w->code }} — {{ $w->name }}</option>
                                        @endforeach
                                    </select>
                                    @if ($paymentWallets->isEmpty())
                                        <p class="mt-1 text-xs text-amber-800">No active wallet for this warehouse. Create or link a wallet first.</p>
                                    @endif
                                </div>
                                <p class="text-xs text-slate-500">This amount will be added to PO additional costs and grand total immediately. A journal entry will debit logistics expense and credit the selected wallet.</p>
                            </div>
                            <div class="mt-6 flex shrink-0 flex-wrap gap-2 border-t border-slate-100 pt-4">
                                <button type="submit" class="rounded-lg bg-slate-900 px-4 py-2 text-sm font-medium text-white hover:bg-slate-800 disabled:cursor-not-allowed disabled:opacity-50" {{ $paymentWallets->isEmpty() ? 'disabled' : '' }}>Save cost</button>
                                <button type="button" @click="costDrawerOpen = false" class="rounded-lg border border-slate-300 px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50">Cancel</button>
                            </div>
                        </form>
                    </div>
                </div>
            @endif
        @endcan

        <div x-cloak x-show="historyDrawerOpen" class="fixed inset-0 z-40" x-transition.opacity>
            <button type="button" @click="historyDrawerOpen = false" class="absolute inset-0 bg-slate-900/40" aria-label="Close"></button>
            <div class="absolute inset-y-0 right-0 flex w-full max-w-lg flex-col overflow-hidden bg-white shadow-2xl">
                <div class="flex shrink-0 items-center justify-between border-b border-slate-200 px-5 py-4">
                    <h2 class="text-lg font-semibold text-slate-900">Change history</h2>
                    <button type="button" @click="historyDrawerOpen = false" class="text-sm font-medium text-slate-600 hover:text-slate-900">Close</button>
                </div>
                <div class="flex-1 overflow-y-auto p-5">
                    @forelse ($purchaseOrder->changeLogs as $log)
                        <article class="mb-6 border-b border-slate-100 pb-6 last:mb-0 last:border-0 last:pb-0">
                            <div class="flex flex-wrap items-baseline justify-between gap-2">
                                <p class="text-sm font-semibold text-slate-900">
                                    @if ($log->event === 'approved')
                                        Approved
                                    @else
                                        Updated
                                    @endif
                                </p>
                                <time class="text-xs text-slate-500" datetime="{{ $log->created_at?->toIso8601String() }}">{{ $log->created_at?->format('Y-m-d H:i') }}</time>
                            </div>
                            <p class="mt-1 text-xs text-slate-600">By {{ $log->user?->name ?? 'Unknown user' }}</p>
                            @php
                                $chg = $log->changes ?? [];
                                $attrs = $chg['attributes'] ?? [];
                                $lineMsgs = $chg['lines'] ?? [];
                            @endphp
                            @if (count($attrs))
                                <p class="mt-3 text-xs font-semibold uppercase tracking-wide text-slate-500">Header &amp; totals</p>
                                <ul class="mt-2 space-y-2 text-sm text-slate-700">
                                    @foreach ($attrs as $row)
                                        <li>
                                            <span class="font-medium text-slate-800">{{ $row['label'] ?? 'Field' }}</span>
                                            <span class="text-slate-500"> from </span>
                                            <span class="rounded bg-slate-100 px-1.5 py-0.5 font-mono text-xs">{{ ($row['before'] ?? '') !== '' ? $row['before'] : '—' }}</span>
                                            <span class="text-slate-500"> to </span>
                                            <span class="rounded bg-emerald-50 px-1.5 py-0.5 font-mono text-xs text-emerald-900">{{ ($row['after'] ?? '') !== '' ? $row['after'] : '—' }}</span>
                                        </li>
                                    @endforeach
                                </ul>
                            @endif
                            @if (count($lineMsgs))
                                <p class="mt-3 text-xs font-semibold uppercase tracking-wide text-slate-500">Lines</p>
                                <ul class="mt-2 list-disc space-y-1 pl-5 text-sm text-slate-700">
                                    @foreach ($lineMsgs as $msg)
                                        <li>{{ $msg }}</li>
                                    @endforeach
                                </ul>
                            @endif
                            @if (! count($attrs) && ! count($lineMsgs))
                                <p class="mt-2 text-sm text-slate-500">No detail rows stored for this event.</p>
                            @endif
                        </article>
                    @empty
                        <p class="text-sm text-slate-600">No changes recorded yet. Updates and approvals will appear here.</p>
                    @endforelse
                </div>
            </div>
        </div>

        @if (! in_array($purchaseOrder->status, ['draft', 'cancelled'], true))
            @can('update', $purchaseOrder)
                <div x-cloak x-show="paymentDrawerOpen" class="fixed inset-0 z-40" x-transition.opacity>
                    <button type="button" @click="paymentDrawerOpen = false" class="absolute inset-0 bg-slate-900/40" aria-label="Close"></button>
                    <div class="absolute inset-y-0 right-0 flex w-full max-w-md flex-col overflow-hidden bg-white shadow-2xl">
                        <div class="flex shrink-0 items-center justify-between border-b border-slate-200 px-5 py-4">
                            <h2 class="text-lg font-semibold text-slate-900">Register supplier payment</h2>
                            <button type="button" @click="paymentDrawerOpen = false" class="text-sm font-medium text-slate-600 hover:text-slate-900">Close</button>
                        </div>
                        <form action="{{ route('purchase-orders.payments.store', $purchaseOrder) }}" method="post" class="flex flex-1 flex-col overflow-y-auto p-5">
                            @csrf
                            <input type="hidden" name="__po_payment" value="1">
                            <div class="space-y-4">
                                <div>
                                    <label for="po_pay_wallet_id" class="text-sm font-medium text-slate-700">Pay from wallet</label>
                                    @if ($supplierPaymentWallets->count() === 1)
                                        @php $ownerPayWallet = $supplierPaymentWallets->first(); @endphp
                                        <input type="hidden" name="wallet_id" value="{{ $ownerPayWallet->id }}">
                                        <div id="po_pay_wallet_id" class="mt-1 rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-sm text-slate-800">
                                            <span class="font-semibold">{{ $ownerPayWallet->code }}</span>
                                            <span class="text-slate-500">— {{ $ownerPayWallet->name }}</span>
                                        </div>
                                        <p class="mt-1 text-xs text-slate-500">Supplier payments always use {{ $ownerCapitalWalletCode ?? 'OWNER-0001' }}.</p>
                                    @else
                                        <select id="po_pay_wallet_id" name="wallet_id" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm" required disabled>
                                            <option value="">{{ $ownerCapitalWalletCode ?? 'OWNER-0001' }} unavailable…</option>
                                        </select>
                                        <p class="mt-1 text-xs text-amber-800">{{ $ownerCapitalWalletCode ?? 'OWNER-0001' }} wallet is required and must be active for this warehouse.</p>
                                    @endif
                                </div>
                                <div>
                                    <label for="po_pay_amount" class="text-sm font-medium text-slate-700">Cash amount</label>
                                    <input id="po_pay_amount" type="number" step="0.01" min="0.01" name="amount" value="{{ old('amount') }}" max="{{ $amountDue }}" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm" required @disabled($amountDue <= 0 || $supplierPaymentWallets->isEmpty())>
                                </div>
                                <div>
                                    <label for="po_pay_settlement_discount" class="text-sm font-medium text-slate-700">Settlement discount</label>
                                    <input id="po_pay_settlement_discount" type="number" step="0.01" min="0" name="settlement_discount" value="{{ old('settlement_discount', '0') }}" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm" @disabled($amountDue <= 0 || $supplierPaymentWallets->isEmpty())>
                                    <p class="mt-1 text-xs text-slate-500">Cash plus discount cannot exceed the balance due.</p>
                                </div>
                                <div>
                                    <label for="po_pay_paid_at" class="text-sm font-medium text-slate-700">Paid on</label>
                                    <input id="po_pay_paid_at" type="date" name="paid_at" value="{{ old('paid_at', now()->toDateString()) }}" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm" required @disabled($supplierPaymentWallets->isEmpty())>
                                </div>
                                <div>
                                    <label for="po_pay_reference" class="text-sm font-medium text-slate-700">Reference (optional)</label>
                                    <input id="po_pay_reference" type="text" name="reference" value="{{ old('reference') }}" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm" maxlength="120" placeholder="Cheque / transfer ref" @disabled($supplierPaymentWallets->isEmpty())>
                                </div>
                                <div>
                                    <label for="po_pay_notes" class="text-sm font-medium text-slate-700">Notes</label>
                                    <input id="po_pay_notes" type="text" name="notes" value="{{ old('notes') }}" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm" maxlength="500" @disabled($supplierPaymentWallets->isEmpty())>
                                </div>
                            </div>
                            <div class="mt-6 flex shrink-0 flex-wrap gap-2 border-t border-slate-100 pt-4">
                                <button type="submit" class="rounded-lg bg-slate-900 px-4 py-2 text-sm font-medium text-white hover:bg-slate-800 disabled:cursor-not-allowed disabled:opacity-50" {{ $amountDue <= 0 || $supplierPaymentWallets->isEmpty() ? 'disabled' : '' }}>Save payment</button>
                                <button type="button" @click="paymentDrawerOpen = false" class="rounded-lg border border-slate-300 px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50">Cancel</button>
                            </div>
                        </form>
                    </div>
                </div>

                @if ($supplierCredit > 0.009 || $poRefundDrawerOpen)
                    <div x-cloak x-show="refundDrawerOpen" class="fixed inset-0 z-40" x-transition.opacity>
                        <button type="button" @click="refundDrawerOpen = false" class="absolute inset-0 bg-slate-900/40" aria-label="Close"></button>
                        <div class="absolute inset-y-0 right-0 flex w-full max-w-md flex-col overflow-hidden bg-white shadow-2xl">
                            <div class="flex shrink-0 items-center justify-between border-b border-slate-200 px-5 py-4">
                                <h2 class="text-lg font-semibold text-slate-900">Refund excess to wallet</h2>
                                <button type="button" @click="refundDrawerOpen = false" class="text-sm font-medium text-slate-600 hover:text-slate-900">Close</button>
                            </div>
                            <form action="{{ route('purchase-orders.refunds.store', $purchaseOrder) }}" method="post" class="flex flex-1 flex-col overflow-y-auto p-5">
                                @csrf
                                <input type="hidden" name="__po_refund" value="1">
                                <div class="space-y-4">
                                    <p class="rounded-lg border border-sky-200 bg-sky-50 px-3 py-2 text-sm text-sky-900">
                                        Excess available: <strong>Tk {{ number_format($supplierCredit, 2) }}</strong>.
                                        Refunding reduces the PO paid total and posts a journal (debit wallet, credit AP).
                                    </p>
                                    <div>
                                        <label for="po_refund_wallet_id" class="text-sm font-medium text-slate-700">Refund into wallet</label>
                                        <select id="po_refund_wallet_id" name="wallet_id" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm" required @disabled($paymentWallets->isEmpty())>
                                            <option value="">Select wallet…</option>
                                            @foreach ($paymentWallets as $w)
                                                <option value="{{ $w->id }}" @selected((string) old('wallet_id') === (string) $w->id)>{{ $w->code }} — {{ $w->name }}</option>
                                            @endforeach
                                        </select>
                                        @if ($paymentWallets->isEmpty())
                                            <p class="mt-1 text-xs text-amber-800">No active wallet for this warehouse. Create or link a wallet first.</p>
                                        @else
                                            <p class="mt-1 text-xs text-slate-500">Any active branch wallet can receive the excess.</p>
                                        @endif
                                    </div>
                                    <div>
                                        <label for="po_refund_amount" class="text-sm font-medium text-slate-700">Refund amount</label>
                                        <input id="po_refund_amount" type="number" step="0.01" min="0.01" max="{{ $supplierCredit }}" name="amount" value="{{ old('amount', number_format($supplierCredit, 2, '.', '')) }}" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm" required @disabled($paymentWallets->isEmpty())>
                                    </div>
                                    <div>
                                        <label for="po_refund_paid_at" class="text-sm font-medium text-slate-700">Refund date</label>
                                        <input id="po_refund_paid_at" type="date" name="paid_at" value="{{ old('paid_at', now()->toDateString()) }}" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm" required @disabled($paymentWallets->isEmpty())>
                                    </div>
                                    <div>
                                        <label for="po_refund_reference" class="text-sm font-medium text-slate-700">Reference (optional)</label>
                                        <input id="po_refund_reference" type="text" name="reference" value="{{ old('reference') }}" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm" maxlength="120" placeholder="Cheque / transfer ref" @disabled($paymentWallets->isEmpty())>
                                    </div>
                                    <div>
                                        <label for="po_refund_notes" class="text-sm font-medium text-slate-700">Notes</label>
                                        <input id="po_refund_notes" type="text" name="notes" value="{{ old('notes') }}" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm" maxlength="500" @disabled($paymentWallets->isEmpty())>
                                    </div>
                                </div>
                                <div class="mt-6 flex shrink-0 flex-wrap gap-2 border-t border-slate-100 pt-4">
                                    <button type="submit" class="rounded-lg bg-sky-800 px-4 py-2 text-sm font-medium text-white hover:bg-sky-900 disabled:cursor-not-allowed disabled:opacity-50" {{ $paymentWallets->isEmpty() ? 'disabled' : '' }}>Save refund</button>
                                    <button type="button" @click="refundDrawerOpen = false" class="rounded-lg border border-slate-300 px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50">Cancel</button>
                                </div>
                            </form>
                        </div>
                    </div>
                @endif
            @endcan

            <section class="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-slate-200">
                <h3 class="text-base font-semibold text-slate-900">Payment history</h3>
                <div class="mt-3 overflow-x-auto">
                    <table class="min-w-full text-sm whitespace-nowrap">
                        <thead class="bg-slate-50 border-b border-slate-200 text-left text-xs font-semibold uppercase tracking-wider text-slate-700">
                            <tr>
                                <th class="px-3 py-2">Date</th>
                                <th class="px-3 py-2">Wallet</th>
                                <th class="px-3 py-2">Amount</th>
                                <th class="px-3 py-2">Discount</th>
                                <th class="px-3 py-2">Reference</th>
                                <th class="px-3 py-2">Recorded by</th>
                                <th class="px-3 py-2">Notes</th>
                                <th class="px-3 py-2 text-right">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($purchaseOrder->payments as $pay)
                                @php $isRefund = $pay->isRefund(); @endphp
                                <tr class="border-b border-slate-100 transition-colors hover:bg-slate-50/70 last:border-0 {{ $isRefund ? 'bg-sky-50/40' : '' }}">
                                    <td class="px-3 py-2">{{ $pay->paid_at?->format('Y-m-d') }}</td>
                                    <td class="px-3 py-2 text-slate-700">{{ $pay->wallet ? $pay->wallet->code.' — '.$pay->wallet->name : '—' }}</td>
                                    <td class="px-3 py-2 font-medium {{ $isRefund ? 'text-sky-800' : '' }}">
                                        @if ($isRefund)
                                            <span class="mr-1 rounded bg-sky-100 px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-sky-800">Refund</span>
                                        @endif
                                        Tk {{ number_format((float) $pay->amount, 2) }}
                                    </td>
                                    <td class="px-3 py-2 text-indigo-800">{{ (float) $pay->settlement_discount > 0.009 ? number_format((float) $pay->settlement_discount, 2) : '—' }}</td>
                                    <td class="px-3 py-2">{{ $pay->reference ?: '—' }}</td>
                                    <td class="px-3 py-2">{{ $pay->creator?->name ?? '—' }}</td>
                                    <td class="px-3 py-2 text-slate-600">{{ $pay->notes ?: '—' }}</td>
                                    <td class="px-3 py-2 text-right">
                                        @can('delete-payment-allocation')
                                            @can('update', $purchaseOrder)
                                                @if ($purchaseOrder->status !== 'cancelled')
                                                    <form
                                                        action="{{ route('purchase-orders.payments.destroy', [$purchaseOrder, $pay]) }}"
                                                        method="post"
                                                        class="inline"
                                                        data-confirm="{{ $isRefund ? 'Remove this refund? Paid total and wallet/AP journal entries will be reversed.' : 'Remove this payment? Supplier balance due and wallet/AP journal entries for this transaction will be reversed.' }}"
                                                    >
                                                        @csrf
                                                        @method('DELETE')
                                                        <button type="submit" class="rounded-md border border-rose-200 bg-white px-2 py-1 text-xs font-semibold text-rose-700 hover:bg-rose-50">Remove</button>
                                                    </form>
                                                @endif
                                            @endcan
                                        @endcan
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="8" class="px-3 py-4 text-center text-slate-500">No payments yet.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </section>
        @endif

        <section class="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-slate-200">
            <h3 class="text-base font-semibold text-slate-900">Related Goods Receipts</h3>
            <div class="mt-3 overflow-x-auto">
                <table class="min-w-full text-sm whitespace-nowrap">
                    <thead class="bg-slate-50 border-b border-slate-200 text-left text-xs font-semibold uppercase tracking-wider text-slate-700">
                        <tr>
                            <th class="px-3 py-2">GRN</th>
                            <th class="px-3 py-2">Supplier</th>
                            <th class="px-3 py-2">Warehouse</th>
                            <th class="px-3 py-2">Received Date</th>
                            <th class="px-3 py-2">Lines</th>
                            <th class="px-3 py-2">Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($relatedGoodsReceipts as $grn)
                            <tr class="border-b border-slate-100 transition-colors hover:bg-slate-50/70 last:border-0">
                                <td class="px-3 py-2 font-mono">
                                    <a href="{{ route('goods-receipts.show', $grn) }}" class="text-blue-600 hover:underline">{{ $grn->grn_number }}</a>
                                </td>
                                <td class="px-3 py-2">
                                    @if ($grn->supplier)
                                        <a href="{{ route('suppliers.show', $grn->supplier) }}" class="text-blue-600 transition-all hover:underline hover:text-base">{{ $grn->supplier->name }}</a>
                                    @else
                                        —
                                    @endif
                                </td>
                                <td class="px-3 py-2">{{ $grn->location?->name }}</td>
                                <td class="px-3 py-2">{{ $grn->received_at }}</td>
                                <td class="px-3 py-2">{{ $grn->lines_count }}</td>
                                <td class="px-3 py-2">{{ ucfirst($grn->status) }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="px-3 py-4 text-center text-slate-500">No related goods receipts yet.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>

        <section class="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-slate-200">
            <h3 class="text-base font-semibold text-slate-900">Supplier Payment References</h3>
            <div class="mt-3 overflow-x-auto">
                <table class="min-w-full text-sm whitespace-nowrap">
                    <thead class="bg-slate-50 border-b border-slate-200 text-left text-xs font-semibold uppercase tracking-wider text-slate-700">
                        <tr>
                            <th class="px-3 py-2">Paid Date</th>
                            <th class="px-3 py-2">Reference</th>
                            <th class="px-3 py-2">Wallet</th>
                            <th class="px-3 py-2 text-right">Amount</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($purchaseOrder->payments as $payRef)
                            <tr class="border-b border-slate-100 transition-colors hover:bg-slate-50/70 last:border-0">
                                <td class="px-3 py-2">{{ $payRef->paid_at?->format('Y-m-d') }}</td>
                                <td class="px-3 py-2 font-medium text-slate-800">{{ $payRef->reference ?: '—' }}</td>
                                <td class="px-3 py-2">{{ $payRef->wallet ? $payRef->wallet->code.' — '.$payRef->wallet->name : '—' }}</td>
                                <td class="px-3 py-2 text-right">{{ number_format((float) $payRef->amount, 2) }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="px-3 py-4 text-center text-slate-500">No supplier payments recorded yet.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>

        <section class="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-slate-200">
            <h3 class="text-base font-semibold text-slate-900">Related Invoice/Delivery References (from GRN)</h3>
            <div class="mt-3 overflow-x-auto">
                <table class="min-w-full text-sm whitespace-nowrap">
                    <thead class="bg-slate-50 border-b border-slate-200 text-left text-xs font-semibold uppercase tracking-wider text-slate-700">
                        <tr>
                            <th class="px-3 py-2">Invoice/Delivery Ref</th>
                            <th class="px-3 py-2">GRN</th>
                            <th class="px-3 py-2">Received Date</th>
                            <th class="px-3 py-2">Warehouse</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($relatedInvoices as $inv)
                            <tr class="border-b border-slate-100 transition-colors hover:bg-slate-50/70 last:border-0">
                                <td class="px-3 py-2 font-medium text-slate-800">{{ $inv->delivery_note_ref }}</td>
                                <td class="px-3 py-2 font-mono">
                                    <a href="{{ route('goods-receipts.show', $inv) }}" class="text-blue-600 hover:underline">{{ $inv->grn_number }}</a>
                                </td>
                                <td class="px-3 py-2">{{ $inv->received_at }}</td>
                                <td class="px-3 py-2">{{ $inv->location?->name }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="px-3 py-4 text-center text-slate-500">No GRN invoice/delivery references linked to this PO yet.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>
    </div>
@endsection
