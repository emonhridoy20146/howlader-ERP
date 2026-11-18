<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\HrDepartment;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\Location;
use App\Models\PayScale;
use App\Models\User;
use App\Services\EmployeeCodeGenerator;
use App\Services\PermissionSyncService;
use App\Services\SalarySheetService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HrManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionSyncService::class)->syncFromPolicies();
    }

    public function test_admin_can_create_employee_and_generate_salary_sheet(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $department = HrDepartment::create(['name' => 'Operations', 'is_active' => true]);
        $payScale = PayScale::create([
            'name' => 'Grade A',
            'basic_salary' => 30000,
            'house_rent_allowance' => 5000,
            'medical_allowance' => 2000,
            'transport_allowance' => 1000,
            'other_allowance' => 0,
            'gross_salary' => 38000,
            'is_active' => true,
        ]);

        $this->actingAs($admin)
            ->post(route('hr.employees.store'), [
                'first_name' => 'Rahim',
                'last_name' => 'Uddin',
                'joining_date' => now()->subMonth()->toDateString(),
                'employment_status' => 'active',
                'hr_department_id' => $department->id,
                'is_active' => 1,
            ])
            ->assertRedirect(route('hr.employees.index'));

        $employee = Employee::first();
        $this->assertNotNull($employee);
        $this->assertSame('EMP-00001', $employee->employee_code);

        $this->actingAs($admin)
            ->post(route('hr.employees.pay-scale.store', $employee), [
                'pay_scale_id' => $payScale->id,
                'effective_from' => now()->subMonth()->toDateString(),
            ])
            ->assertRedirect(route('hr.employees.show', $employee));

        $sheet = app(SalarySheetService::class)->generate((int) now()->year, (int) now()->month, $admin->id);

        $this->assertSame(1, $sheet->lines()->count());
        $this->assertSame(38000.0, (float) $sheet->lines()->first()->gross_salary);
    }

    public function test_employee_show_leave_summary_works_with_mysql_group_by(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $leaveType = LeaveType::firstOrFail();

        $employee = Employee::create([
            'employee_code' => 'EMP-00001',
            'first_name' => 'Karim',
            'joining_date' => now()->toDateString(),
            'employment_status' => 'active',
            'is_active' => true,
        ]);

        LeaveRequest::create([
            'employee_id' => $employee->id,
            'leave_type_id' => $leaveType->id,
            'start_date' => now()->toDateString(),
            'end_date' => now()->toDateString(),
            'total_days' => 1,
            'status' => LeaveRequest::STATUS_PENDING,
        ]);

        LeaveRequest::create([
            'employee_id' => $employee->id,
            'leave_type_id' => $leaveType->id,
            'start_date' => now()->addDay()->toDateString(),
            'end_date' => now()->addDay()->toDateString(),
            'total_days' => 1,
            'status' => LeaveRequest::STATUS_APPROVED,
        ]);

        $this->actingAs($admin)
            ->get(route('hr.employees.show', $employee))
            ->assertOk()
            ->assertSee('Pending leave')
            ->assertSee('Approved leave');
    }

    public function test_employee_code_generator_increments(): void
    {
        Employee::create([
            'employee_code' => 'EMP-00001',
            'first_name' => 'Test',
            'joining_date' => now()->toDateString(),
            'employment_status' => 'active',
            'is_active' => true,
        ]);

        $this->assertSame('EMP-00002', app(EmployeeCodeGenerator::class)->next());
    }

    public function test_employee_can_be_assigned_to_multiple_branches(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $department = HrDepartment::create(['name' => 'Sales', 'is_active' => true]);
        $branchA = Location::query()->create([
            'code' => 'HR-A',
            'name' => 'Branch Alpha',
            'type' => 'branch',
            'address' => null,
            'phone' => null,
            'manager_name' => null,
            'is_active' => true,
            'dizicashier_uuid' => null,
        ]);
        $branchB = Location::query()->create([
            'code' => 'HR-B',
            'name' => 'Branch Beta',
            'type' => 'branch',
            'address' => null,
            'phone' => null,
            'manager_name' => null,
            'is_active' => true,
            'dizicashier_uuid' => null,
        ]);

        $this->actingAs($admin)
            ->post(route('hr.employees.store'), [
                'first_name' => 'Sadia',
                'last_name' => 'Khan',
                'joining_date' => now()->toDateString(),
                'employment_status' => 'active',
                'hr_department_id' => $department->id,
                'location_ids' => [$branchA->id, $branchB->id],
                'is_active' => 1,
            ])
            ->assertRedirect(route('hr.employees.index'));

        $employee = Employee::with('locations')->first();
        $this->assertNotNull($employee);
        $this->assertSame(
            [$branchA->id, $branchB->id],
            $employee->locations->pluck('id')->sort()->values()->all()
        );
        $this->assertSame('Branch Alpha, Branch Beta', $employee->branchNames());
    }
}
