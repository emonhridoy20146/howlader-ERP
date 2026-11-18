<?php

namespace Tests\Feature;

use App\Models\AttendanceRecord;
use App\Models\Employee;
use App\Models\Location;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\PermissionSyncService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardAttendanceWidgetTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_hides_attendance_widget_without_permission(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-09 10:00:00'));
        app(PermissionSyncService::class)->syncFromPolicies();

        $branch = $this->makeBranch('BR-A', 'Branch A');
        $user = User::factory()->create([
            'role' => 'sales',
            'is_active' => true,
            'branch_id' => $branch->id,
        ]);
        $user->accessibleLocations()->sync([$branch->id]);

        $employee = $this->makeEmployee('E-A1', 'Alice', 'Alpha');
        $employee->locations()->sync([$branch->id]);

        AttendanceRecord::create([
            'employee_id' => $employee->id,
            'attendance_date' => '2026-08-09',
            'status' => AttendanceRecord::STATUS_PRESENT,
            'check_in' => '09:00',
            'check_out' => '18:00',
        ]);

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertDontSee('Today’s attendance', false)
            ->assertDontSee('Alice Alpha', false);
    }

    public function test_dashboard_attendance_widget_is_scoped_to_accessible_branches(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-09 10:00:00'));
        app(PermissionSyncService::class)->syncFromPolicies();

        $branchA = $this->makeBranch('BR-A', 'Branch A');
        $branchB = $this->makeBranch('BR-B', 'Branch B');

        $permissionId = Permission::query()->where('slug', 'dashboard.attendance.view')->value('id');
        $this->assertNotNull($permissionId);

        $role = Role::query()->create([
            'name' => 'Attendance widget',
            'slug' => 'attendance-widget-test',
        ]);
        $role->permissions()->sync([(int) $permissionId]);

        $user = User::factory()->create([
            'role' => 'sales',
            'is_active' => true,
            'branch_id' => $branchA->id,
        ]);
        $user->roles()->sync([$role->id]);
        $user->accessibleLocations()->sync([$branchA->id]);

        $employeeA = $this->makeEmployee('E-A1', 'Alice', 'Alpha');
        $employeeA->locations()->sync([$branchA->id]);
        $employeeA->update([
            'check_in_time' => '09:00',
            'check_out_time' => '18:00',
        ]);

        $employeeB = $this->makeEmployee('E-B1', 'Bob', 'Beta');
        $employeeB->locations()->sync([$branchB->id]);

        AttendanceRecord::create([
            'employee_id' => $employeeA->id,
            'attendance_date' => '2026-08-09',
            'status' => AttendanceRecord::STATUS_PRESENT,
            'check_in' => '09:05',
            'check_out' => '17:55',
        ]);

        AttendanceRecord::create([
            'employee_id' => $employeeB->id,
            'attendance_date' => '2026-08-09',
            'status' => AttendanceRecord::STATUS_ABSENT,
        ]);

        $response = $this->actingAs($user)->get(route('dashboard'));

        $response->assertOk();
        $response->assertSee('Today’s attendance', false);
        $response->assertSee('Alice Alpha', false);
        $response->assertSee('Present', false);
        $response->assertDontSee('Bob Beta', false);

        $summary = app(\App\Services\AttendanceReportService::class)
            ->todayDashboardSummary($user, null);
        $this->assertSame(1, $summary['counts']['total']);
        $this->assertSame(1, $summary['counts']['present']);
        $this->assertSame('09:05', $summary['rows']->first()['check_in']);
    }

    public function test_attendance_register_prefills_employee_scheduled_times(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-09 10:00:00'));
        app(PermissionSyncService::class)->syncFromPolicies();

        $branch = $this->makeBranch('BR-A', 'Branch A');
        $user = User::factory()->create([
            'role' => 'admin',
            'is_active' => true,
        ]);

        $employee = $this->makeEmployee('E-A1', 'Alice', 'Alpha');
        $employee->locations()->sync([$branch->id]);
        $employee->update([
            'check_in_time' => '09:30',
            'check_out_time' => '18:30',
        ]);

        $this->actingAs($user)
            ->get(route('hr.attendance.index', ['date' => '2026-08-09']))
            ->assertOk()
            ->assertSee('value="09:30"', false)
            ->assertSee('value="18:30"', false)
            ->assertSee('Fill scheduled times', false);
    }

    private function makeBranch(string $code, string $name): Location
    {
        return Location::create([
            'code' => $code,
            'name' => $name,
            'type' => 'branch',
            'is_active' => true,
        ]);
    }

    private function makeEmployee(string $code, string $first, string $last): Employee
    {
        return Employee::create([
            'employee_code' => $code,
            'first_name' => $first,
            'last_name' => $last,
            'employment_status' => 'active',
            'joining_date' => '2026-01-01',
            'is_active' => true,
        ]);
    }
}
