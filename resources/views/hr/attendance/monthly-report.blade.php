@extends('layouts.app')

@section('title', 'Monthly attendance report')
@section('page-title', 'HR · Monthly attendance')

@section('content')
    @php
        $statusOptions = \App\Models\AttendanceRecord::statusOptions();
        $statusAbbrevs = \App\Models\AttendanceRecord::statusAbbreviations();
        $cellClass = static function (?string $status): string {
            return match ($status) {
                'present' => 'bg-emerald-50 text-emerald-800',
                'absent' => 'bg-rose-50 text-rose-800',
                'half_day' => 'bg-amber-50 text-amber-900',
                'leave' => 'bg-sky-50 text-sky-800',
                'holiday' => 'bg-violet-50 text-violet-800',
                'weekend' => 'bg-slate-100 text-slate-600',
                default => 'bg-white text-slate-300',
            };
        };
    @endphp

    <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
        <p class="text-sm text-slate-600">Attendance matrix for <strong>{{ $periodLabel }}</strong> ({{ $periodStart }} to {{ $periodEnd }}). Blank cells have no entry yet.</p>
        <div class="flex flex-wrap gap-2">
            <a href="{{ route('hr.attendance.index', ['date' => $periodStart]) }}" class="rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50">Daily register</a>
            <a href="{{ route('hr.attendance.monthly-report.print', request()->query()) }}" target="_blank" rel="noopener" class="rounded-lg bg-slate-900 px-3 py-2 text-sm font-medium text-white hover:bg-slate-800">Print</a>
        </div>
    </div>

    <form method="get" action="{{ route('hr.attendance.monthly-report') }}" class="mb-4 grid gap-3 rounded-2xl bg-white p-4 shadow-sm ring-1 ring-slate-200 md:grid-cols-6">
        <div>
            <label class="text-xs font-semibold uppercase text-slate-500">Year</label>
            <input type="number" name="year" value="{{ $filters['year'] }}" min="2000" max="2100" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm" required>
        </div>
        <div>
            <label class="text-xs font-semibold uppercase text-slate-500">Month</label>
            <select name="month" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm" required>
                @for ($m = 1; $m <= 12; $m++)
                    <option value="{{ $m }}" @selected($filters['month'] === $m)>{{ \Carbon\Carbon::createFromDate($filters['year'], $m, 1)->format('F') }}</option>
                @endfor
            </select>
        </div>
        <div>
            <label class="text-xs font-semibold uppercase text-slate-500">Branch</label>
            <select name="location_id" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
                <option value="">All accessible</option>
                @foreach ($locations as $loc)
                    <option value="{{ $loc->id }}" @selected(($filters['location_id'] ?? '') == $loc->id)>{{ $loc->name }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="text-xs font-semibold uppercase text-slate-500">Department</label>
            <select name="department_id" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
                <option value="">All</option>
                @foreach ($departments as $dept)
                    <option value="{{ $dept->id }}" @selected(($filters['department_id'] ?? '') == $dept->id)>{{ $dept->name }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="text-xs font-semibold uppercase text-slate-500">Search</label>
            <input type="text" name="q" value="{{ $filters['q'] ?? '' }}" placeholder="Employee name or code" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
        </div>
        <div class="flex items-end"><button class="w-full rounded-lg bg-slate-900 px-4 py-2 text-sm text-white">Load report</button></div>
    </form>

    <div class="mb-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
        @foreach ($statusOptions as $key => $label)
            <x-status-card :label="$label.' (days)'">
                <span class="text-slate-900">{{ (int) ($monthTotals[$key] ?? 0) }}</span>
            </x-status-card>
        @endforeach
        <x-status-card label="Unmarked (cells)">
            <span class="text-slate-500">{{ (int) ($monthTotals['unmarked'] ?? 0) }}</span>
        </x-status-card>
    </div>

    <div class="mb-4 flex flex-wrap gap-2 text-xs text-slate-600">
        @foreach ($statusAbbrevs as $status => $abbrev)
            <span class="inline-flex items-center gap-1 rounded-md px-2 py-1 ring-1 ring-slate-200 {{ $cellClass($status) }}">
                <strong>{{ $abbrev }}</strong> {{ $statusOptions[$status] }}
            </span>
        @endforeach
        <span class="inline-flex items-center rounded-md px-2 py-1 ring-1 ring-slate-200 text-slate-400">— No record</span>
        <span class="inline-flex items-center rounded-md px-2 py-1 ring-1 ring-slate-200 text-slate-500">Times under status = check-in / check-out</span>
    </div>

    <x-index-table-shell :title="'Monthly register · '.$periodLabel" :meta="$rows->count().' employees'">
        <div class="overflow-x-auto">
            <table class="index-data-table min-w-max whitespace-nowrap text-xs">
                <thead class="bg-slate-50/80 text-left text-[11px] font-semibold uppercase tracking-wide text-slate-600">
                    <tr>
                        <th class="sticky left-0 z-10 bg-slate-50 px-3 py-2">Employee</th>
                        <th class="px-3 py-2">Dept</th>
                        @foreach ($dayDates as $date)
                            <th class="px-1 py-2 text-center tabular-nums" title="{{ $date }}">{{ \Carbon\Carbon::parse($date)->format('d') }}</th>
                        @endforeach
                        @foreach ($statusOptions as $key => $label)
                            <th class="px-2 py-2 text-right">{{ $statusAbbrevs[$key] }}</th>
                        @endforeach
                        <th class="px-2 py-2 text-right">Payable</th>
                        <th class="px-2 py-2 text-right">Blank</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse ($rows as $row)
                        @php $employee = $row['employee']; @endphp
                        <tr class="hover:bg-slate-50/70">
                            <td class="sticky left-0 z-10 bg-white px-3 py-2">
                                <p class="font-medium text-slate-900">{{ $employee->fullName() }}</p>
                                <p class="text-[10px] text-slate-500">{{ $employee->employee_code }}</p>
                            </td>
                            <td class="px-3 py-2 text-slate-600">{{ $employee->department?->name ?? '—' }}</td>
                            @foreach ($dayDates as $date)
                                @php
                                    $record = $row['cells'][$date] ?? null;
                                    $checkIn = $record?->check_in ? substr((string) $record->check_in, 0, 5) : null;
                                    $checkOut = $record?->check_out ? substr((string) $record->check_out, 0, 5) : null;
                                    $timeTitle = collect([
                                        $record ? ($statusOptions[$record->status] ?? $record->status) : 'No record',
                                        $date,
                                        $checkIn ? 'In '.$checkIn : null,
                                        $checkOut ? 'Out '.$checkOut : null,
                                    ])->filter()->implode(' · ');
                                @endphp
                                <td class="px-0.5 py-1 text-center align-top">
                                    <span
                                        class="inline-flex min-w-[2.75rem] flex-col items-center justify-center rounded px-1 py-0.5 font-semibold tabular-nums {{ $cellClass($record?->status) }}"
                                        title="{{ $timeTitle }}"
                                    >
                                        <span>{{ $record ? $record->statusAbbreviation() : '—' }}</span>
                                        @if ($checkIn || $checkOut)
                                            <span class="mt-0.5 text-[9px] font-medium leading-tight text-current/80">{{ $checkIn ?? '—' }}</span>
                                            <span class="text-[9px] font-medium leading-tight text-current/70">{{ $checkOut ?? '—' }}</span>
                                        @endif
                                    </span>
                                </td>
                            @endforeach
                            @foreach ($statusOptions as $key => $label)
                                <td class="px-2 py-2 text-right tabular-nums text-slate-700">{{ (int) ($row['totals'][$key] ?? 0) }}</td>
                            @endforeach
                            <td class="px-2 py-2 text-right tabular-nums font-semibold text-emerald-800">{{ number_format($row['payable_days'], 1) }}</td>
                            <td class="px-2 py-2 text-right tabular-nums text-slate-400">{{ $row['unmarked_days'] }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="{{ 4 + count($dayDates) + count($statusOptions) }}" class="px-4 py-8 text-center text-slate-500">No active employees match the filters.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-index-table-shell>
@endsection
