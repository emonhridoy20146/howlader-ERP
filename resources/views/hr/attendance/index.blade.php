@extends('layouts.app')

@section('title', 'Attendance')
@section('page-title', 'HR · Attendance')

@section('content')
    @php $attendanceDateLabel = \Carbon\Carbon::parse($filters['date'])->format('d M Y'); @endphp
    @if (session('status'))<div class="mb-4 rounded-lg bg-emerald-50 p-3 text-sm text-emerald-700">{{ session('status') }}</div>@endif

    <div class="mb-4 flex flex-wrap items-center justify-between gap-2">
        <div class="flex flex-wrap gap-2">
            <a href="{{ route('hr.attendance.monthly-report', ['year' => \Carbon\Carbon::parse($filters['date'])->year, 'month' => \Carbon\Carbon::parse($filters['date'])->month, 'department_id' => $filters['department_id'], 'location_id' => $filters['location_id'] ?? '', 'q' => $filters['q'] ?? '']) }}" class="rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50">Monthly report</a>
        </div>
    </div>

    <form method="get" class="mb-4 grid gap-3 rounded-2xl bg-white p-4 shadow-sm ring-1 ring-slate-200 md:grid-cols-5">
        <div>
            <label class="text-xs font-semibold uppercase text-slate-500">Date</label>
            <input type="date" name="date" value="{{ $filters['date'] }}" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
        </div>
        <div>
            <label class="text-xs font-semibold uppercase text-slate-500">Branch</label>
            <select name="location_id" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
                <option value="">All accessible</option>
                @foreach ($locations as $loc)<option value="{{ $loc->id }}" @selected(($filters['location_id'] ?? '') == $loc->id)>{{ $loc->name }}</option>@endforeach
            </select>
        </div>
        <div>
            <label class="text-xs font-semibold uppercase text-slate-500">Department</label>
            <select name="department_id" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
                <option value="">All</option>
                @foreach ($departments as $dept)<option value="{{ $dept->id }}" @selected(($filters['department_id'] ?? '') == $dept->id)>{{ $dept->name }}</option>@endforeach
            </select>
        </div>
        <div>
            <label class="text-xs font-semibold uppercase text-slate-500">Search</label>
            <input type="text" name="q" value="{{ $filters['q'] ?? '' }}" placeholder="Employee name or code" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
        </div>
        <div class="flex items-end"><button class="w-full rounded-lg bg-slate-900 px-4 py-2 text-sm text-white">Load</button></div>
    </form>

    <div class="mb-4 grid gap-3 sm:grid-cols-4">
        @foreach (\App\Models\AttendanceRecord::statusOptions() as $key => $label)
            <x-status-card :label="$label"><span class="text-slate-900">{{ $monthStats[$key] ?? 0 }}</span></x-status-card>
        @endforeach
    </div>

    <form method="post" action="{{ route('hr.attendance.store') }}" id="attendance-register-form">
        @csrf
        <input type="hidden" name="attendance_date" value="{{ $filters['date'] }}">
        <input type="hidden" name="department_id" value="{{ $filters['department_id'] }}">
        <input type="hidden" name="location_id" value="{{ $filters['location_id'] ?? '' }}">
        <input type="hidden" name="q" value="{{ $filters['q'] ?? '' }}">

        <x-index-table-shell :title="'Daily register · '.$attendanceDateLabel" :meta="$employees->count().' employees'">
            <x-slot:toolbar>
                <button type="button" id="fill-scheduled-times" class="rounded-lg border border-slate-300 bg-white px-3 py-1.5 text-xs font-semibold text-slate-700 hover:bg-slate-50">Fill scheduled times</button>
                <button type="submit" class="rounded-lg bg-emerald-700 px-3 py-1.5 text-xs font-semibold text-white hover:bg-emerald-800">Save attendance</button>
            </x-slot:toolbar>
            <table class="index-data-table min-w-[72rem] sm:min-w-full whitespace-nowrap">
                <thead class="bg-slate-50/80 text-left text-[11px] font-semibold uppercase tracking-wide text-slate-600">
                    <tr>
                        <th class="px-4 py-3">Employee</th>
                        <th class="px-4 py-3">Department</th>
                        <th class="px-4 py-3">Status</th>
                        <th class="px-4 py-3">Check in</th>
                        <th class="px-4 py-3">Check out</th>
                        <th class="px-4 py-3">Notes</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse ($employees as $index => $employee)
                        @php
                            $record = $records->get($employee->id);
                            $defaultIn = $employee->formattedCheckInTime();
                            $defaultOut = $employee->formattedCheckOutTime();
                            $checkInValue = old("records.$index.check_in", $record?->check_in ? substr((string) $record->check_in, 0, 5) : ($defaultIn ?? ''));
                            $checkOutValue = old("records.$index.check_out", $record?->check_out ? substr((string) $record->check_out, 0, 5) : ($defaultOut ?? ''));
                        @endphp
                        <tr class="hover:bg-slate-50/70" data-attendance-row>
                            <td class="px-4 py-3">
                                <input type="hidden" name="records[{{ $index }}][employee_id]" value="{{ $employee->id }}">
                                <p class="font-medium">{{ $employee->fullName() }}</p>
                                <p class="text-xs text-slate-500">{{ $employee->employee_code }}</p>
                                @if ($defaultIn || $defaultOut)
                                    <p class="mt-0.5 text-[11px] text-slate-400">Schedule {{ $defaultIn ?? '—' }} – {{ $defaultOut ?? '—' }}</p>
                                @endif
                            </td>
                            <td class="px-4 py-3">{{ $employee->department?->name ?? '—' }}</td>
                            <td class="px-4 py-3">
                                <select name="records[{{ $index }}][status]" class="rounded-lg border border-slate-300 px-2 py-1.5 text-sm">
                                    @foreach (\App\Models\AttendanceRecord::statusOptions() as $value => $label)
                                        <option value="{{ $value }}" @selected(old("records.$index.status", $record->status ?? 'present') === $value)>{{ $label }}</option>
                                    @endforeach
                                </select>
                            </td>
                            <td class="px-4 py-3">
                                <input
                                    type="time"
                                    name="records[{{ $index }}][check_in]"
                                    value="{{ $checkInValue }}"
                                    data-scheduled-in="{{ $defaultIn ?? '' }}"
                                    class="attendance-check-in rounded-lg border border-slate-300 px-2 py-1.5 text-sm"
                                >
                            </td>
                            <td class="px-4 py-3">
                                <input
                                    type="time"
                                    name="records[{{ $index }}][check_out]"
                                    value="{{ $checkOutValue }}"
                                    data-scheduled-out="{{ $defaultOut ?? '' }}"
                                    class="attendance-check-out rounded-lg border border-slate-300 px-2 py-1.5 text-sm"
                                >
                            </td>
                            <td class="px-4 py-3"><input type="text" name="records[{{ $index }}][notes]" value="{{ old("records.$index.notes", $record->notes ?? '') }}" class="w-full min-w-[12rem] rounded-lg border border-slate-300 px-2 py-1.5 text-sm"></td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="px-4 py-8 text-center text-slate-500">No active employees match the filters.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </x-index-table-shell>
    </form>

    <script>
        document.getElementById('fill-scheduled-times')?.addEventListener('click', () => {
            document.querySelectorAll('[data-attendance-row]').forEach((row) => {
                const checkIn = row.querySelector('.attendance-check-in');
                const checkOut = row.querySelector('.attendance-check-out');
                if (checkIn && !checkIn.value && checkIn.dataset.scheduledIn) {
                    checkIn.value = checkIn.dataset.scheduledIn;
                }
                if (checkOut && !checkOut.value && checkOut.dataset.scheduledOut) {
                    checkOut.value = checkOut.dataset.scheduledOut;
                }
            });
        });
    </script>
@endsection
