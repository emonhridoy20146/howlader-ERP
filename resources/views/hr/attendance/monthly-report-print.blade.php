<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Monthly attendance — {{ $periodLabel }}</title>
    <style>
        * { box-sizing: border-box; }
        body { margin: 0; font-family: ui-sans-serif, system-ui, Arial, sans-serif; background: #334155; color: #0f172a; }
        .toolbar { max-width: 1200px; margin: 0 auto; padding: 12px 16px; display: flex; justify-content: flex-end; gap: 8px; }
        .btn { border: 1px solid #cbd5e1; background: #fff; border-radius: 8px; font-size: 13px; padding: 8px 14px; cursor: pointer; font-weight: 500; text-decoration: none; color: #334155; }
        .frame { padding: 0 16px 24px; }
        .sheet { max-width: 1200px; margin: 0 auto; background: #fff; padding: 24px; border-radius: 8px; }
        h1 { margin: 0 0 4px; font-size: 22px; }
        .sub { font-size: 12px; color: #64748b; margin-bottom: 12px; }
        .legend { font-size: 9px; color: #475569; margin-bottom: 12px; line-height: 1.6; }
        table { width: 100%; border-collapse: collapse; font-size: 8px; }
        th, td { border: 1px solid #cbd5e1; padding: 3px 4px; }
        th { background: #f1f5f9; font-weight: 600; text-transform: uppercase; letter-spacing: .03em; }
        .emp { min-width: 120px; text-align: left; }
        .day { width: 18px; text-align: center; padding: 2px 1px; }
        .num { text-align: right; }
        .c-present { background: #ecfdf5; }
        .c-absent { background: #fff1f2; }
        .c-half_day { background: #fffbeb; }
        .c-leave { background: #f0f9ff; }
        .c-holiday { background: #f5f3ff; }
        .c-weekend { background: #f8fafc; color: #64748b; }
        @media print {
            body { background: #fff; }
            .toolbar { display: none; }
            .frame { padding: 0; }
            .sheet { box-shadow: none; border-radius: 0; max-width: none; padding: 0; }
        }
    </style>
</head>
<body>
    <div class="toolbar">
        <button type="button" class="btn" onclick="window.print()">Print</button>
        <a href="{{ route('hr.attendance.monthly-report', request()->query()) }}" class="btn">Back to report</a>
    </div>
    <div class="frame">
        <div class="sheet">
            <h1>Monthly attendance report</h1>
            <p class="sub">{{ $periodLabel }} · {{ $periodStart }} to {{ $periodEnd }} · {{ $rows->count() }} employees</p>
            <p class="legend">
                @foreach (\App\Models\AttendanceRecord::statusAbbreviations() as $status => $abbrev)
                    <strong>{{ $abbrev }}</strong> = {{ \App\Models\AttendanceRecord::statusOptions()[$status] }} ·
                @endforeach
                — = no record · times under status = check-in / check-out
            </p>
            <table>
                <thead>
                    <tr>
                        <th class="num">#</th>
                        <th class="emp">Employee</th>
                        <th>Dept</th>
                        @foreach ($dayDates as $date)
                            <th class="day">{{ \Carbon\Carbon::parse($date)->format('d') }}</th>
                        @endforeach
                        @foreach (\App\Models\AttendanceRecord::statusOptions() as $key => $label)
                            <th class="num">{{ \App\Models\AttendanceRecord::statusAbbreviations()[$key] }}</th>
                        @endforeach
                        <th class="num">Pay</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($rows as $row)
                        @php $employee = $row['employee']; @endphp
                        <tr>
                            <td class="num">{{ $loop->iteration }}</td>
                            <td class="emp">
                                <strong>{{ $employee->fullName() }}</strong><br>
                                <span style="color:#64748b">{{ $employee->employee_code }}</span>
                            </td>
                            <td>{{ $employee->department?->name ?? '—' }}</td>
                            @foreach ($dayDates as $date)
                                @php
                                    $record = $row['cells'][$date] ?? null;
                                    $status = $record?->status;
                                    $checkIn = $record?->check_in ? substr((string) $record->check_in, 0, 5) : null;
                                    $checkOut = $record?->check_out ? substr((string) $record->check_out, 0, 5) : null;
                                @endphp
                                <td class="day {{ $status ? 'c-'.$status : '' }}">
                                    {{ $record ? $record->statusAbbreviation() : '—' }}
                                    @if ($checkIn || $checkOut)
                                        <br><span style="font-size:7px;font-weight:500">{{ $checkIn ?? '—' }}</span>
                                        <br><span style="font-size:7px;font-weight:500;color:#64748b">{{ $checkOut ?? '—' }}</span>
                                    @endif
                                </td>
                            @endforeach
                            @foreach (\App\Models\AttendanceRecord::statusOptions() as $key => $label)
                                <td class="num">{{ (int) ($row['totals'][$key] ?? 0) }}</td>
                            @endforeach
                            <td class="num">{{ number_format($row['payable_days'], 1) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
</body>
</html>
