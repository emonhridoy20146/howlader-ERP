@extends('layouts.app')

@section('title', $salarySheet->periodLabel().' Salary Sheet')
@section('page-title', 'Salary Sheet')

@section('content')
<div class="space-y-5">
    @if (session('status'))<div class="rounded-lg bg-emerald-50 p-3 text-sm text-emerald-700">{{ session('status') }}</div>@endif

    <section class="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-slate-200">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <p class="text-xs uppercase tracking-[0.2em] text-slate-500">Payroll</p>
                <h2 class="mt-1 text-2xl font-semibold text-slate-900">{{ $salarySheet->title }}</h2>
                <p class="mt-2 text-sm text-slate-600 capitalize">Status: {{ $salarySheet->status }}</p>
            </div>
            <div class="text-right text-sm text-slate-600">
                <p>Gross: <span class="font-semibold">{{ number_format($salarySheet->total_gross, 2) }}</span></p>
                <p>Deductions: <span class="font-semibold">{{ number_format($salarySheet->total_deductions, 2) }}</span></p>
                <p>Net pay: <span class="font-semibold text-emerald-700">{{ number_format($salarySheet->total_net, 2) }}</span></p>
            </div>
        </div>
        <div class="mt-4 flex flex-wrap gap-2">
            <a href="{{ route('hr.salary-sheets.index') }}" class="rounded-lg border border-slate-300 px-3 py-1.5 text-sm">Back</a>
            @if ($salarySheet->isDraft())
                @can('finalize', $salarySheet)
                    <form action="{{ route('hr.salary-sheets.finalize', $salarySheet) }}" method="post" data-confirm="Finalize this salary sheet? It cannot be edited after.">
                        @csrf
                        <button class="rounded-lg bg-emerald-700 px-3 py-1.5 text-sm font-medium text-white">Finalize</button>
                    </form>
                @endcan
            @endif
        </div>
    </section>

    <section class="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-slate-200">
        <h3 class="text-base font-semibold text-slate-900">Employee lines</h3>
        <div class="mt-3 overflow-x-auto">
            <table class="min-w-full text-sm whitespace-nowrap">
                <thead class="bg-slate-50 text-left text-xs font-semibold uppercase text-slate-600">
                    <tr>
                        <th class="px-3 py-2">Employee</th>
                        <th class="px-3 py-2">Pay scale</th>
                        <th class="px-3 py-2 text-right">Present</th>
                        <th class="px-3 py-2 text-right">Absent</th>
                        <th class="px-3 py-2 text-right">Leave</th>
                        <th class="px-3 py-2 text-right">Gross</th>
                        <th class="px-3 py-2 text-right">Deductions</th>
                        <th class="px-3 py-2 text-right">Net</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($salarySheet->lines as $line)
                        <tr class="border-b border-slate-100">
                            <td class="px-3 py-2">
                                <p class="font-medium">{{ $line->employee?->fullName() }}</p>
                                <p class="text-xs text-slate-500">{{ $line->employee?->employee_code }}</p>
                            </td>
                            <td class="px-3 py-2">{{ $line->payScale?->name ?? '—' }}</td>
                            <td class="px-3 py-2 text-right tabular-nums">{{ number_format($line->days_present, 1) }}</td>
                            <td class="px-3 py-2 text-right tabular-nums">{{ number_format($line->days_absent, 1) }}</td>
                            <td class="px-3 py-2 text-right tabular-nums">{{ number_format($line->leave_days, 1) }}</td>
                            <td class="px-3 py-2 text-right tabular-nums">{{ number_format($line->gross_salary, 2) }}</td>
                            <td class="px-3 py-2 text-right tabular-nums text-rose-700">{{ number_format($line->deductions, 2) }}</td>
                            <td class="px-3 py-2 text-right tabular-nums font-semibold">{{ number_format($line->net_salary, 2) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="8" class="px-3 py-8 text-center text-slate-500">No employee lines. Assign pay scales and regenerate if needed.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>
</div>
@endsection
