<table>
    <thead>
        <tr>
            <th colspan="15">{{ $companyPad['company_name'] ?? 'Company Name' }}</th>
        </tr>
        <tr>
            <th colspan="15">{{ $reportTitle }} ({{ $subtitle }})</th>
        </tr>
        <tr></tr>
        <tr>
            <th>Referrer</th>
            <th>Invoices Generated</th>
            <th>Total Revenue</th>
            <th>Amount Collected</th>
            <th>Amount Due</th>
            <th>Invoice #</th>
            <th>Invoice Date</th>
            <th>Customer</th>
            <th>Revenue</th>
            <th>Collected</th>
            <th>Due</th>
            <th>Payment Date</th>
            <th>Method</th>
            <th>Reference</th>
            <th>Ref Employee</th>
        </tr>
    </thead>
    <tbody>
        @foreach($rows as $row)
            @php
                $refKey = $row->representative_id === null ? 'none' : (string) $row->representative_id;
                $invoices = ($invoiceDetailsByReferrer ?? collect())->get($refKey, collect());
                $referrerLabel = $row->representative_id
                    ? \App\Models\Employee::labelFromParts($row->representative_code, $row->representative_first_name, $row->representative_last_name)
                    : 'No referrer';
                $detailRowCount = $invoices->sum(function ($invoice) {
                    if (! $invoice->relationLoaded('payments')) {
                        return 1;
                    }

                    $paymentRows = $invoice->payments->filter(function ($payment) {
                        $cash = round((float) ($payment->pivot->allocated_amount ?? 0), 2);
                        $discount = round((float) ($payment->pivot->settlement_discount ?? 0), 2);

                        return $cash > 0.009 || $discount > 0.009;
                    })->count();

                    return 1 + $paymentRows;
                });
            @endphp
            @if ($invoices->isEmpty())
                <tr>
                    <td>{{ $referrerLabel }}</td>
                    <td>{{ $row->total_invoices }}</td>
                    <td>{{ $row->total_revenue }}</td>
                    <td>{{ $row->total_collected }}</td>
                    <td>{{ $row->total_due }}</td>
                    <td></td>
                    <td></td>
                    <td></td>
                    <td></td>
                    <td></td>
                    <td></td>
                    <td></td>
                    <td></td>
                    <td></td>
                    <td></td>
                </tr>
            @else
                @php $detailIndex = 0; @endphp
                @foreach ($invoices as $invoice)
                    <tr>
                        @if ($detailIndex === 0)
                            <td rowspan="{{ max(1, $detailRowCount) }}">{{ $referrerLabel }}</td>
                            <td rowspan="{{ max(1, $detailRowCount) }}">{{ $row->total_invoices }}</td>
                            <td rowspan="{{ max(1, $detailRowCount) }}">{{ $row->total_revenue }}</td>
                            <td rowspan="{{ max(1, $detailRowCount) }}">{{ $row->total_collected }}</td>
                            <td rowspan="{{ max(1, $detailRowCount) }}">{{ $row->total_due }}</td>
                        @endif
                        <td>{{ $invoice->invoice_number }}</td>
                        <td>{{ $invoice->invoice_date?->toDateString() }}</td>
                        <td>{{ $invoice->customer_name }} ({{ $invoice->customer_code }})</td>
                        <td>{{ $invoice->grand_total }}</td>
                        <td>{{ $invoice->amount_paid }}</td>
                        <td>{{ $invoice->amount_due }}</td>
                        <td></td>
                        <td></td>
                        <td></td>
                        <td></td>
                    </tr>
                    @php $detailIndex++; @endphp
                    @if ($invoice->relationLoaded('payments'))
                        @foreach ($invoice->payments as $payment)
                            @php
                                $allocated = round((float) ($payment->pivot->allocated_amount ?? 0), 2);
                                $discount = round((float) ($payment->pivot->settlement_discount ?? 0), 2);
                                $reference = trim((string) ($payment->reference_no ?? ''));
                            @endphp
                            @if ($allocated > 0.009)
                                <tr>
                                    <td></td>
                                    <td></td>
                                    <td></td>
                                    <td></td>
                                    <td></td>
                                    <td></td>
                                    <td></td>
                                    <td>{{ $payment->payment_date?->toDateString() }}</td>
                                    <td>{{ $payment->paymentMethodRef?->name ?? ucfirst(str_replace('_', ' ', (string) $payment->payment_method)) }}</td>
                                    <td>{{ $reference !== '' ? $reference : '' }}</td>
                                    <td>{{ $payment->referrerEmployee?->displayLabel() ?? '' }}</td>
                                </tr>
                                @php $detailIndex++; @endphp
                            @endif
                            @if ($discount > 0.009)
                                <tr>
                                    <td></td>
                                    <td></td>
                                    <td></td>
                                    <td></td>
                                    <td></td>
                                    <td></td>
                                    <td></td>
                                    <td>{{ $payment->payment_date?->toDateString() }}</td>
                                    <td>Settlement discount</td>
                                    <td>{{ $reference !== '' ? $reference : '' }}</td>
                                    <td>{{ $payment->referrerEmployee?->displayLabel() ?? '' }}</td>
                                </tr>
                                @php $detailIndex++; @endphp
                            @endif
                        @endforeach
                    @endif
                @endforeach
            @endif
        @endforeach
    </tbody>
</table>
