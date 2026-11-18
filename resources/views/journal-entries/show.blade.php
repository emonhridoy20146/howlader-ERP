@extends('layouts.app')
@section('title', $entry->document_no)
@section('page-title', 'Journal '.$entry->document_no)
@section('content')
<style>
    @media print {
        aside#app-sidebar,
        nav[aria-label="Quick actions"],
        .journal-entry-no-print,
        body > div.lg\:hidden.fixed {
            display: none !important;
        }
        body {
            background: #fff !important;
        }
        main {
            margin-left: 0 !important;
            padding-top: 0 !important;
        }
        main > div.border-b {
            border: none !important;
            padding: 0.5rem 0 !important;
        }
        main > div.border-b h1 {
            font-size: 1.125rem !important;
        }
        .journal-entry-report {
            box-shadow: none !important;
            border: 1px solid #e2e8f0 !important;
        }
    }
</style>

<div class="journal-entry-no-print mb-4 flex flex-wrap items-center justify-between gap-3">
    <div class="flex items-center gap-3">
        <a href="{{ route('journal-entries.index') }}" class="text-sm text-slate-600 underline">← Back to journal</a>
    </div>
    <div class="flex items-center gap-3">
        @if ($entry->isEditableManualEntry())
            @can('update', $entry)
                <a
                    href="{{ route('journal-entries.edit', $entry) }}"
                    class="rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm font-medium text-slate-800 shadow-sm hover:bg-slate-50"
                >Edit</a>
            @endcan
        @endif
        @if ($entry->status === 'draft')
            @can('post', $entry)
                <form method="POST" action="{{ route('journal-entries.post', $entry) }}">
                    @csrf
                    <button
                        type="submit"
                        class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-medium text-white shadow-sm hover:bg-indigo-700"
                    >Post Voucher</button>
                </form>
            @endcan
        @endif
        @if ($entry->isEditableManualEntry() || $entry->status === 'draft' || (auth()->user()?->can('delete-posted-journal-entry') && $entry->isAdminPostedJournalDeleteOnly()))
            @can('delete', $entry)
                <form method="POST" action="{{ route('journal-entries.destroy', $entry) }}" data-confirm="{{ $entry->isEditableManualEntry() ? 'Delete this journal entry? Wallet balances will be updated.' : ($entry->status === 'draft' ? 'Delete this draft journal entry?' : 'Delete this journal entry only? Invoice, customer balance, and payment totals will not be updated.') }}">
                    @csrf
                    @method('DELETE')
                    <button
                        type="submit"
                        class="rounded-lg bg-red-600 px-4 py-2 text-sm font-medium text-white shadow-sm hover:bg-red-700"
                    >{{ $entry->isAdminPostedJournalDeleteOnly() ? 'Delete entry only' : 'Delete' }}{{ $entry->status === 'draft' && ! $entry->isEditableManualEntry() ? ' draft' : '' }}</button>
                </form>
            @endcan
        @endif
        @can('delete-payment-allocation')
            @if (! empty($linkedPaymentRemoval))
                <form
                    method="POST"
                    action="{{ route('journal-entries.linked-payment.destroy', $entry) }}"
                    data-confirm="Remove this payment? Customer balance, invoice totals, and all related journal entries will be reversed."
                >
                    @csrf
                    @method('DELETE')
                    <button
                        type="submit"
                        class="rounded-lg bg-rose-600 px-4 py-2 text-sm font-medium text-white shadow-sm hover:bg-rose-700"
                    >Remove payment</button>
                </form>
            @endif
        @endcan
        <button
            type="button"
            onclick="window.print()"
            class="rounded-lg border border-slate-300 bg-white px-3 py-1.5 text-sm font-medium text-slate-800 shadow-sm hover:bg-slate-50"
        >Print report</button>
    </div>
</div>

@if (session('status'))
    <div class="journal-entry-no-print mb-4 rounded-lg bg-emerald-50 p-3 text-sm text-emerald-700">{{ session('status') }}</div>
@endif
@if (session('error'))
    <div class="journal-entry-no-print mb-4 rounded-lg bg-red-50 p-3 text-sm text-red-700">{{ session('error') }}</div>
@endif

