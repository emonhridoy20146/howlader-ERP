@extends('layouts.app')

@section('title', 'Salary Sheets')
@section('page-title', 'HR · Salary Sheets')

@section('content')
    @if (session('status'))<div class="mb-4 rounded-lg bg-emerald-50 p-3 text-sm text-emerald-700">{{ session('status') }}</div>@endif

    @can('generate', \App\Models\SalarySheet::class)
        <form method="post" action="{{ route('hr.salary-sheets.store') }}" class="mb-4 grid gap-3 rounded-2xl bg-white p-4 shadow-sm ring-1 ring-slate-200 md:grid-cols-4">
            @csrf
            <div>
                <label class="text-xs font-semibold uppercase text-slate-500">Year</label>
                <input type="number" name="period_year" value="{{ now()->year }}" min="2000" max="2100" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm" required>
            </div>
            <div>
                <label class="text-xs font-semibold uppercase text-slate-500">Month</label>
                <select name="period_month" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm" required>
                    @for ($m = 1; $m <= 12; $m++)
                        <option value="{{ $m }}" @selected($m === (int) now()->month)>{{ \Carbon\Carbon::createFromDate(now()->year, $m, 1)->format('F') }}</option>
                    @endfor
                </select>
            </div>
            <div class="flex items-end md:col-span-2">
                <button class="rounded-lg bg-slate-900 px-4 py-2 text-sm font-medium text-white">Generate salary sheet</button>
            </div>
        </form>
    @endcan

    <div class="mb-4 grid gap-3 sm:grid-cols-3">
        <x-status-card label="Total sheets"><span class="text-slate-900">{{ $kpis['total'] }}</span></x-status-card>
        <x-status-card label="Draft"><span class="text-amber-700">{{ $kpis['draft'] }}</span></x-status-card>
        <x-status-card label="Finalized"><span class="text-emerald-700">{{ $kpis['finalized'] }}</span></x-status-card>
    </div>

    <x-index-table-shell title="Salary Sheets" :meta="$salarySheets->total().' in this list'">
        <table class="index-data-table min-w-[48rem] sm:min-w-full whitespace-nowrap">
            <thead class="bg-slate-50/80 text-left text-[11px] font-semibold uppercase tracking-wide text-slate-600">
                <tr><th class="px-4 py-3">Period</th><th class="px-4 py-3">Status</th><th class="px-4 py-3 text-right">Gross</th><th class="px-4 py-3 text-right">Deductions</th><th class="px-4 py-3 text-right">Net</th><th class="px-4 py-3 text-right">Actions</th></tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @forelse ($salarySheets as $row)
                    <tr class="hover:bg-slate-50/70">
                        <td class="px-4 py-3 font-medium">{{ $row->periodLabel() }}</td>
                        <td class="px-4 py-3 capitalize">{{ $row->status }}</td>
                        <td class="px-4 py-3 text-right tabular-nums">{{ number_format($row->total_gross, 2) }}</td>
                        <td class="px-4 py-3 text-right tabular-nums">{{ number_format($row->total_deductions, 2) }}</td>
                        <td class="px-4 py-3 text-right tabular-nums font-semibold">{{ number_format($row->total_net, 2) }}</td>
                        <td class="px-4 py-3 text-right">
                            <x-index-row-actions-menu>
                                <a href="{{ route('hr.salary-sheets.show', $row) }}">View</a>
                                @if ($row->isDraft())
                                    <form action="{{ route('hr.salary-sheets.destroy', $row) }}" method="post" data-confirm="Delete draft sheet?">@csrf @method('DELETE')<button type="submit" class="index-actions-menu-item--danger">Delete</button></form>
                                @endif
                            </x-index-row-actions-menu>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="px-4 py-8 text-center text-slate-500">No salary sheets yet. Generate one above.</td></tr>
                @endforelse
            </tbody>
        </table>
    </x-index-table-shell>
    <x-pagination-limit-selector /><div class="mt-4">{{ $salarySheets->links() }}</div>
@endsection
