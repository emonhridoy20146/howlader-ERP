@extends('layouts.app')

@section('title', 'Dashboard')
@section('page-title', 'Dashboard')

@section('content')
    <div class="mb-6 rounded-2xl border border-slate-200 bg-gradient-to-br from-slate-800 to-slate-900 p-5 text-white shadow-sm ring-1 ring-slate-900/10 sm:p-6">
        <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
            <div class="min-w-0">
                <p class="text-xs font-medium uppercase tracking-[0.2em] text-slate-400">Location scope</p>
                @if ($contextLocation)
                    <h2 class="mt-2 truncate text-xl font-semibold tracking-tight sm:text-2xl">
                        {{ $contextLocation->name }}
                    </h2>
                    <p class="mt-1 text-sm text-slate-300">Figures below are filtered to this branch. Switch location from the header to compare or view company-wide totals.</p>
                @else
                    <h2 class="mt-2 text-xl font-semibold tracking-tight sm:text-2xl">All locations</h2>
                    <p class="mt-1 text-sm text-slate-300">Aggregated view across every active branch. Choose a location in the header to focus dashboards and lists.</p>
                @endif
            </div>
            <div class="flex shrink-0 flex-wrap gap-2">
                <a href="{{ route('sales.invoices.create') }}" class="rounded-lg bg-white/10 px-3 py-2 text-sm font-medium text-white ring-1 ring-white/20 hover:bg-white/15">New sale</a>
                <a href="{{ route('goods-receipts.create') }}" class="rounded-lg bg-white/10 px-3 py-2 text-sm font-medium text-white ring-1 ring-white/20 hover:bg-white/15">Receive stock</a>
            </div>
        </div>
    </div>

    <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
        <div class="rounded-2xl bg-white p-4 shadow-sm ring-1 ring-slate-200">
            <p class="text-xs uppercase tracking-[0.2em] text-slate-500">Today sales</p>
            <p class="mt-2 text-2xl font-semibold tabular-nums">{{ number_format($stats['today_sales'], 2) }}</p>
            <p class="mt-1 text-xs text-slate-500">Posted &amp; active sales today (includes installment)</p>
        </div>
        <div class="rounded-2xl bg-white p-4 shadow-sm ring-1 ring-slate-200">
            <p class="text-xs uppercase tracking-[0.2em] text-slate-500">Month to date</p>
            <p class="mt-2 text-2xl font-semibold tabular-nums">{{ number_format($stats['month_sales'], 2) }}</p>
            <p class="mt-1 text-xs text-slate-500">Posted &amp; active sales this month (includes installment)</p>
        </div>
        <div class="rounded-2xl bg-white p-4 shadow-sm ring-1 ring-slate-200">
            <p class="text-xs uppercase tracking-[0.2em] text-slate-500">Outstanding receivable</p>
            <p class="mt-2 text-2xl font-semibold tabular-nums">{{ number_format($stats['outstanding_receivable'], 2) }}</p>
            <p class="mt-1 text-xs text-slate-500">Posted / active, not fully paid</p>
        </div>
        <div class="rounded-2xl bg-white p-4 shadow-sm ring-1 ring-slate-200">
            <p class="text-xs uppercase tracking-[0.2em] text-slate-500">Draft invoices</p>
            <p class="mt-2 text-2xl font-semibold tabular-nums">{{ $stats['draft_invoices'] }}</p>
            <p class="mt-1 text-xs text-slate-500">Awaiting completion or posting</p>
        </div>
    </div>

    <div class="mt-3 grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
        <div class="rounded-2xl bg-white p-4 shadow-sm ring-1 ring-slate-200">
            <p class="text-xs uppercase tracking-[0.2em] text-slate-500">Month collection — total due</p>
            <p class="mt-2 text-2xl font-semibold tabular-nums text-rose-700">{{ number_format($stats['collection_total_due'], 2) }}</p>
            <p class="mt-1 text-xs text-slate-500">Installments + credits + overdue (this month report)</p>
        </div>
        <div class="rounded-2xl bg-white p-4 shadow-sm ring-1 ring-slate-200">
            <p class="text-xs uppercase tracking-[0.2em] text-slate-500">Installments this month</p>
            <p class="mt-2 text-2xl font-semibold tabular-nums text-sky-800">{{ number_format($stats['collection_installment_due'], 2) }}</p>
            <p class="mt-1 text-xs text-slate-500">Schedules due {{ now()->format('F Y') }}</p>
        </div>
        <div class="rounded-2xl bg-white p-4 shadow-sm ring-1 ring-slate-200">
            <p class="text-xs uppercase tracking-[0.2em] text-slate-500">Credit invoices this month</p>
            <p class="mt-2 text-2xl font-semibold tabular-nums text-indigo-800">{{ number_format($stats['collection_credit_due'], 2) }}</p>
            <p class="mt-1 text-xs text-slate-500">Amount due on credit terms</p>
        </div>
        <div class="rounded-2xl bg-white p-4 shadow-sm ring-1 ring-slate-200">
            <p class="text-xs uppercase tracking-[0.2em] text-slate-500">Overdue installments</p>
            <p class="mt-2 text-2xl font-semibold tabular-nums text-amber-800">{{ number_format($stats['collection_overdue_installment'], 2) }}</p>
            <p class="mt-1 text-xs text-slate-500">Past-due schedule balances</p>
        </div>
    </div>

    <div class="mt-3 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
        <a href="{{ route('installments.index') }}" class="rounded-2xl bg-white p-4 shadow-sm ring-1 ring-slate-200 transition hover:ring-slate-300">
            <p class="text-xs uppercase tracking-[0.2em] text-slate-500">Overdue installments</p>
            <p class="mt-2 text-2xl font-semibold text-rose-700 tabular-nums">{{ $stats['overdue_installments'] }}</p>
            <p class="mt-1 text-xs font-medium text-slate-600">Schedules marked overdue →</p>
        </a>
        <a href="{{ route('installments.index') }}" class="rounded-2xl bg-white p-4 shadow-sm ring-1 ring-slate-200 transition hover:ring-slate-300">
            <p class="text-xs uppercase tracking-[0.2em] text-slate-500">Dues next 14 days</p>
            <p class="mt-2 text-2xl font-semibold text-amber-700 tabular-nums">{{ $stats['upcoming_dues'] }}</p>
            <p class="mt-1 text-xs font-medium text-slate-600">Pending / partial through {{ $upcomingWindowEnd->format('M j') }} →</p>
        </a>
        <a href="{{ route('reports.stock-by-location') }}" class="rounded-2xl bg-white p-4 shadow-sm ring-1 ring-slate-200 transition hover:ring-slate-300">
            <p class="text-xs uppercase tracking-[0.2em] text-slate-500">Low stock positions</p>
            <p class="mt-2 text-2xl font-semibold text-orange-700 tabular-nums">{{ $stats['low_stock_count'] }}</p>
            <p class="mt-1 text-xs font-medium text-slate-600">Available ≤ reorder level →</p>
        </a>
        <a href="{{ route('transfers.index') }}" class="rounded-2xl bg-white p-4 shadow-sm ring-1 ring-slate-200 transition hover:ring-slate-300">
            <p class="text-xs uppercase tracking-[0.2em] text-slate-500">In-flight transfers</p>
            <p class="mt-2 text-2xl font-semibold text-sky-800 tabular-nums">{{ $stats['pending_transfers'] }}</p>
            <p class="mt-1 text-xs font-medium text-slate-600">Draft, approved, or issued →</p>
        </a>
    </div>

    <div class="mt-3 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
        <a href="{{ route('inventory.stock-levels.index') }}" class="rounded-2xl bg-white p-4 shadow-sm ring-1 ring-slate-200 transition hover:ring-emerald-200">
            <p class="text-xs uppercase tracking-[0.2em] text-slate-500">In stock</p>
            <p class="mt-2 text-2xl font-semibold tabular-nums text-emerald-800">{{ number_format($stats['product_in_stock']) }}</p>
            <p class="mt-1 text-xs font-medium text-slate-600">Available units{{ $contextLocation ? ' · '.$contextLocation->name : '' }} →</p>
        </a>
        <a href="{{ route('reports.stock-summary') }}" class="rounded-2xl bg-white p-4 shadow-sm ring-1 ring-slate-200 transition hover:ring-amber-200">
            <p class="text-xs uppercase tracking-[0.2em] text-slate-500">On hold</p>
            <p class="mt-2 text-2xl font-semibold tabular-nums text-amber-800">{{ number_format($stats['product_on_hold']) }}</p>
            <p class="mt-1 text-xs font-medium text-slate-600">Reserved units{{ $contextLocation ? ' · '.$contextLocation->name : '' }} →</p>
        </a>
        <div class="rounded-2xl bg-white p-4 shadow-sm ring-1 ring-slate-200">
            <p class="text-xs uppercase tracking-[0.2em] text-slate-500">Open purchase orders</p>
            <p class="mt-2 text-2xl font-semibold tabular-nums">{{ $stats['open_purchase_orders'] }}</p>
            <p class="mt-1 text-xs text-slate-500">Draft, approved, or partially received</p>
            <a href="{{ route('purchase-orders.index') }}" class="mt-3 inline-block text-sm font-medium text-slate-800 underline decoration-slate-300 underline-offset-2 hover:decoration-slate-600">View purchase orders</a>
        </div>
        @can('view-reports')
        <div class="rounded-2xl border border-dashed border-slate-200 bg-slate-50/80 p-4 sm:flex sm:flex-col sm:justify-center">
            <p class="text-sm font-medium text-slate-800">Reports</p>
            <p class="mt-1 text-xs text-slate-600">Jump to detailed breakdowns, open print-friendly reports, or download Excel from the report hub.</p>
            <div class="mt-3 flex flex-wrap gap-2">
                <a href="{{ route('reports.index') }}" class="rounded-lg border border-slate-800 bg-slate-900 px-3 py-1.5 text-xs font-medium text-white hover:bg-slate-800">Report downloads</a>
                <a href="{{ route('reports.sales-summary') }}" class="rounded-lg border border-slate-200 bg-white px-3 py-1.5 text-xs font-medium text-slate-800 hover:bg-slate-50">Sales summary</a>
                <a href="{{ route('reports.installment-overdue') }}" class="rounded-lg border border-slate-200 bg-white px-3 py-1.5 text-xs font-medium text-slate-800 hover:bg-slate-50">Installment overdue</a>
                <a href="{{ route('reports.stock-by-location') }}" class="rounded-lg border border-slate-200 bg-white px-3 py-1.5 text-xs font-medium text-slate-800 hover:bg-slate-50">Stock by location</a>
                <a href="{{ route('reports.low-stock') }}" class="rounded-lg border border-slate-200 bg-white px-3 py-1.5 text-xs font-medium text-slate-800 hover:bg-slate-50">Low stock</a>
                <a href="{{ route('reports.customer-dues') }}" class="rounded-lg border border-slate-200 bg-white px-3 py-1.5 text-xs font-medium text-slate-800 hover:bg-slate-50">Customer dues</a>
                <a href="{{ route('reports.open-purchase-orders') }}" class="rounded-lg border border-slate-200 bg-white px-3 py-1.5 text-xs font-medium text-slate-800 hover:bg-slate-50">Open POs</a>
            </div>
        </div>
        @endcan
    </div>

    @can('view-dashboard-attendance')
        @if ($attendanceToday)
            @php
                $attendanceCounts = $attendanceToday['counts'];
                $attendanceRows = $attendanceToday['rows'];
                $attendanceStatusLabels = array_merge(\App\Models\AttendanceRecord::statusOptions(), ['unmarked' => 'Not marked']);
            @endphp
            <section class="mt-6">
                <x-index-table-shell
                    title="Today’s attendance"
                    :meta="\Carbon\Carbon::parse($attendanceToday['date'])->format('d M Y').($contextLocation ? ' · '.$contextLocation->name : ' · accessible branches')"
                    :show-mobile-hint="false"
                >
                    <x-slot:toolbar>
                        @can('viewAny', \App\Models\AttendanceRecord::class)
                            <a href="{{ route('hr.attendance.index', ['date' => $attendanceToday['date']]) }}" class="text-xs font-medium text-slate-600 hover:text-slate-900">Open register</a>
                        @endcan
                    </x-slot:toolbar>

                    <div class="grid gap-3 border-b border-slate-100 p-4 sm:grid-cols-2 lg:grid-cols-5">
                        <div class="rounded-xl bg-slate-50 px-3 py-2.5 ring-1 ring-slate-200">
                            <p class="text-[11px] font-semibold uppercase tracking-wide text-slate-500">Employees</p>
                            <p class="mt-1 text-xl font-semibold tabular-nums text-slate-900">{{ $attendanceCounts['total'] }}</p>
                        </div>
                        <div class="rounded-xl bg-emerald-50/80 px-3 py-2.5 ring-1 ring-emerald-100">
                            <p class="text-[11px] font-semibold uppercase tracking-wide text-emerald-700">Present</p>
                            <p class="mt-1 text-xl font-semibold tabular-nums text-emerald-900">{{ $attendanceCounts['present'] }}</p>
                        </div>
                        <div class="rounded-xl bg-rose-50/80 px-3 py-2.5 ring-1 ring-rose-100">
                            <p class="text-[11px] font-semibold uppercase tracking-wide text-rose-700">Absent</p>
                            <p class="mt-1 text-xl font-semibold tabular-nums text-rose-900">{{ $attendanceCounts['absent'] }}</p>
                        </div>
                        <div class="rounded-xl bg-amber-50/80 px-3 py-2.5 ring-1 ring-amber-100">
                            <p class="text-[11px] font-semibold uppercase tracking-wide text-amber-800">Not marked</p>
                            <p class="mt-1 text-xl font-semibold tabular-nums text-amber-950">{{ $attendanceCounts['unmarked'] }}</p>
                        </div>
                        <div class="rounded-xl bg-sky-50/80 px-3 py-2.5 ring-1 ring-sky-100">
                            <p class="text-[11px] font-semibold uppercase tracking-wide text-sky-700">Checked in</p>
                            <p class="mt-1 text-xl font-semibold tabular-nums text-sky-900">{{ $attendanceCounts['checked_in'] }}</p>
                        </div>
                    </div>

                    <table class="index-data-table min-w-[40rem] sm:min-w-full text-sm whitespace-nowrap">
                        <thead class="bg-slate-50 text-left text-xs font-medium uppercase tracking-wide text-slate-500">
                            <tr>
                                <th class="px-4 py-2.5 sm:px-5">Employee</th>
                                <th class="px-4 py-2.5 sm:px-5">Branch</th>
                                <th class="px-4 py-2.5 sm:px-5">Status</th>
                                <th class="px-4 py-2.5 sm:px-5">Check in</th>
                                <th class="px-4 py-2.5 sm:px-5">Check out</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            @forelse ($attendanceRows->take(12) as $row)
                                @php
                                    $status = $row['status'];
                                    $statusClass = match ($status) {
                                        'present' => 'bg-emerald-50 text-emerald-800 ring-emerald-100',
                                        'absent' => 'bg-rose-50 text-rose-800 ring-rose-100',
                                        'half_day' => 'bg-amber-50 text-amber-900 ring-amber-100',
                                        'leave' => 'bg-sky-50 text-sky-800 ring-sky-100',
                                        'holiday' => 'bg-violet-50 text-violet-800 ring-violet-100',
                                        'weekend' => 'bg-slate-100 text-slate-600 ring-slate-200',
                                        default => 'bg-slate-50 text-slate-500 ring-slate-200',
                                    };
                                @endphp
                                <tr class="hover:bg-slate-50/80">
                                    <td class="px-4 py-3 sm:px-5">
                                        <p class="font-medium text-slate-900">{{ $row['employee']->fullName() }}</p>
                                        <p class="text-xs text-slate-500">{{ $row['employee']->employee_code }}</p>
                                    </td>
                                    <td class="max-w-[12rem] truncate px-4 py-3 text-slate-600 sm:px-5" title="{{ $row['branch'] }}">{{ $row['branch'] }}</td>
                                    <td class="px-4 py-3 sm:px-5">
                                        <span class="inline-flex rounded-full px-2 py-0.5 text-xs font-medium ring-1 {{ $statusClass }}">
                                            {{ $attendanceStatusLabels[$status] ?? ucfirst(str_replace('_', ' ', $status)) }}
                                        </span>
                                    </td>
                                    <td class="px-4 py-3 tabular-nums text-slate-800 sm:px-5">{{ $row['check_in'] ?? '—' }}</td>
                                    <td class="px-4 py-3 tabular-nums text-slate-800 sm:px-5">{{ $row['check_out'] ?? '—' }}</td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="5" class="px-4 py-8 text-center text-slate-500 sm:px-5">No active employees in this branch scope.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                    @if ($attendanceRows->count() > 12)
                        <div class="border-t border-slate-100 px-4 py-3 text-xs text-slate-500 sm:px-5">
                            Showing 12 of {{ $attendanceRows->count() }} employees
                            @can('viewAny', \App\Models\AttendanceRecord::class)
                                · <a href="{{ route('hr.attendance.index', ['date' => $attendanceToday['date']]) }}" class="font-medium text-slate-700 hover:text-slate-900">View all</a>
                            @endcan
                        </div>
                    @endif
                </x-index-table-shell>
            </section>
        @endif
    @endcan

    @php
        $thisMonthInstallments = $monthlyCollection['thisMonthInstallments'];
        $thisMonthCredits = $monthlyCollection['thisMonthCredits'];
        $branchMonthlyTargetProgress = $branchMonthlyTargetProgress ?? [];
    @endphp

    @can('view-dashboard-targets')
        @if ($branchTargetLocations->isNotEmpty())
            @php
                $targetYear = (int) now()->year;
                $targetMonthLabel = now()->format('F Y');
                $annualSalesActual = 0.0;
                $annualSalesGoal = 0.0;
                $annualCollectionActual = 0.0;
                $annualCollectionGoal = 0.0;
                $monthlySalesActual = 0.0;
                $monthlySalesGoal = 0.0;
                $monthlyCollectionActual = 0.0;
                $monthlyCollectionGoal = 0.0;
                $annualTargetsCount = 0;
                $monthlyTargetsCount = 0;
                foreach ($branchTargetLocations as $loc) {
                    $annual = $branchTargetProgress[(int) $loc->id] ?? null;
                    if ($annual && ($annual['has_target'] ?? false)) {
                        $annualTargetsCount++;
                        $annualSalesActual += (float) $annual['sales']['actual'];
                        $annualSalesGoal += (float) $annual['sales']['target'];
                        $annualCollectionActual += (float) $annual['collection']['actual'];
                        $annualCollectionGoal += (float) $annual['collection']['target'];
                    }
                    $monthly = $branchMonthlyTargetProgress[(int) $loc->id] ?? null;
                    if ($monthly && ($monthly['has_target'] ?? false)) {
                        $monthlyTargetsCount++;
                        $monthlySalesActual += (float) $monthly['sales']['actual'];
                        $monthlySalesGoal += (float) $monthly['sales']['target'];
                        $monthlyCollectionActual += (float) $monthly['collection']['actual'];
                        $monthlyCollectionGoal += (float) $monthly['collection']['target'];
                    }
                }
                $annualSalesPct = $annualSalesGoal > 0 ? min(999.9, round(($annualSalesActual / $annualSalesGoal) * 100, 1)) : null;
                $annualCollectionPct = $annualCollectionGoal > 0 ? min(999.9, round(($annualCollectionActual / $annualCollectionGoal) * 100, 1)) : null;
                $monthlySalesPct = $monthlySalesGoal > 0 ? min(999.9, round(($monthlySalesActual / $monthlySalesGoal) * 100, 1)) : null;
                $monthlyCollectionPct = $monthlyCollectionGoal > 0 ? min(999.9, round(($monthlyCollectionActual / $monthlyCollectionGoal) * 100, 1)) : null;
            @endphp
            <section class="mt-6">
                <x-index-table-shell
                    :title="$contextLocation ? $contextLocation->name.' — sales & collection targets' : 'Branch sales & collection targets'"
                    :meta="($contextLocation ? 'Selected location' : 'Accessible branches').' · '.$targetMonthLabel.' + '.$targetYear"
                    :show-mobile-hint="false"
                >
                    <x-slot:toolbar>
                        @if ($contextLocation)
                            @can('viewAny', \App\Models\BranchAnnualTarget::class)
                                <a href="{{ route('locations.show', ['location' => $contextLocation, 'tab' => 'targets']) }}" class="text-xs font-medium text-slate-600 hover:text-slate-900">Manage targets</a>
                            @endcan
                        @endif
                    </x-slot:toolbar>

                    <div class="grid gap-3 border-b border-slate-100 p-4 sm:grid-cols-2 xl:grid-cols-4">
                        <div class="rounded-xl bg-emerald-50/80 px-3 py-2.5 ring-1 ring-emerald-100">
                            <p class="text-[11px] font-semibold uppercase tracking-wide text-emerald-700">This month · sales</p>
                            <p class="mt-1 text-xl font-semibold tabular-nums text-emerald-900">{{ $monthlySalesPct !== null ? number_format($monthlySalesPct, 1).'%' : '—' }}</p>
                            <p class="mt-0.5 text-[11px] tabular-nums text-emerald-800/80">{{ number_format($monthlySalesActual, 0) }} / {{ number_format($monthlySalesGoal, 0) }}</p>
                        </div>
                        <div class="rounded-xl bg-sky-50/80 px-3 py-2.5 ring-1 ring-sky-100">
                            <p class="text-[11px] font-semibold uppercase tracking-wide text-sky-700">This month · collection</p>
                            <p class="mt-1 text-xl font-semibold tabular-nums text-sky-900">{{ $monthlyCollectionPct !== null ? number_format($monthlyCollectionPct, 1).'%' : '—' }}</p>
                            <p class="mt-0.5 text-[11px] tabular-nums text-sky-800/80">{{ number_format($monthlyCollectionActual, 0) }} / {{ number_format($monthlyCollectionGoal, 0) }}</p>
                        </div>
                        <div class="rounded-xl bg-emerald-50/40 px-3 py-2.5 ring-1 ring-emerald-100">
                            <p class="text-[11px] font-semibold uppercase tracking-wide text-emerald-800">Annual · sales</p>
                            <p class="mt-1 text-xl font-semibold tabular-nums text-emerald-950">{{ $annualSalesPct !== null ? number_format($annualSalesPct, 1).'%' : '—' }}</p>
                            <p class="mt-0.5 text-[11px] tabular-nums text-emerald-900/80">{{ number_format($annualSalesActual, 0) }} / {{ number_format($annualSalesGoal, 0) }}</p>
                        </div>
                        <div class="rounded-xl bg-sky-50/40 px-3 py-2.5 ring-1 ring-sky-100">
                            <p class="text-[11px] font-semibold uppercase tracking-wide text-sky-800">Annual · collection</p>
                            <p class="mt-1 text-xl font-semibold tabular-nums text-sky-950">{{ $annualCollectionPct !== null ? number_format($annualCollectionPct, 1).'%' : '—' }}</p>
                            <p class="mt-0.5 text-[11px] tabular-nums text-sky-900/80">{{ number_format($annualCollectionActual, 0) }} / {{ number_format($annualCollectionGoal, 0) }}</p>
                        </div>
                    </div>

                    <table class="index-data-table min-w-[72rem] sm:min-w-full text-sm whitespace-nowrap">
                        <thead class="bg-slate-50 text-left text-xs font-medium uppercase tracking-wide text-slate-500">
                            <tr>
                                <th class="px-4 py-2.5 sm:px-5" rowspan="2">Location</th>
                                <th class="px-4 py-2.5 text-center sm:px-5" colspan="4">This month ({{ now()->format('M Y') }})</th>
                                <th class="px-4 py-2.5 text-center sm:px-5" colspan="4">Annual ({{ $targetYear }})</th>
                            </tr>
                            <tr>
                                <th class="px-4 py-2.5 text-right sm:px-5">Sales %</th>
                                <th class="px-4 py-2.5 text-right sm:px-5">Sales actual / target</th>
                                <th class="px-4 py-2.5 text-right sm:px-5">Collection %</th>
                                <th class="px-4 py-2.5 text-right sm:px-5">Collection actual / target</th>
                                <th class="px-4 py-2.5 text-right sm:px-5">Sales %</th>
                                <th class="px-4 py-2.5 text-right sm:px-5">Sales actual / target</th>
                                <th class="px-4 py-2.5 text-right sm:px-5">Collection %</th>
                                <th class="px-4 py-2.5 text-right sm:px-5">Collection actual / target</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            @php $hasTargetRows = false; @endphp
                            @foreach ($branchTargetLocations as $loc)
                                @php
                                    $annual = $branchTargetProgress[(int) $loc->id] ?? null;
                                    $monthly = $branchMonthlyTargetProgress[(int) $loc->id] ?? null;
                                    $showRow = ($annual && ($annual['has_target'] ?? false)) || ($monthly && ($monthly['has_target'] ?? false));
                                @endphp
                                @if ($showRow)
                                    @php $hasTargetRows = true; @endphp
                                    <tr class="hover:bg-slate-50/80">
                                        <td class="px-4 py-3 font-medium text-slate-900 sm:px-5">
                                            @can('viewAny', \App\Models\BranchAnnualTarget::class)
                                                <a href="{{ route('locations.show', ['location' => $loc, 'tab' => 'targets']) }}" class="hover:text-indigo-700 hover:underline">{{ $loc->name }}</a>
                                            @else
                                                {{ $loc->name }}
                                            @endcan
                                            <span class="ml-1 text-xs text-slate-500">({{ ucfirst($loc->type) }})</span>
                                        </td>
                                        <td class="px-4 py-3 text-right tabular-nums font-medium sm:px-5">
                                            {{ ($monthly['has_target'] ?? false) && $monthly['sales']['pct'] !== null ? number_format($monthly['sales']['pct'], 1).'%' : '—' }}
                                        </td>
                                        <td class="px-4 py-3 text-right tabular-nums text-slate-600 sm:px-5">
                                            @if ($monthly['has_target'] ?? false)
                                                {{ number_format($monthly['sales']['actual'], 0) }} / {{ number_format($monthly['sales']['target'], 0) }}
                                            @else
                                                —
                                            @endif
                                        </td>
                                        <td class="px-4 py-3 text-right tabular-nums font-medium sm:px-5">
                                            {{ ($monthly['has_target'] ?? false) && $monthly['collection']['pct'] !== null ? number_format($monthly['collection']['pct'], 1).'%' : '—' }}
                                        </td>
                                        <td class="px-4 py-3 text-right tabular-nums text-slate-600 sm:px-5">
                                            @if ($monthly['has_target'] ?? false)
                                                {{ number_format($monthly['collection']['actual'], 0) }} / {{ number_format($monthly['collection']['target'], 0) }}
                                            @else
                                                —
                                            @endif
                                        </td>
                                        <td class="px-4 py-3 text-right tabular-nums font-medium sm:px-5">
                                            {{ ($annual['has_target'] ?? false) && $annual['sales']['pct'] !== null ? number_format($annual['sales']['pct'], 1).'%' : '—' }}
                                        </td>
                                        <td class="px-4 py-3 text-right tabular-nums text-slate-600 sm:px-5">
                                            @if ($annual['has_target'] ?? false)
                                                {{ number_format($annual['sales']['actual'], 0) }} / {{ number_format($annual['sales']['target'], 0) }}
                                            @else
                                                —
                                            @endif
                                        </td>
                                        <td class="px-4 py-3 text-right tabular-nums font-medium sm:px-5">
                                            {{ ($annual['has_target'] ?? false) && $annual['collection']['pct'] !== null ? number_format($annual['collection']['pct'], 1).'%' : '—' }}
                                        </td>
                                        <td class="px-4 py-3 text-right tabular-nums text-slate-600 sm:px-5">
                                            @if ($annual['has_target'] ?? false)
                                                {{ number_format($annual['collection']['actual'], 0) }} / {{ number_format($annual['collection']['target'], 0) }}
                                            @else
                                                —
                                            @endif
                                        </td>
                                    </tr>
                                @endif
                            @endforeach
                            @unless ($hasTargetRows)
                                <tr>
                                    <td colspan="9" class="px-4 py-8 text-center text-slate-500 sm:px-5">No monthly or annual targets set for this scope.</td>
                                </tr>
                            @endunless
                        </tbody>
                    </table>
                    <div class="border-t border-slate-100 px-4 py-2.5 text-xs text-slate-500 sm:px-5">
                        {{ $monthlyTargetsCount }} branch{{ $monthlyTargetsCount === 1 ? '' : 'es' }} with monthly targets · {{ $annualTargetsCount }} with annual targets
                    </div>
                </x-index-table-shell>
            </section>
        @endif
    @endcan

        <div class="mt-6 grid gap-6 lg:grid-cols-2">
        <section>
            <x-index-table-shell title="This month — installments to collect" meta="{{ now()->format('F Y') }}" :show-mobile-hint="false">
                <x-slot:toolbar>
                    @can('view-report', 'monthly-collection')
                        <a href="{{ route('reports.monthly-collection') }}" class="text-xs font-medium text-slate-600 hover:text-slate-900">Full report</a>
                    @endcan
                </x-slot:toolbar>
                <table class="index-data-table min-w-[36rem] sm:min-w-full text-sm whitespace-nowrap">
                    <thead class="bg-slate-50 text-left text-xs font-medium uppercase tracking-wide text-slate-500">
                        <tr>
                            <th class="px-4 py-2.5 sm:px-5">Due</th>
                            <th class="px-4 py-2.5 sm:px-5">Customer</th>
                            <th class="px-4 py-2.5 sm:px-5">Invoice</th>
                            <th class="px-4 py-2.5 text-right sm:px-5">Remaining</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @forelse ($thisMonthInstallments as $row)
                            @php
                                $inv = $row->invoice;
                                $remaining = (float) $row->remaining;
                            @endphp
                            <tr class="hover:bg-slate-50/80">
                                <td class="whitespace-nowrap px-4 py-3 text-slate-700 sm:px-5">{{ $row->due_date?->format('M j') ?? '—' }}</td>
                                <td class="max-w-[10rem] truncate px-4 py-3 text-slate-700 sm:px-5">{{ $inv?->customer?->name ?? '—' }}</td>
                                <td class="whitespace-nowrap px-4 py-3 sm:px-5">{{ $inv?->invoice_number ?? '—' }}</td>
                                <td class="whitespace-nowrap px-4 py-3 text-right font-medium tabular-nums text-rose-700 sm:px-5">{{ number_format($remaining, 2) }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="4" class="px-4 py-8 text-center text-slate-500 sm:px-5">No installment dues this month.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </x-index-table-shell>
        </section>

        <section>
            <x-index-table-shell title="This month — credit invoices to collect" meta="{{ now()->format('F Y') }}" :show-mobile-hint="false">
                <table class="index-data-table min-w-[36rem] sm:min-w-full text-sm whitespace-nowrap">
                    <thead class="bg-slate-50 text-left text-xs font-medium uppercase tracking-wide text-slate-500">
                        <tr>
                            <th class="px-4 py-2.5 sm:px-5">Due</th>
                            <th class="px-4 py-2.5 sm:px-5">Customer</th>
                            <th class="px-4 py-2.5 sm:px-5">Invoice</th>
                            <th class="px-4 py-2.5 text-right sm:px-5">Amount due</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @forelse ($thisMonthCredits as $invoice)
                            <tr class="hover:bg-slate-50/80">
                                <td class="whitespace-nowrap px-4 py-3 text-slate-700 sm:px-5">{{ $invoice->collectionDueDate()?->format('M j') ?? '—' }}</td>
                                <td class="max-w-[10rem] truncate px-4 py-3 text-slate-700 sm:px-5">{{ $invoice->customer?->name ?? '—' }}</td>
                                <td class="whitespace-nowrap px-4 py-3 sm:px-5">{{ $invoice->invoice_number }}</td>
                                <td class="whitespace-nowrap px-4 py-3 text-right font-medium tabular-nums text-rose-700 sm:px-5">{{ number_format((float) $invoice->amount_due, 2) }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="4" class="px-4 py-8 text-center text-slate-500 sm:px-5">No credit invoices due this month.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </x-index-table-shell>
        </section>
    </div>

    <div class="mt-6 grid gap-6 lg:grid-cols-2">
        <section>
            <x-index-table-shell title="Upcoming installment dues" meta="Next 14 days" :show-mobile-hint="false">
                <table class="index-data-table min-w-[36rem] sm:min-w-full text-sm whitespace-nowrap">
                    <thead class="bg-slate-50 text-left text-xs font-medium uppercase tracking-wide text-slate-500">
                        <tr>
                            <th class="px-4 py-2.5 sm:px-5">Due</th>
                            <th class="px-4 py-2.5 sm:px-5">Customer</th>
                            <th class="px-4 py-2.5 sm:px-5">Invoice</th>
                            <th class="px-4 py-2.5 text-right sm:px-5">Due balance</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @forelse ($upcomingDues as $row)
                            @php
                                $inv = $row->installmentPlan?->salesInvoice;
                                $remaining = (float) $row->due_amount - (float) $row->paid_amount;
                            @endphp
                            <tr class="hover:bg-slate-50/80">
                                <td class="whitespace-nowrap px-4 py-3 text-slate-700 sm:px-5">{{ $row->due_date->format('M j, Y') }}</td>
                                <td class="max-w-[10rem] truncate px-4 py-3 text-slate-700 sm:max-w-xs sm:px-5" title="{{ $row->installmentPlan?->salesInvoice?->customer?->name }}">
                                    {{ $row->installmentPlan?->salesInvoice?->customer?->name ?? '—' }}
                                </td>
                                <td class="whitespace-nowrap px-4 py-3 sm:px-5">
                                    @if ($inv)
                                        <a href="{{ route('sales.invoices.show', $inv) }}" class="font-medium text-slate-800 underline decoration-slate-300 underline-offset-2 hover:decoration-slate-600">{{ $inv->invoice_number }}</a>
                                    @else
                                        —
                                    @endif
                                </td>
                                <td class="whitespace-nowrap px-4 py-3 text-right font-medium tabular-nums text-slate-900 sm:px-5">{{ number_format($remaining, 2) }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="4" class="px-4 py-8 text-center text-slate-500 sm:px-5">No installment dues in this window.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </x-index-table-shell>
        </section>

        <section>
            <x-index-table-shell title="Overdue installments" :show-mobile-hint="false">
                <x-slot:toolbar>
                    <a href="{{ route('reports.installment-overdue') }}" class="text-xs font-medium text-slate-600 hover:text-slate-900">Full report</a>
                </x-slot:toolbar>
                <table class="index-data-table min-w-[36rem] sm:min-w-full text-sm whitespace-nowrap">
                    <thead class="bg-slate-50 text-left text-xs font-medium uppercase tracking-wide text-slate-500">
                        <tr>
                            <th class="px-4 py-2.5 sm:px-5">Due date</th>
                            <th class="px-4 py-2.5 sm:px-5">Customer</th>
                            <th class="px-4 py-2.5 sm:px-5">Invoice</th>
                            <th class="px-4 py-2.5 text-right sm:px-5">Due balance</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @forelse ($recentOverdues as $row)
                            @php
                                $inv = $row->installmentPlan?->salesInvoice;
                                $remaining = (float) $row->due_amount - (float) $row->paid_amount;
                            @endphp
                            <tr class="hover:bg-rose-50/40">
                                <td class="whitespace-nowrap px-4 py-3 font-medium text-rose-800 sm:px-5">{{ $row->due_date->format('M j, Y') }}</td>
                                <td class="max-w-[10rem] truncate px-4 py-3 text-slate-700 sm:max-w-xs sm:px-5">{{ $row->installmentPlan?->salesInvoice?->customer?->name ?? '—' }}</td>
                                <td class="whitespace-nowrap px-4 py-3 sm:px-5">
                                    @if ($inv)
                                        <a href="{{ route('sales.invoices.show', $inv) }}" class="font-medium text-slate-800 underline decoration-slate-300 underline-offset-2 hover:decoration-slate-600">{{ $inv->invoice_number }}</a>
                                    @else
                                        —
                                    @endif
                                </td>
                                <td class="whitespace-nowrap px-4 py-3 text-right font-medium tabular-nums text-slate-900 sm:px-5">{{ number_format($remaining, 2) }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="4" class="px-4 py-8 text-center text-slate-500 sm:px-5">No overdue schedules in scope.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </x-index-table-shell>
        </section>
    </div>

    <div class="mt-6 grid gap-6 lg:grid-cols-2">
        <section>
            <x-index-table-shell title="Low stock alerts" meta="Available ≤ reorder" :show-mobile-hint="false">
                <table class="index-data-table min-w-[40rem] sm:min-w-full text-sm whitespace-nowrap">
                    <thead class="bg-slate-50 text-left text-xs font-medium uppercase tracking-wide text-slate-500">
                        <tr>
                            <th class="px-4 py-2.5 sm:px-5">SKU</th>
                            <th class="px-4 py-2.5 sm:px-5">Product</th>
                            <th class="px-4 py-2.5 sm:px-5">Loc</th>
                            <th class="px-4 py-2.5 text-right sm:px-5">Avail</th>
                            <th class="px-4 py-2.5 text-right sm:px-5">Reorder</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @forelse ($lowStockItems as $row)
                            <tr class="hover:bg-orange-50/50">
                                <td class="whitespace-nowrap px-4 py-3 font-mono text-xs text-slate-700 sm:px-5">{{ $row->sku }}</td>
                                <td class="max-w-[8rem] truncate px-4 py-3 sm:max-w-[14rem] sm:px-5" title="{{ $row->name }}">
                                    <a href="{{ route('products.show', $row->product_id) }}" class="font-medium text-slate-800 underline decoration-slate-300 underline-offset-2 hover:decoration-slate-600">{{ $row->name }}</a>
                                </td>
                                <td class="whitespace-nowrap px-4 py-3 text-slate-600 sm:px-5">{{ $row->location_name }}</td>
                                <td class="whitespace-nowrap px-4 py-3 text-right font-semibold text-orange-800 tabular-nums sm:px-5">{{ (int) $row->available_qty }}</td>
                                <td class="whitespace-nowrap px-4 py-3 text-right tabular-nums text-slate-600 sm:px-5">{{ (int) $row->reorder_level }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" class="px-4 py-8 text-center text-slate-500 sm:px-5">No low stock rows in this scope.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </x-index-table-shell>
        </section>

        <section>
            <x-index-table-shell title="Transfers in progress" :show-mobile-hint="false">
                <x-slot:toolbar>
                    <a href="{{ route('transfers.index') }}" class="text-xs font-medium text-slate-600 hover:text-slate-900">All transfers</a>
                </x-slot:toolbar>
                <table class="index-data-table min-w-[28rem] sm:min-w-full text-sm whitespace-nowrap">
                    <thead class="bg-slate-50 text-left text-xs font-medium uppercase tracking-wide text-slate-500">
                        <tr>
                            <th class="px-4 py-2.5 sm:px-5">Number</th>
                            <th class="px-4 py-2.5 sm:px-5">Status</th>
                            <th class="px-4 py-2.5 sm:px-5">Route</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @forelse ($pendingTransfers as $t)
                            <tr class="hover:bg-slate-50/80">
                                <td class="whitespace-nowrap px-4 py-3 font-medium text-slate-900 sm:px-5">{{ $t->transfer_number }}</td>
                                <td class="whitespace-nowrap px-4 py-3 sm:px-5">
                                    <span class="rounded-full bg-sky-50 px-2 py-0.5 text-xs font-medium capitalize text-sky-900 ring-1 ring-sky-100">{{ $t->status }}</span>
                                </td>
                                <td class="max-w-[12rem] truncate px-4 py-3 text-slate-600 sm:max-w-none sm:px-5" title="{{ $t->sourceLocation?->name }} → {{ $t->destinationLocation?->name }}">
                                    {{ $t->sourceLocation?->name ?? '?' }} → {{ $t->destinationLocation?->name ?? '?' }}
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="3" class="px-4 py-8 text-center text-slate-500 sm:px-5">No in-flight transfers in this scope.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </x-index-table-shell>
        </section>
    </div>
@endsection