@php
    $totalDr = (float) $entry->lines->sum('debit');
    $totalCr = (float) $entry->lines->sum('credit');
    $fmtMoney = static fn (float $n): string => $n > 0 ? number_format($n, 2) : '—';

    $paymentCustomerBlock = null;
    if ($entry->source === 'sales_invoice' && $entry->reference instanceof \App\Models\SalesInvoice) {
        $inv = $entry->reference;
        $cust = $inv->customer;
        if ($cust) {
            $paymentCustomerBlock = [
                'title' => 'Customer',
                'payment_number' => null,
                'payment_date' => null,
                'customer' => $cust,
                'invoices' => collect([$inv])->filter()->unique('id')->values()->all(),
            ];
        }
    } elseif ($entry->source === 'sales_payment' && $entry->reference instanceof \App\Models\Payment) {
        $pay = $entry->reference;
        $cust = $pay->customer;
        if ($cust) {
            $invoices = $pay->paymentAllocations
                ->map(fn (\App\Models\PaymentAllocation $a) => $a->salesInvoice)
                ->filter()
                ->unique('id')
                ->values()
                ->all();
            $paymentCustomerBlock = [
                'title' => 'Customer',
                'payment_number' => $pay->payment_number,
                'payment_date' => $pay->payment_date,
                'customer' => $cust,
                'invoices' => $invoices,
            ];
        }
    } elseif ($entry->source === 'sales_payment_discount' && $entry->reference instanceof \App\Models\PaymentAllocation) {
        $alloc = $entry->reference;
        $cust = $alloc->payment?->customer ?? $alloc->salesInvoice?->customer;
        if ($cust) {
            $inv = $alloc->salesInvoice;
            $paymentCustomerBlock = [
                'title' => 'Customer',
                'payment_number' => $alloc->payment?->payment_number,
                'payment_date' => $alloc->payment?->payment_date,
                'customer' => $cust,
                'invoices' => collect([$inv])->filter()->unique('id')->values()->all(),
            ];
        }
    }
@endphp

<div class="journal-entry-no-print mb-6 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm ring-1 ring-slate-200">
    <dl class="grid gap-3 text-sm md:grid-cols-2">
        <div><dt class="text-slate-500">Date</dt><dd class="font-medium">{{ $entry->entry_date->format('Y-m-d') }}</dd></div>
        <div><dt class="text-slate-500">Branch</dt><dd class="font-medium">{{ $entry->branch?->name }}</dd></div>
        <div><dt class="text-slate-500">Source</dt><dd class="font-medium">{{ \App\Models\JournalEntry::sources()[$entry->source] ?? $entry->source }}</dd></div>
        <div>
            <dt class="text-slate-500">Status</dt>
            <dd class="font-medium">
                @if ($entry->status === 'draft')
                    <span class="inline-flex items-center rounded-full bg-amber-50 px-2.5 py-0.5 text-xs font-medium text-amber-700 ring-1 ring-inset ring-amber-600/20">Draft</span>
                @else
                    <span class="inline-flex items-center rounded-full bg-emerald-50 px-2.5 py-0.5 text-xs font-medium text-emerald-700 ring-1 ring-inset ring-emerald-600/20">Posted</span>
                @endif
            </dd>
        </div>
        <div><dt class="text-slate-500">Posted by</dt><dd class="font-medium">{{ $entry->creator?->displayName() ?? '—' }}</dd></div>
        <div class="md:col-span-2"><dt class="text-slate-500">Description</dt><dd class="font-medium">{{ $entry->description }}</dd></div>
        @if ($paymentCustomerBlock)
            <div class="md:col-span-2 rounded-lg border border-slate-100 bg-slate-50/80 p-4">
                <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">{{ $paymentCustomerBlock['title'] }}</p>
                <div class="mt-2 flex flex-wrap items-baseline gap-x-2 gap-y-1">
                    <span class="text-base font-semibold text-slate-900">{{ $paymentCustomerBlock['customer']->name }}</span>
                    @can('view', $paymentCustomerBlock['customer'])
                        <a href="{{ route('customers.show', $paymentCustomerBlock['customer']) }}" class="text-xs font-medium text-indigo-700 underline">Profile</a>
                    @endcan
                </div>
                @if ($paymentCustomerBlock['customer']->customer_code)
                    <p class="mt-1 text-sm text-slate-600">Code: <span class="font-mono font-medium text-slate-800">{{ $paymentCustomerBlock['customer']->customer_code }}</span></p>
                @endif
                @if ($paymentCustomerBlock['customer']->phone)
                    <p class="mt-1 text-sm text-slate-600">Phone: {{ $paymentCustomerBlock['customer']->phone }}</p>
                @endif
                @if ($paymentCustomerBlock['customer']->email)
                    <p class="mt-1 text-sm text-slate-600">Email: {{ $paymentCustomerBlock['customer']->email }}</p>
                @endif
                <div class="mt-3 flex flex-wrap gap-x-4 gap-y-1 border-t border-slate-200/80 pt-3 text-xs text-slate-600">
                    @if (!empty($paymentCustomerBlock['payment_number']))
                        <span>Payment <span class="font-mono font-medium text-slate-800">{{ $paymentCustomerBlock['payment_number'] }}</span></span>
                    @endif
                    @if (!empty($paymentCustomerBlock['payment_date']))
                        <span>Payment date <span class="font-medium text-slate-800">{{ $paymentCustomerBlock['payment_date']->format('Y-m-d') }}</span></span>
                    @endif
                    @if (!empty($paymentCustomerBlock['invoices']))
                        <span class="flex flex-wrap items-center gap-x-1 gap-y-1">
                            <span>{{ count($paymentCustomerBlock['invoices']) > 1 ? 'Invoices' : 'Invoice' }}</span>
                            @foreach ($paymentCustomerBlock['invoices'] as $invRow)
                                @php $invNo = $invRow->invoice_number ?? '#'.$invRow->id; @endphp
                                @if (auth()->user()?->can('view', $invRow))
                                    <a href="{{ route('sales.invoices.show', $invRow) }}" class="font-mono font-medium text-indigo-700 underline">{{ $invNo }}</a>
                                @else
                                    <span class="font-mono font-medium text-slate-800">{{ $invNo }}</span>
                                @endif
                                @if (! $loop->last)
                                    <span class="text-slate-400">,</span>
                                @endif
                            @endforeach
                        </span>
                    @endif
                </div>
            </div>
        @endif
    </dl>
