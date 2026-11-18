<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\CustomerRiskSnapshot;
use App\Models\InstallmentPaymentAllocation;
use App\Models\InstallmentPlan;
use App\Models\InstallmentSchedule;
use App\Models\Location;
use App\Models\Payment;
use App\Models\Permission;
use App\Models\Role;
use App\Models\SalesInvoice;
use App\Models\User;
use App\Services\PermissionSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HighRiskCustomersReportTest extends TestCase
{
    use RefreshDatabase;

    private Location $branch;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionSyncService::class)->syncFromPolicies();
        $this->branch = Location::create(['code' => 'BR-HR', 'name' => 'HR Branch', 'type' => 'branch', 'is_active' => true]);
        $this->admin = User::factory()->create(['role' => 'admin', 'is_active' => true, 'branch_id' => $this->branch->id]);
    }

    private function snap(string $name, string $grade, string $classification = 'none', array $extra = [], ?Location $branch = null, array $customerExtra = []): Customer
    {
        $branch ??= $this->branch;
        $c = Customer::create(array_merge([
            'customer_code' => 'HR-'.strtoupper(substr(md5($name), 0, 6)), 'name' => $name,
            'phone' => '0171'.random_int(1000000, 9999999), 'is_active' => true, 'branch_id' => $branch->id,
        ], $customerExtra));
        CustomerRiskSnapshot::query()->updateOrCreate(['customer_id' => $c->id], array_merge([
            'branch_id' => $branch->id, 'score' => 50, 'grade' => $grade, 'risk_level' => 'Medium', 'classification' => $classification,
            'status' => 'overdue', 'overdue_amount' => 100, 'outstanding_amount' => 500, 'max_days_late' => 10, 'avg_days_late' => 5,
            'late_6m' => 1, 'computed_at' => now(),
        ], $extra));

        return $c;
    }

    private function viewer(array $slugs): User
    {
        $user = User::factory()->create(['is_active' => true, 'branch_id' => $this->branch->id]);
        $user->accessibleLocations()->sync([$this->branch->id]);
        $role = Role::query()->create(['name' => 'R '.uniqid(), 'slug' => 'r-'.uniqid()]);
        $role->permissions()->sync(Permission::query()->whereIn('slug', $slugs)->pluck('id')->all());
        $user->roles()->sync([$role->id]);

        return $user;
    }

    public function test_snapshot_refreshes_when_a_late_payment_is_allocated(): void
    {
        $customer = Customer::create(['customer_code' => 'C-SNAP', 'name' => 'Snap Sam', 'phone' => '01700000001', 'is_active' => true, 'branch_id' => $this->branch->id]);
        $invoice = SalesInvoice::create([
            'invoice_number' => 'S-SNAP', 'branch_id' => $this->branch->id, 'customer_id' => $customer->id, 'invoice_date' => now()->subMonths(2)->toDateString(),
            'sale_type' => 'installment', 'status' => 'posted', 'payment_status' => 'partial', 'subtotal' => 500, 'discount_total' => 0, 'tax_total' => 0,
            'grand_total' => 500, 'amount_paid' => 0, 'amount_due' => 500, 'created_by' => $this->admin->id,
        ]);
        $plan = InstallmentPlan::create([
            'sales_invoice_id' => $invoice->id, 'customer_id' => $customer->id, 'total_amount' => 500, 'down_payment' => 0, 'financed_amount' => 500,
            'remaining_amount' => 500, 'penalty_enabled' => false, 'penalty_rate' => 0, 'status' => 'active', 'created_by' => $this->admin->id,
        ]);
        $schedule = InstallmentSchedule::create([
            'installment_plan_id' => $plan->id, 'installment_no' => 1, 'due_date' => now()->subDays(20)->toDateString(),
            'due_amount' => 500, 'paid_amount' => 0, 'status' => 'overdue',
        ]);

        $snapshot = $customer->fresh()->riskSnapshot;
        $this->assertNotNull($snapshot);
        $this->assertSame(500.0, $snapshot->overdue_amount);
        $this->assertSame('overdue', $snapshot->status);

        $schedule->update(['paid_amount' => 500, 'status' => 'paid']);
        $payment = Payment::create([
            'payment_number' => 'P-SNAP', 'customer_id' => $customer->id, 'payment_date' => now()->toDateString(), 'payment_method' => 'cash',
            'amount' => 500, 'direction' => 'inbound', 'recorded_by' => $this->admin->id,
        ]);
        InstallmentPaymentAllocation::create(['installment_schedule_id' => $schedule->id, 'payment_id' => $payment->id, 'allocated_amount' => 500]);

        $snapshot = $customer->fresh()->riskSnapshot;
        $this->assertSame(0.0, $snapshot->overdue_amount);
        $this->assertSame(20, (int) $snapshot->max_days_late);
    }

    public function test_recompute_command_rebuilds_snapshots(): void
    {
        $customer = Customer::create(['customer_code' => 'C-RC', 'name' => 'Recompute Rae', 'phone' => '01700000002', 'is_active' => true, 'branch_id' => $this->branch->id]);
        $this->assertNull($customer->fresh()->riskSnapshot);

        $this->artisan('customers:recompute-risk', ['--customer' => $customer->id])->assertSuccessful();

        $this->assertNotNull($customer->fresh()->riskSnapshot);
        $this->assertSame('N/A', $customer->fresh()->riskSnapshot->grade);
    }

    public function test_default_rule_lists_d_e_and_trend_but_not_clean_a_b(): void
    {
        $this->snap('Alice Aaa', 'A');
        $this->snap('Bob Bbb', 'B', 'isolated');
        $this->snap('Cathy Ccc', 'C', 'trend');
        $this->snap('Dan Ddd', 'D');
        $this->snap('Eve Eee', 'E');

        $this->actingAs($this->admin)->get(route('reports.high-risk-customers'))
            ->assertOk()->assertSee('Cathy Ccc')->assertSee('Dan Ddd')->assertSee('Eve Eee')
            ->assertDontSee('Alice Aaa')->assertDontSee('Bob Bbb');
    }

    public function test_filters_work(): void
    {
        $other = Location::create(['code' => 'BR-HR2', 'name' => 'Second Branch', 'type' => 'branch', 'is_active' => true]);
        $guarantor = Customer::create(['customer_code' => 'G-1', 'name' => 'Guarantor Gus', 'phone' => '01700000003', 'is_active' => true]);

        $this->snap('Branchy Bea', 'D', 'none', [], $other);
        $this->snap('Gradey Gil', 'E');
        $this->snap('Trendy Tom', 'C', 'trend');
        $this->snap('Overdue Ola', 'D', 'none', ['overdue_amount' => 900]);
        $this->snap('Late Lou', 'D', 'none', ['max_days_late' => 80]);
        $this->snap('Guaranteed Gwen', 'D', 'none', ['has_guarantor' => true], null, ['default_guarantor_id' => $guarantor->id]);
        $get = fn (array $q) => $this->actingAs($this->admin)->get(route('reports.high-risk-customers', $q));

        $get(['branch_id' => $other->id])->assertSee('Branchy Bea')->assertDontSee('Gradey Gil');
        $get(['grade' => ['E']])->assertSee('Gradey Gil')->assertDontSee('Trendy Tom')->assertDontSee('Branchy Bea');
        $get(['classification' => 'trend'])->assertSee('Trendy Tom')->assertDontSee('Gradey Gil');
        $get(['overdue_min' => 500, 'overdue_max' => 1000])->assertSee('Overdue Ola')->assertDontSee('Gradey Gil');
        $get(['min_days_late' => 60])->assertSee('Late Lou')->assertDontSee('Gradey Gil');
        $get(['guarantor' => 'Gus'])->assertSee('Guaranteed Gwen')->assertDontSee('Gradey Gil');
        $get(['has_guarantor' => 'yes'])->assertSee('Guaranteed Gwen')->assertDontSee('Gradey Gil');
    }

    public function test_sorting_by_overdue_amount(): void
    {
        $this->snap('Small Sid', 'D', 'none', ['overdue_amount' => 10]);
        $this->snap('Big Bert', 'D', 'none', ['overdue_amount' => 9999]);

        $this->actingAs($this->admin)->get(route('reports.high-risk-customers', ['sort' => 'overdue_amount', 'dir' => 'desc']))->assertSeeInOrder(['Big Bert', 'Small Sid']);
        $this->actingAs($this->admin)->get(route('reports.high-risk-customers', ['sort' => 'overdue_amount', 'dir' => 'asc']))->assertSeeInOrder(['Small Sid', 'Big Bert']);
    }

    public function test_permission_required_and_outputs_work(): void
    {
        $this->snap('Output Olly', 'E');

        $denied = $this->viewer(['reports.view', 'reports.export', 'reports.sales.view']);
        $this->actingAs($denied)->get(route('reports.high-risk-customers'))->assertForbidden();

        $this->actingAs($this->admin)->get(route('reports.print', ['slug' => 'high-risk-customers']))->assertOk()->assertSee('Output Olly');
        $this->actingAs($this->admin)->get(route('reports.export', ['slug' => 'high-risk-customers', 'format' => 'xlsx']))->assertOk()->assertDownload();
        $this->actingAs($this->admin)->get(route('reports.export', ['slug' => 'high-risk-customers', 'format' => 'pdf']))
            ->assertOk()->assertHeader('content-type', 'application/pdf');
        $this->actingAs($this->admin)->get(route('reports.export', ['slug' => 'customer-dues', 'format' => 'pdf']))->assertStatus(302);
    }

    public function test_hub_card_visible_only_with_permission(): void
    {
        $this->actingAs($this->admin)->get(route('reports.index'))->assertOk()->assertSee('High-Risk Customers');

        $without = $this->viewer(['reports.view', 'reports.sales.view']);
        $this->actingAs($without)->get(route('reports.index'))->assertOk()->assertDontSee('High-Risk Customers');
    }
}
