@props(['invoices', 'referrerId' => null, 'compact' => false])
@php
    $saleTypeLabels = ['default' => 'Cash', 'credit' => 'Credit', 'installment' => 'Installment'];
@endphp
<div class="overflow-hidden rounded-xl border border-slate-200 bg-white">
    <table class="w-full text-left text-sm text-slate-600">
        <thead class="border-b border-slate-200 bg-slate-100 text-[11px] uppercase tracking-wide text-slate-500">
            <tr>
                <th class="w-8 px-3 py-2.5">{{ $compact ? '#' : '' }}</th>
                <th class="px-3 py-2.5 font-medium">Invoice / Payment</th>
                <th class="px-3 py-2.5 font-medium">Date</th>
                <th class="px-3 py-2.5 font-medium">{{ $compact ? 'Customer / Method' : 'Customer' }}</th>
                @unless ($compact)
                    <th class="px-3 py-2.5 font-medium">Branch</th>
                    <th class="px-3 py-2.5 font-medium">Type</th>
                @endunless
                <th class="px-3 py-2.5 font-medium">Reference</th>
                <th class="px-3 py-2.5 font-medium">Ref employee</th>
                <th class="px-3 py-2.5 font-medium text-right">Revenue</th>
                <th class="px-3 py-2.5 font-medium text-right">Collected</th>
                <th class="px-3 py-2.5 font-medium text-right">Due</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-slate-100">
            @forelse ($invoices as $invoice)
                @php
                    $invoiceReferrerId = $invoice->referrer_id !== null ? (int) $invoice->referrer_id : null;
                    $viewReferrerId = $referrerId !== null ? (int) $referrerId : null;
                    $paymentReferralOnly = $viewReferrerId !== null && $invoiceReferrerId === null;
                    $paymentCollectionOnly = $viewReferrerId !== null
                        && $invoiceReferrerId !== null
                        && $invoiceReferrerId !== $viewReferrerId;
                    $limitPaymentsToReferrer = $paymentReferralOnly || $paymentCollectionOnly;
                    $paymentRows = $invoice->relationLoaded('payments')
                        ? $invoice->payments->filter(function ($payment) use ($limitPaymentsToReferrer, $viewReferrerId) {
                            $cash = round((float) ($payment->pivot->allocated_amount ?? 0), 2);
                            $discount = round((float) ($payment->pivot->settlement_discount ?? 0), 2);

                            if ($cash <= 0.009 && $discount <= 0.009) {
                                return false;
                            }

                            if ($limitPaymentsToReferrer && $viewReferrerId !== null) {
                                return (int) $payment->referrer_employee_id === $viewReferrerId;
                            }

                            return true;
                        })
                        : collect();
                @endphp
                <tr class="bg-white">
                    <td class="px-3 py-2.5 text-right tabular-nums">{{ $compact ? $loop->iteration : '' }}</td>
                    <td class="px-3 py-2.5">
                        <div class="flex flex-wrap items-center gap-2">
                            <span class="font-mono font-semibold text-indigo-700">{{ $invoice->invoice_number }}</span>
                            @if ($paymentReferralOnly)
                                <span class="rounded-full bg-amber-100 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-amber-800">Payment referral only</span>
                            @elseif ($paymentCollectionOnly)
                                <span class="rounded-full bg-violet-100 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-violet-800">Payment collection</span>
                            @endif
                        </div>
                    </td>
                    <td class="px-3 py-2.5 whitespace-nowrap tabular-nums">{{ $invoice->invoice_date?->toDateString() }}</td>
                    <td class="px-3 py-2.5">
                        <span class="font-medium text-slate-800">{{ $invoice->customer_name }}</span>
                        <span class="text-slate-400"> · {{ $invoice->customer_code }}</span>
                    </td>
                    @unless ($compact)
                        <td class="px-3 py-2.5 text-slate-600">{{ $invoice->branch_name ?? '—' }}</td>
                        <td class="px-3 py-2.5 text-slate-600">{{ $saleTypeLabels[$invoice->sale_type] ?? ucfirst($invoice->sale_type) }}</td>
                    @endunless
                    <td class="px-3 py-2.5 text-slate-400">—</td>
                    <td class="px-3 py-2.5 text-slate-400">—</td>
                    <td class="px-3 py-2.5 text-right tabular-nums font-medium text-slate-800">{{ number_format((float) $invoice->grand_total, 2) }}</td>
                    <td class="px-3 py-2.5 text-right tabular-nums font-medium text-emerald-700">{{ number_format((float) $invoice->amount_paid, 2) }}</td>
                    <td class="px-3 py-2.5 text-right tabular-nums font-medium text-amber-700">{{ number_format((float) $invoice->amount_due, 2) }}</td>
                </tr>
                @foreach ($paymentRows as $payment)
                    @php
                        $allocated = round((float) ($payment->pivot->allocated_amount ?? 0), 2);
                        $discount = round((float) ($payment->pivot->settlement_discount ?? 0), 2);
                        $reference = trim((string) ($payment->reference_no ?? ''));
                        $paymentReferrerLabel = $payment->referrerEmployee?->displayLabel();
                        $paymentReferrerDiffers = $payment->referrer_employee_id !== null
                            && (int) $payment->referrer_employee_id !== (int) ($invoice->referrer_id ?? 0);
                    @endphp
                    @if ($allocated > 0.009)
                        <tr class="bg-indigo-50/40 text-xs">
                            <td class="px-3 py-2 text-center text-indigo-300">↳</td>
                            <td class="px-3 py-2 text-slate-600">
                                {{ $payment->paymentMethodRef?->name ?? ucfirst(str_replace('_', ' ', (string) $payment->payment_method)) }}
                            </td>
                            <td class="px-3 py-2 whitespace-nowrap tabular-nums text-slate-600">{{ $payment->payment_date?->toDateString() }}</td>
                            <td class="px-3 py-2 text-slate-500">Collection</td>
                            @unless ($compact)
                                <td class="px-3 py-2"></td>
                                <td class="px-3 py-2"></td>
                            @endunless
                            <td class="px-3 py-2 font-medium text-slate-700">{{ $reference !== '' ? $reference : '—' }}</td>
                            <td class="px-3 py-2">
                                @if ($paymentReferrerLabel)
                                    <span @class([
                                        'inline-flex rounded-full px-2 py-0.5 text-[10px] font-medium',
                                        'bg-indigo-100 text-indigo-800' => ! $paymentReferrerDiffers,
                                        'bg-violet-100 text-violet-800' => $paymentReferrerDiffers,
                                    ])>{{ $paymentReferrerLabel }}</span>
                                @else
                                    <span class="text-slate-400">—</span>
                                @endif
                            </td>
                            <td class="px-3 py-2"></td>
                            <td class="px-3 py-2 text-right tabular-nums font-semibold text-emerald-700">{{ number_format($allocated, 2) }}</td>
                            <td class="px-3 py-2"></td>
                        </tr>
                    @endif
                    @if ($discount > 0.009)
                        <tr class="bg-indigo-50/40 text-xs">
                            <td class="px-3 py-2 text-center text-indigo-300">↳</td>
                            <td class="px-3 py-2 font-medium text-indigo-900">Settlement discount</td>
                            <td class="px-3 py-2 whitespace-nowrap tabular-nums text-slate-600">{{ $payment->payment_date?->toDateString() }}</td>
                            <td class="px-3 py-2 text-slate-500">Discount</td>
                            @unless ($compact)
                                <td class="px-3 py-2"></td>
                                <td class="px-3 py-2"></td>
                            @endunless
                            <td class="px-3 py-2 font-medium text-slate-700">{{ $reference !== '' ? $reference : '—' }}</td>
                            <td class="px-3 py-2">
                                @if ($paymentReferrerLabel)
                                    <span @class([
                                        'inline-flex rounded-full px-2 py-0.5 text-[10px] font-medium',
                                        'bg-indigo-100 text-indigo-800' => ! $paymentReferrerDiffers,
                                        'bg-violet-100 text-violet-800' => $paymentReferrerDiffers,
                                    ])>{{ $paymentReferrerLabel }}</span>
                                @else
                                    <span class="text-slate-400">—</span>
                                @endif
                            </td>
                            <td class="px-3 py-2"></td>
                            <td class="px-3 py-2 text-right tabular-nums font-semibold text-indigo-900">{{ number_format($discount, 2) }}</td>
                            <td class="px-3 py-2"></td>
                        </tr>
                    @endif
                @endforeach
            @empty
                <tr>
                    <td colspan="{{ $compact ? 9 : 11 }}" class="px-4 py-6 text-center text-sm text-slate-500">No invoices for this referrer.</td>
                </tr>
            @endforelse
        </tbody>
    </table>
</div>