</div>

<div class="journal-entry-report mt-6 overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm">
    <div class="hidden border-b border-slate-200 bg-white px-4 py-4 print:block">
        <p class="font-mono text-sm font-semibold text-slate-900">{{ $entry->document_no }}</p>
        <p class="mt-2 text-xs text-slate-600">
            <span class="font-medium text-slate-800">{{ $entry->entry_date->format('Y-m-d') }}</span>
            <span class="mx-2 text-slate-300">·</span>
            <span>{{ $entry->branch?->name }}</span>
        </p>
        <p class="mt-2 text-sm text-slate-800">{{ $entry->description }}</p>
        @if (!empty($paymentCustomerBlock))
            <p class="mt-3 border-t border-slate-100 pt-2 text-xs text-slate-700">
                <span class="font-semibold text-slate-900">Customer:</span>
                {{ $paymentCustomerBlock['customer']->name }}
                @if ($paymentCustomerBlock['customer']->customer_code)
                    · {{ $paymentCustomerBlock['customer']->customer_code }}
                @endif
                @if ($paymentCustomerBlock['customer']->phone)
                    · {{ $paymentCustomerBlock['customer']->phone }}
                @endif
                @if (!empty($paymentCustomerBlock['payment_number']))
                    · Payment {{ $paymentCustomerBlock['payment_number'] }}
                @endif
                @if (!empty($paymentCustomerBlock['invoices']))
                    · Inv.
                    @foreach ($paymentCustomerBlock['invoices'] as $invRow)
                        {{ $invRow->invoice_number ?? '#'.$invRow->id }}@if (! $loop->last), @endif
                    @endforeach
                @endif
            </p>
        @endif
    </div>
    <table class="w-full border-collapse text-sm">
        <thead>
            <tr class="border-b border-slate-200 bg-slate-100 text-slate-800">
                <th class="px-4 py-3 text-left text-xs font-semibold tracking-wide">Account</th>
                <th class="px-4 py-3 text-left text-xs font-semibold tracking-wide">Memo</th>
                <th class="px-4 py-3 text-right text-xs font-semibold tracking-wide">Debit</th>
                <th class="px-4 py-3 text-right text-xs font-semibold tracking-wide">Credit</th>
            </tr>
        </thead>
        <tbody class="text-slate-900">
            @foreach ($entry->lines as $line)
                @php
                    $dr = (float) $line->debit;
                    $cr = (float) $line->credit;
                @endphp
                <tr class="border-b border-slate-200">
                    <td class="px-4 py-3 align-top">
                        <span class="block font-mono text-xs leading-tight text-slate-600">{{ $line->ledgerAccount?->code }}</span>
                        <span class="mt-1 block text-sm font-bold leading-snug text-slate-900">{{ $line->ledgerAccount?->name }}</span>
                    </td>
                    <td class="px-4 py-3 align-top text-slate-700">{{ $line->line_memo !== null && $line->line_memo !== '' ? $line->line_memo : '' }}</td>
                    <td class="px-4 py-3 text-right align-top font-mono text-sm tabular-nums text-slate-900">{{ $fmtMoney($dr) }}</td>
                    <td class="px-4 py-3 text-right align-top font-mono text-sm tabular-nums text-slate-900">{{ $fmtMoney($cr) }}</td>
                </tr>
            @endforeach
        </tbody>
        <tfoot>
            <tr class="border-t border-slate-200 bg-slate-100 text-slate-900">
                <td class="px-4 py-3 text-sm font-bold">Totals</td>
                <td class="px-4 py-3"></td>
                <td class="px-4 py-3 text-right font-mono text-sm font-bold tabular-nums">{{ number_format($totalDr, 2) }}</td>
                <td class="px-4 py-3 text-right font-mono text-sm font-bold tabular-nums">{{ number_format($totalCr, 2) }}</td>
            </tr>
        </tfoot>
    </table>
</div>
@endsection
