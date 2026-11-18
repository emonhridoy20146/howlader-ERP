<?php

namespace Tests\Feature;

use App\Models\AttendanceRecord;
use App\Models\Employee;
use App\Models\HrDepartment;
use App\Models\User;
use App\Services\AttendanceReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

class MonthlyAttendanceReportTest extends TestCase
{
    use RefreshDatabase;

    public function test_monthly_attendance_report_aggregates_status_and_payable_days(): void
    {
        $department = HrDepartment::create([
            'name' => 'Ops',
            'code' => 'OPS',
            'is_active' => true,
        ]);

        $user = User::factory()->create([
            'role' => 'admin',
            'is_active' => true,
        ]);

        $employee = Employee::create([
            'employee_code' => 'E-001',
            'hr_department_id' => $department->id,
            'first_name' => 'Test',
            'last_name' => 'Worker',
            'employment_status' => 'active',
            'joining_date' => '2026-01-01',
            'is_active' => true,
        ]);

        AttendanceRecord::create([
            'employee_id' => $employee->id,
            'attendance_date' => '2026-08-05',
            'status' => AttendanceRecord::STATUS_PRESENT,
            'check_in' => '09:15',
            'check_out' => '18:05',
            'recorded_by' => $user->id,
        ]);

        AttendanceRecord::create([
            'employee_id' => $employee->id,
            'attendance_date' => '2026-08-06',
            'status' => AttendanceRecord::STATUS_HALF_DAY,
            'recorded_by' => $user->id,
        ]);

        AttendanceRecord::create([
            'employee_id' => $employee->id,
            'attendance_date' => '2026-08-07',
            'status' => AttendanceRecord::STATUS_ABSENT,
            'recorded_by' => $user->id,
        ]);

        $request = Request::create('/hr/attendance/monthly-report', 'GET', [
            'year' => 2026,
            'month' => 8,
        ]);

        $report = app(AttendanceReportService::class)->monthlyReport($request);

        $this->assertSame('August 2026', $report['periodLabel']);
        $this->assertCount(1, $report['rows']);

        $row = $report['rows']->first();
        $this->assertSame(1, (int) $row['totals'][AttendanceRecord::STATUS_PRESENT]);
        $this->assertSame(1, (int) $row['totals'][AttendanceRecord::STATUS_HALF_DAY]);
        $this->assertSame(1, (int) $row['totals'][AttendanceRecord::STATUS_ABSENT]);
        $this->assertSame(1.5, $row['payable_days']);
        $this->assertSame(31 - 3, $row['unmarked_days']);
        $this->assertSame('09:15', substr((string) $row['cells']['2026-08-05']->check_in, 0, 5));
        $this->assertSame('18:05', substr((string) $row['cells']['2026-08-05']->check_out, 0, 5));
    }

    public function test_monthly_attendance_report_page_loads_for_authorized_user(): void
    {
        $user = User::factory()->create([
            'role' => 'admin',
            'is_active' => true,
        ]);

        $employee = Employee::create([
            'employee_code' => 'E-002',
            'first_name' => 'Shown',
            'last_name' => 'Times',
            'employment_status' => 'active',
            'joining_date' => '2026-01-01',
            'is_active' => true,
        ]);

        AttendanceRecord::create([
            'employee_id' => $employee->id,
            'attendance_date' => '2026-08-05',
            'status' => AttendanceRecord::STATUS_PRESENT,
            'check_in' => '09:15',
            'check_out' => '18:05',
            'recorded_by' => $user->id,
        ]);

        $this->actingAs($user)
            ->get(route('hr.attendance.monthly-report', ['year' => 2026, 'month' => 8]))
            ->assertOk()
            ->assertSee('Monthly register')
            ->assertSee('09:15', false)
            ->assertSee('18:05', false);
    }
}
