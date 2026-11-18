@extends('layouts.app')

@section('title', $employee->fullName())
@section('page-title', 'Employee Profile')

@section('content')
<div class="space-y-5">
    @if (session('status'))<div class="rounded-lg bg-emerald-50 p-3 text-sm text-emerald-700">{{ session('status') }}</div>@endif

    <section class="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-slate-200">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <p class="text-xs uppercase tracking-[0.2em] text-slate-500">{{ $employee->employee_code }}</p>
                <h2 class="mt-1 text-2xl font-semibold text-slate-900">{{ $employee->fullName() }}</h2>
                <p class="mt-2 text-sm text-slate-600">
                    {{ $employee->designation?->name ?? 'No designation' }}
                    · {{ $employee->department?->name ?? 'No department' }}
                    · {{ $employee->branchNames() }}
                </p>
                @if ($employee->formattedCheckInTime() || $employee->formattedCheckOutTime())
                    <p class="mt-1 text-sm text-slate-600">
                        Schedule:
                        <span class="font-semibold tabular-nums">{{ $employee->formattedCheckInTime() ?? '—' }}</span>
                        –
                        <span class="font-semibold tabular-nums">{{ $employee->formattedCheckOutTime() ?? '—' }}</span>
                    </p>
                @endif
            </div>
            <div class="text-right text-sm text-slate-600">
                <p>Status: <span class="font-semibold capitalize">{{ $employee->employment_status }}</span></p>
                <p>Joined: <span class="font-semibold">{{ $employee->joining_date?->format('d M Y') }}</span></p>
                @if ($currentAssignment?->payScale)
                    <p>Current pay scale: <span class="font-semibold">{{ $currentAssignment->payScale->name }} ({{ number_format($currentAssignment->payScale->gross_salary, 2) }})</span></p>
                @else
                    <p class="text-amber-700">No pay scale assigned</p>
                @endif
            </div>
        </div>
        <div class="mt-4 flex flex-wrap gap-2">
            <a href="{{ route('hr.employees.index') }}" class="rounded-lg border border-slate-300 px-3 py-1.5 text-sm">Back</a>
            <a href="{{ route('hr.employees.index', ['drawer' => 'edit', 'edit' => $employee->id]) }}" class="rounded-lg border border-blue-200 bg-blue-50 px-3 py-1.5 text-sm font-medium text-blue-700">Edit</a>
            <a href="{{ route('hr.leave-requests.index', ['employee_id' => $employee->id]) }}" class="rounded-lg border border-slate-300 px-3 py-1.5 text-sm">Leave history</a>
        </div>
    </section>

    <div class="grid gap-4 sm:grid-cols-4">
        <x-status-card label="Pending leave"><span class="text-slate-900">{{ $leaveSummary['pending'] ?? 0 }}</span></x-status-card>
        <x-status-card label="Approved leave"><span class="text-slate-900">{{ $leaveSummary['approved'] ?? 0 }}</span></x-status-card>
        <x-status-card label="Rejected"><span class="text-slate-900">{{ $leaveSummary['rejected'] ?? 0 }}</span></x-status-card>
        <x-status-card label="Recent attendance"><span class="text-slate-900">{{ $employee->attendanceRecords->count() }}</span></x-status-card>
    </div>

    <section class="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-slate-200">
        <h3 class="text-base font-semibold text-slate-900">Assign pay scale</h3>
        @can('create', \App\Models\EmployeePayScaleAssignment::class)
            <form action="{{ route('hr.employees.pay-scale.store', $employee) }}" method="post" class="mt-4 grid gap-4 md:grid-cols-4">
                @csrf
                <div class="md:col-span-2">
                    <label class="text-sm font-medium">Pay scale</label>
                    <select name="pay_scale_id" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2" required>
                        <option value="">Select pay scale</option>
                        @foreach ($payScales as $scale)
                            <option value="{{ $scale->id }}">{{ $scale->name }} — {{ number_format($scale->gross_salary, 2) }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="text-sm font-medium">Effective from</label>
                    <input type="date" name="effective_from" value="{{ now()->format('Y-m-d') }}" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2" required>
                </div>
                <div>
                    <label class="text-sm font-medium">Effective to</label>
                    <input type="date" name="effective_to" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2">
                </div>
                <div class="md:col-span-4"><button class="rounded-lg bg-slate-900 px-4 py-2 text-sm text-white">Assign pay scale</button></div>
            </form>
        @endcan

        <div class="mt-6 overflow-x-auto">
            <table class="min-w-full text-sm whitespace-nowrap">
                <thead class="bg-slate-50 text-left text-xs font-semibold uppercase text-slate-600">
                    <tr><th class="px-3 py-2">Pay scale</th><th class="px-3 py-2">From</th><th class="px-3 py-2">To</th><th class="px-3 py-2 text-right">Gross</th></tr>
                </thead>
                <tbody>
                    @forelse ($employee->payScaleAssignments as $assignment)
                        <tr class="border-b border-slate-100">
                            <td class="px-3 py-2">{{ $assignment->payScale?->name ?? '—' }}</td>
                            <td class="px-3 py-2">{{ $assignment->effective_from?->format('d M Y') }}</td>
                            <td class="px-3 py-2">{{ $assignment->effective_to?->format('d M Y') ?? 'Current' }}</td>
                            <td class="px-3 py-2 text-right tabular-nums">{{ number_format($assignment->payScale?->gross_salary ?? 0, 2) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="px-3 py-6 text-center text-slate-500">No pay scale assignments yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>

    <section class="grid gap-5 lg:grid-cols-2">
        <div class="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-slate-200">
            <h3 class="text-base font-semibold">Contact & bank</h3>
            <dl class="mt-3 space-y-2 text-sm text-slate-700">
                <div class="flex justify-between gap-3"><dt>Email</dt><dd class="font-medium">{{ $employee->email ?: '—' }}</dd></div>
                <div class="flex justify-between gap-3"><dt>Phone</dt><dd class="font-medium">{{ $employee->phone ?: '—' }}</dd></div>
                <div class="flex justify-between gap-3"><dt>Bank</dt><dd class="font-medium">{{ $employee->bank_name ?: '—' }}</dd></div>
                <div class="flex justify-between gap-3"><dt>Account</dt><dd class="font-medium">{{ $employee->bank_account ?: '—' }}</dd></div>
                <div class="flex justify-between gap-3"><dt>System user</dt><dd class="font-medium">{{ $employee->user?->name ?? '—' }}</dd></div>
            </dl>
        </div>
        <div class="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-slate-200">
            <h3 class="text-base font-semibold">Recent attendance</h3>
            <div class="mt-3 overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead class="text-left text-xs uppercase text-slate-500"><tr><th class="py-1">Date</th><th class="py-1">Status</th><th class="py-1">In</th><th class="py-1">Out</th></tr></thead>
                    <tbody>
                        @forelse ($employee->attendanceRecords as $record)
                            <tr class="border-t border-slate-100">
                                <td class="py-2">{{ $record->attendance_date?->format('d M Y') }}</td>
                                <td class="py-2 capitalize">{{ str_replace('_', ' ', $record->status) }}</td>
                                <td class="py-2">{{ $record->check_in ? substr($record->check_in, 0, 5) : '—' }}</td>
                                <td class="py-2">{{ $record->check_out ? substr($record->check_out, 0, 5) : '—' }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="py-4 text-slate-500">No attendance records yet.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </section>
</div>
@endsection
