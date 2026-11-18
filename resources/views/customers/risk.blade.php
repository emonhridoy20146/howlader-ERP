@extends('layouts.app')

@section('title', 'Profile & Risk')
@section('page-title', 'Customer Profile & Risk')

@php
    $s = $report['summary'];
    $rec = $report['recommendation'];
    $trend = $report['trend'];
    $grade = $report['grade'];
    $gradeClass = fn ($g) => match ($g) {
        'A', 'B' => 'bg-emerald-100 text-emerald-800',
        'C' => 'bg-amber-100 text-amber-800',
        'D', 'E' => 'bg-rose-100 text-rose-800',
        default => 'bg-slate-100 text-slate-600',
    };
    $statusClass = match ($report['status']) {
        'defaulted' => 'bg-rose-100 text-rose-800',
        'overdue' => 'bg-amber-100 text-amber-800',
        'active' => 'bg-blue-100 text-blue-800',
        default => 'bg-slate-100 text-slate-600',
    };
    $bandClass = fn ($row) => ! $row['is_late'] ? ($row['remaining'] > 0.009 && $row['matured'] ? 'text-amber-700' : 'text-emerald-700') : ($row['days_late'] > 30 ? 'text-rose-700 font-semibold' : 'text-amber-700 font-semibold');
    $money = fn ($v) => number_format((float) $v, 2);
    $phones = $customer->normalizedPhoneNumbers();
@endphp

