<?php

namespace App\Services;

use App\Models\AttendanceRecord;
use App\Models\Employee;
use App\Models\SalarySheet;
use App\Models\SalarySheetLine;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class SalarySheetService
{
    public function generate(int $year, int $month, ?int $generatedByUserId = null): SalarySheet
    {
        $periodStart = Carbon::createFromDate($year, $month, 1)->startOfDay();
        $periodEnd = $periodStart->copy()->endOfMonth();
        $daysInMonth = $periodEnd->day;

        return DB::transaction(function () use ($year, $month, $periodStart, $periodEnd, $daysInMonth, $generatedByUserId): SalarySheet {
            $existing = SalarySheet::query()
                ->where('period_year', $year)
                ->where('period_month', $month)
                ->first();

            if ($existing && ! $existing->isDraft()) {
                return $existing->load(['lines.employee', 'lines.payScale']);
            }

            $sheet = SalarySheet::query()->updateOrCreate(
                [
                    'period_year' => $year,
                    'period_month' => $month,
                ],
                [
                    'title' => $periodStart->format('F Y').' Salary Sheet',
                    'status' => SalarySheet::STATUS_DRAFT,
                    'generated_by' => $generatedByUserId,
                    'finalized_by' => null,
                    'finalized_at' => null,
                ]
            );

            if (! $sheet->isDraft()) {
                return $sheet;
            }

            $sheet->lines()->delete();

            $employees = Employee::query()
                ->where('is_active', true)
                ->where('employment_status', 'active')
                ->where('joining_date', '<=', $periodEnd->toDateString())
                ->where(function ($query) use ($periodStart): void {
                    $query->whereNull('termination_date')
                        ->orWhere('termination_date', '>=', $periodStart->toDateString());
                })
                ->orderBy('employee_code')
                ->get();

            $totalGross = 0.0;
            $totalDeductions = 0.0;
            $totalNet = 0.0;

            foreach ($employees as $employee) {
                $assignment = $employee->currentPayScaleAssignment($periodEnd->toDateString());
                $payScale = $assignment?->payScale;

                $attendance = AttendanceRecord::query()
                    ->where('employee_id', $employee->id)
                    ->whereBetween('attendance_date', [$periodStart->toDateString(), $periodEnd->toDateString()])
                    ->get();

                $daysPresent = 0.0;
                $daysAbsent = 0.0;
                $leaveDays = 0.0;

                foreach ($attendance as $record) {
                    if ($record->status === AttendanceRecord::STATUS_ABSENT) {
                        $daysAbsent += 1;
                    } elseif ($record->status === AttendanceRecord::STATUS_HALF_DAY) {
                        $daysPresent += 0.5;
                        $daysAbsent += 0.5;
                    } elseif ($record->status === AttendanceRecord::STATUS_LEAVE) {
                        $leaveDays += 1;
                        $daysPresent += 1;
                    } elseif (in_array($record->status, [AttendanceRecord::STATUS_PRESENT, AttendanceRecord::STATUS_HOLIDAY, AttendanceRecord::STATUS_WEEKEND], true)) {
                        $daysPresent += 1;
                    }
                }

                $basic = (float) ($payScale?->basic_salary ?? 0);
                $houseRent = (float) ($payScale?->house_rent_allowance ?? 0);
                $medical = (float) ($payScale?->medical_allowance ?? 0);
                $transport = (float) ($payScale?->transport_allowance ?? 0);
                $other = (float) ($payScale?->other_allowance ?? 0);
                $gross = (float) ($payScale?->gross_salary ?? 0);

                $perDay = $daysInMonth > 0 ? round($gross / $daysInMonth, 2) : 0.0;
                $deductions = round($perDay * $daysAbsent, 2);
                $net = max(0, round($gross - $deductions, 2));

                SalarySheetLine::query()->create([
                    'salary_sheet_id' => $sheet->id,
                    'employee_id' => $employee->id,
                    'pay_scale_id' => $payScale?->id,
                    'days_in_month' => $daysInMonth,
                    'days_present' => $daysPresent,
                    'days_absent' => $daysAbsent,
                    'leave_days' => $leaveDays,
                    'basic_salary' => $basic,
                    'house_rent_allowance' => $houseRent,
                    'medical_allowance' => $medical,
                    'transport_allowance' => $transport,
                    'other_allowance' => $other,
                    'gross_salary' => $gross,
                    'deductions' => $deductions,
                    'net_salary' => $net,
                    'notes' => $payScale ? null : 'No pay scale assigned for this period.',
                ]);

                $totalGross += $gross;
                $totalDeductions += $deductions;
                $totalNet += $net;
            }

            $sheet->update([
                'total_gross' => round($totalGross, 2),
                'total_deductions' => round($totalDeductions, 2),
                'total_net' => round($totalNet, 2),
            ]);

            return $sheet->fresh(['lines.employee', 'lines.payScale']);
        });
    }

    public function finalize(SalarySheet $sheet, int $userId): SalarySheet
    {
        if (! $sheet->isDraft()) {
            return $sheet;
        }

        $sheet->update([
            'status' => SalarySheet::STATUS_FINALIZED,
            'finalized_by' => $userId,
            'finalized_at' => now(),
        ]);

        return $sheet->fresh();
    }
}