@section('content')
@vite('resources/js/customer-risk.js')
<div class="space-y-5">
    <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div class="flex items-center gap-4">
                @if ($customer->photoUrl())
                    <img src="{{ $customer->photoUrl() }}" alt="{{ $customer->name }}" class="h-16 w-16 rounded-full object-cover ring-1 ring-slate-200">
                @endif
                <div>
                    <h2 class="text-xl font-semibold text-slate-900">{{ $customer->name }}</h2>
                    <p class="text-sm text-slate-500">{{ $customer->customer_code }} · {{ $customer->branch?->name ?? 'No branch' }}</p>
                    <div class="mt-2 flex flex-wrap items-center gap-2 text-xs">
                        <span class="rounded-full px-2.5 py-1 font-semibold {{ $statusClass }}">{{ ucfirst($report['status']) }}</span>
                        <span class="rounded-full px-2.5 py-1 font-semibold {{ $gradeClass($grade) }}">Grade {{ $grade }}@if ($report['score']['total'] !== null) · {{ $report['score']['total'] }}/100 @endif</span>
                        <span class="rounded-full bg-slate-100 px-2.5 py-1 font-semibold text-slate-700">Risk: {{ $report['risk_level'] }}</span>
                    </div>
                </div>
            </div>
            <div class="flex flex-wrap gap-2 print:hidden">
                <a href="{{ route('customers.show', $customer) }}" class="rounded-lg border border-slate-300 px-3 py-1.5 text-sm text-slate-700 hover:bg-slate-100">Back</a>
                <a href="{{ route('customers.risk.print', $customer) }}" target="_blank" class="rounded-lg border border-slate-300 bg-slate-800 px-3 py-1.5 text-sm font-medium text-white hover:bg-slate-700">Print</a>
                <a href="{{ route('customers.risk.pdf', $customer) }}" class="rounded-lg border border-rose-200 bg-rose-50 px-3 py-1.5 text-sm font-medium text-rose-700 hover:bg-rose-100">Download PDF</a>
                <a href="{{ route('customers.risk.excel', $customer) }}" class="rounded-lg border border-emerald-200 bg-emerald-50 px-3 py-1.5 text-sm font-medium text-emerald-700 hover:bg-emerald-100">Excel</a>
            </div>
        </div>
    </div>

    <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
        <h3 class="mb-3 text-sm font-semibold uppercase tracking-wide text-slate-500">Personal information</h3>
        <dl class="grid gap-3 text-sm sm:grid-cols-2 lg:grid-cols-4">
            <div><dt class="text-slate-500">Phones</dt><dd class="font-medium">{{ $phones ? implode(', ', $phones) : '—' }}</dd></div>
            <div><dt class="text-slate-500">Email</dt><dd class="font-medium">{{ $customer->email ?: '—' }}</dd></div>
            <div><dt class="text-slate-500">Address</dt><dd class="font-medium">{{ $customer->address ?: '—' }}</dd></div>
            <div><dt class="text-slate-500">Detailed address</dt><dd class="font-medium">{{ $customer->detailed_address ?: '—' }}</dd></div>
            <div><dt class="text-slate-500">ID</dt><dd class="font-medium">{{ trim($customer->id_type.' '.$customer->id_number) ?: '—' }}</dd></div>
            <div><dt class="text-slate-500">NID image</dt><dd class="font-medium">@if ($customer->nidUrl())<a class="text-blue-700 underline" href="{{ $customer->nidUrl() }}" target="_blank">View</a>@else — @endif</dd></div>
            <div><dt class="text-slate-500">Map</dt><dd class="font-medium">@if ($customer->googleMapsUrl())<a class="text-blue-700 underline" href="{{ $customer->googleMapsUrl() }}" target="_blank">Open map</a>@else — @endif</dd></div>
            <div><dt class="text-slate-500">Attachments</dt>
                <dd class="font-medium">
                    @forelse ((array) $customer->attachments as $att)
                        @php $path = is_array($att) ? ($att['path'] ?? null) : $att; @endphp
                        @if ($path)<a class="mr-2 text-blue-700 underline" href="{{ \Illuminate\Support\Facades\Storage::disk('public')->url($path) }}" target="_blank">File {{ $loop->iteration }}</a>@endif
                    @empty — @endforelse
                </dd></div>
            <div><dt class="text-slate-500">Credit limit</dt><dd class="font-medium">{{ $money($customer->credit_limit) }}</dd></div>
            <div><dt class="text-slate-500">Account</dt><dd class="font-medium">{{ $customer->is_active ? 'Active' : 'Inactive' }}</dd></div>
        </dl>
    </div>

    <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
        <h3 class="mb-3 text-sm font-semibold uppercase tracking-wide text-slate-500">Guarantors</h3>
        @forelse ($report['guarantors'] as $g)
            <div class="mb-2 grid gap-2 rounded-xl bg-slate-50 p-3 text-sm sm:grid-cols-2 lg:grid-cols-5">
                <div><span class="text-slate-500">Name</span><br>
                    @if ($g['customer_id'])<a class="font-medium text-blue-700 underline" href="{{ route('customers.show', $g['customer_id']) }}">{{ $g['name'] }}</a>@else<span class="font-medium">{{ $g['name'] }}</span>@endif
                    <span class="text-xs text-slate-500">({{ $g['relation'] }})</span></div>
                <div><span class="text-slate-500">Phone</span><br><span class="font-medium">{{ $g['phones'] ? implode(', ', $g['phones']) : '—' }}</span></div>
                <div><span class="text-slate-500">NID</span><br><span class="font-medium">{{ $g['nid_number'] ?: '—' }}</span> @if ($g['nid_url'])<a class="text-blue-700 underline" href="{{ $g['nid_url'] }}" target="_blank">image</a>@endif</div>
                <div><span class="text-slate-500">Address</span><br><span class="font-medium">{{ $g['address'] ?: '—' }}</span></div>
                <div><span class="text-slate-500">Own grade</span><br>
                    @if ($g['grade'])<span class="rounded-full px-2 py-0.5 text-xs font-semibold {{ $gradeClass($g['grade']) }}">{{ $g['grade'] }}</span>@else<span class="font-medium">—</span>@endif
                    @if ($g['invoices'])<span class="block text-xs text-slate-500">Invoices: {{ implode(', ', $g['invoices']) }}</span>@endif</div>
            </div>
        @empty
            <p class="text-sm text-slate-500">No guarantors on record.</p>
        @endforelse
    </div>

    <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
        @foreach ([
            ['Total purchased', $money($s['total_purchased'])],
            ['Total paid', $money($s['total_paid'])],
            ['Installment outstanding', $money($s['outstanding'])],
            ['Overdue', $money($s['overdue_amount']).' ('.$s['overdue_count'].')'],
            ['On-time rate', $s['on_time_rate'] === null ? 'N/A' : number_format($s['on_time_rate'] * 100, 1).'%'],
            ['Avg / max days late', $s['avg_days_late'].' / '.$s['max_days_late']],
            ['Lates 3m / 6m / 12m', $s['late_3m'].' / '.$s['late_6m'].' / '.$s['late_12m']],
            ['Streak longest / current', $s['longest_streak'].' / '.$s['current_streak']],
        ] as [$label, $value])
            <div class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
                <p class="text-xs text-slate-500">{{ $label }}</p>
                <p class="mt-1 text-lg font-semibold text-slate-900">{{ $value }}</p>
            </div>
        @endforeach
    </div>
    <p class="text-xs text-slate-500">Reschedules: {{ $s['reschedule_count'] }} · Defaulted / written-off plans: {{ $s['defaulted_plans'] }} · Last payment: {{ $s['last_payment_date']?->format('Y-m-d') ?? '—' }} · Customer outstanding balance on file: {{ $money($s['customer_outstanding_balance']) }}</p>

    <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
        <h3 class="mb-3 text-sm font-semibold uppercase tracking-wide text-slate-500">Risk analysis</h3>
        <div class="grid gap-5 lg:grid-cols-2">
            <div>
                <p class="text-sm">Late-payment pattern:
                    <span class="rounded-full px-2.5 py-0.5 text-xs font-semibold {{ $trend['classification'] === 'trend' ? 'bg-rose-100 text-rose-800' : ($trend['classification'] === 'isolated' ? 'bg-amber-100 text-amber-800' : 'bg-emerald-100 text-emerald-800') }}">
                        {{ $trend['classification'] === 'none' ? 'None' : ucfirst($trend['classification']) }}</span>
                    @if ($trend['improving'])<span class="rounded-full bg-emerald-100 px-2.5 py-0.5 text-xs font-semibold text-emerald-800">Improving</span>@endif
                </p>
                <p class="mt-2 text-sm text-slate-700">{{ $trend['reason'] }}</p>
                @if ($report['score']['total'] !== null)
                    <table class="mt-4 w-full text-sm">
                        <thead><tr class="border-b text-left text-xs text-slate-500"><th class="py-1">Factor</th><th>Value</th><th class="text-right">Weight</th><th class="text-right">Points</th></tr></thead>
                        <tbody>
                            @foreach ($report['score']['factors'] as $f)
                                <tr class="border-b border-slate-100"><td class="py-1">{{ $f['label'] }}</td><td>{{ $f['value'] }}</td><td class="text-right">{{ $f['weight'] }}</td><td class="text-right">{{ $f['points'] }}</td></tr>
                            @endforeach
                            <tr class="font-semibold"><td class="py-1" colspan="3">Total</td><td class="text-right">{{ $report['score']['total'] }}</td></tr>
                        </tbody>
                    </table>
                    <p class="mt-2 text-xs text-slate-500">{{ $report['score']['formula'] }}. Trend caps the grade at {{ $report['config']['trend_max_grade'] }}; any default/write-off forces grade E.</p>
                @else
                    <p class="mt-4 text-sm text-slate-500">New customer — no matured installment history, so no score yet.</p>
                @endif
            </div>
            <div class="rounded-xl bg-slate-50 p-4 text-sm">
                <h4 class="font-semibold text-slate-800">Suggested future installment</h4>
                <p class="mt-1 font-medium {{ $rec['eligible'] ? 'text-emerald-700' : 'text-rose-700' }}">{{ $rec['text'] }}</p>
                @if ($rec['max_amount'] !== null)
                    <dl class="mt-3 grid grid-cols-3 gap-2">
                        <div><dt class="text-xs text-slate-500">Max amount</dt><dd class="font-semibold">{{ $money($rec['max_amount']) }}</dd></div>
                        <div><dt class="text-xs text-slate-500">Max tenure</dt><dd class="font-semibold">{{ $rec['max_tenure_months'] }} mo</dd></div>
                        <div><dt class="text-xs text-slate-500">Min down payment</dt><dd class="font-semibold">{{ $rec['min_down_payment_pct'] }}%</dd></div>
                    </dl>
                    <p class="mt-2 text-xs text-slate-500">Based on average financed amount of {{ $money($rec['base_amount']) }}.</p>
                @endif
            </div>
        </div>
    </div>

    <script type="application/json" id="customer-risk-data">@json($chartData)</script>
    <div class="grid gap-4 lg:grid-cols-2">
        <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm"><h4 class="mb-2 text-sm font-semibold text-slate-700">On-time vs late per month</h4><div class="h-64"><canvas id="chart-ontime-late"></canvas></div></div>
        <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm"><h4 class="mb-2 text-sm font-semibold text-slate-700">Due vs paid per month</h4><div class="h-64"><canvas id="chart-due-paid"></canvas></div></div>
        <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm lg:col-span-2"><h4 class="mb-2 text-sm font-semibold text-slate-700">Days late per installment (with trend line)</h4><div class="h-64"><canvas id="chart-days-late"></canvas></div></div>
    </div>

    <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
        <h3 class="mb-3 text-sm font-semibold uppercase tracking-wide text-slate-500">Sales & invoices</h3>
        <div class="overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead><tr class="border-b text-left text-xs text-slate-500"><th class="py-1 pr-3">Invoice</th><th class="pr-3">Date</th><th class="pr-3">Branch</th><th class="pr-3">Type</th><th class="pr-3">Status</th><th class="pr-3 text-right">Total</th><th class="pr-3 text-right">Paid</th><th class="text-right">Due</th></tr></thead>
                <tbody>
                    @forelse ($report['invoices'] as $inv)
                        <tr class="border-b border-slate-100">
                            <td class="py-1 pr-3"><a class="text-blue-700 underline" href="{{ route('sales.invoices.show', $inv) }}">{{ $inv->invoice_number }}</a></td>
                            <td class="pr-3">{{ $inv->invoice_date?->format('Y-m-d') }}</td>
                            <td class="pr-3">{{ $inv->branch?->name }}</td>
                            <td class="pr-3">{{ ucfirst($inv->sale_type) }}</td>
                            <td class="pr-3">{{ ucfirst(str_replace('_', ' ', $inv->status)) }}</td>
                            <td class="pr-3 text-right">{{ $money($inv->grand_total) }}</td>
                            <td class="pr-3 text-right">{{ $money($inv->amount_paid) }}</td>
                            <td class="text-right">{{ $money($inv->amount_due) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="8" class="py-3 text-slate-500">No invoices.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    @foreach ($report['plans'] as $plan)
        <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
            <h3 class="mb-3 text-sm font-semibold text-slate-700">
                Installment plan — {{ $plan['invoice_number'] }}
                <span class="ml-2 rounded-full bg-slate-100 px-2 py-0.5 text-xs">{{ ucfirst($plan['status']) }}</span>
                @if ($plan['written_off'])<span class="ml-1 rounded-full bg-rose-100 px-2 py-0.5 text-xs text-rose-800">Written off</span>@endif
                <span class="ml-2 text-xs font-normal text-slate-500">Financed {{ $money($plan['financed_amount']) }} · reschedules {{ $plan['reschedule_count'] }}</span>
            </h3>
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead><tr class="border-b text-left text-xs text-slate-500"><th class="py-1 pr-3">#</th><th class="pr-3">Due date</th><th class="pr-3 text-right">Due</th><th class="pr-3 text-right">Late fee</th><th class="pr-3 text-right">Paid</th><th class="pr-3">Paid date</th><th class="pr-3 text-right">Days late</th><th class="pr-3">Band</th><th>Status</th></tr></thead>
                    <tbody>
                        @foreach ($plan['installments'] as $row)
                            <tr class="border-b border-slate-100 break-inside-avoid">
                                <td class="py-1 pr-3">{{ $row['installment_no'] }}</td>
                                <td class="pr-3">{{ $row['due_date']?->format('Y-m-d') }}</td>
                                <td class="pr-3 text-right">{{ $money($row['due_amount']) }}</td>
                                <td class="pr-3 text-right">{{ $money($row['late_fee']) }}</td>
                                <td class="pr-3 text-right">{{ $money($row['paid_amount']) }}</td>
                                <td class="pr-3">{{ $row['paid_date']?->format('Y-m-d') ?? '—' }}</td>
                                <td class="pr-3 text-right {{ $bandClass($row) }}">{{ $row['days_late'] }}{{ $row['paid_date'] === null && $row['days_late'] > 0 ? '+' : '' }}</td>
                                <td class="pr-3 {{ $bandClass($row) }}">{{ $row['band'] }}</td>
                                <td>{{ ucfirst(str_replace('_', ' ', $row['status'])) }}</td>
                            </tr>
                            @foreach ($row['payments'] as $p)
                                <tr class="break-inside-avoid text-xs text-slate-500">
                                    <td></td><td colspan="8" class="pb-1">↳ {{ $p['date']?->format('Y-m-d') }} · {{ $money($p['amount']) }} · {{ $p['method'] }} · {{ $p['payment_number'] }}</td>
                                </tr>
                            @endforeach
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endforeach
</div>
<style>
    @media print {
        nav, aside, header, .print\:hidden { display: none !important; }
        tr { break-inside: avoid; }
        canvas { max-width: 100% !important; }
    }
</style>
@endsection
