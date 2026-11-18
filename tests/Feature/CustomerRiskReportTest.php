<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\InstallmentPaymentAllocation;
use App\Models\InstallmentPlan;
use App\Models\InstallmentSchedule;
use App\Models\Location;
use App\Models\Payment;
use App\Models\Permission;
use App\Models\Role;
use App\Models\SalesInvoice;
use App\Models\User;
use App\Services\CustomerRiskService;
use App\Services\PermissionSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class CustomerRiskReportTest extends TestCase
{
    use RefreshDatabase;

    private Location $branch;

    private Customer $customer;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionSyncService::class)->syncFromPolicies();

        $this->branch = Location::create(['code' => 'BR-RISK', 'name' => 'Risk Branch', 'type' => 'branch', 'is_active' => true]);
        $this->admin = User::factory()->create(['role' => 'admin', 'is_active' => true, 'branch_id' => $this->branch->id]);
        $this->customer = Customer::create([
            'customer_code' => 'C-RISK-1', 'name' => 'Risky Rahim', 'phone' => '01711111111',
            'is_active' => true, 'branch_id' => $this->branch->id,
        ]);
    }

    /** @return array{0: SalesInvoice, 1: list<InstallmentSchedule>} */
    private function plan(string $number, array $rows, string $status = 'active'): array
    {
        $total = array_sum(array_column($rows, 'due'));
        $invoice = SalesInvoice::create([
            'invoice_number' => $number, 'branch_id' => $this->branch->id, 'customer_id' => $this->customer->id,
            'invoice_date' => now()->subMonths(8)->toDateString(), 'sale_type' => 'installment', 'status' => 'posted',
            'payment_status' => 'partial', 'subtotal' => $total, 'discount_total' => 0, 'tax_total' => 0, 'grand_total' => $total,
            'amount_paid' => 0, 'amount_due' => $total, 'created_by' => $this->admin->id,
        ]);
        $plan = InstallmentPlan::create([
            'sales_invoice_id' => $invoice->id, 'customer_id' => $this->customer->id, 'total_amount' => $total,
            'down_payment' => 0, 'financed_amount' => $total, 'remaining_amount' => $total, 'penalty_enabled' => false,
            'penalty_rate' => 0, 'status' => $status, 'created_by' => $this->admin->id,
        ]);

        $schedules = [];
        foreach ($rows as $i => $row) {
            $paid = $row['paid_on'] ?? null;
            $schedule = InstallmentSchedule::create([
                'installment_plan_id' => $plan->id, 'installment_no' => $i + 1, 'due_date' => $row['due_date'],
                'due_amount' => $row['due'], 'paid_amount' => $paid ? $row['due'] : ($row['partial'] ?? 0), 'status' => $paid ? 'paid' : 'pending',
            ]);
            foreach ($row['payments'] ?? ($paid ? [[$paid, $row['due']]] : []) as $k => [$date, $amount]) {
                $payment = Payment::create([
                    'payment_number' => 'P-'.$number.'-'.$i.'-'.$k, 'customer_id' => $this->customer->id, 'payment_date' => $date,
                    'payment_method' => 'cash', 'amount' => $amount, 'direction' => 'inbound', 'recorded_by' => $this->admin->id,
                ]);
                InstallmentPaymentAllocation::create(['installment_schedule_id' => $schedule->id, 'payment_id' => $payment->id, 'allocated_amount' => $amount]);
            }
            $schedules[] = $schedule;
        }

        return [$invoice, $schedules];
    }

    private function risk(): array
    {
        return app(CustomerRiskService::class)->build($this->customer->fresh(), Carbon::today());
    }

    public function test_paid_date_is_the_settling_payment_with_partial_payments(): void
    {
        $due = now()->subMonths(2)->startOfDay();
        $this->plan('S-PART', [[
            'due_date' => $due->toDateString(), 'due' => 1000, 'paid_on' => $due->copy()->addDays(10)->toDateString(),
            'payments' => [[$due->copy()->addDays(1)->toDateString(), 400], [$due->copy()->addDays(10)->toDateString(), 600]],
        ]]);

        $row = $this->risk()['installments'][0];

        $this->assertSame(10, $row['days_late']);
        $this->assertTrue($row['is_late']);
        $this->assertSame($due->copy()->addDays(10)->toDateString(), $row['paid_date']->toDateString());
    }

    public function test_unpaid_overdue_counts_to_today_and_future_due_is_not_scored(): void
    {
        $this->plan('S-OPEN', [
            ['due_date' => now()->subDays(20)->toDateString(), 'due' => 500],
            ['due_date' => now()->addDays(20)->toDateString(), 'due' => 500],
        ]);

        $r = $this->risk();

        $this->assertSame(20, $r['installments'][0]['days_late']);
        $this->assertSame(0, $r['installments'][1]['days_late']);
        $this->assertSame(1, $r['summary']['matured_count']);
        $this->assertSame(500.0, $r['summary']['overdue_amount']);
        $this->assertSame('overdue', $r['status']);
    }

    public function test_perfect_payer_gets_grade_a_and_is_eligible(): void
    {
        $rows = [];
        foreach (range(6, 1) as $m) {
            $d = now()->subMonths($m)->toDateString();
            $rows[] = ['due_date' => $d, 'due' => 1000, 'paid_on' => $d];
        }
        $this->plan('S-PERFECT', $rows, 'completed');

        $r = $this->risk();

        $this->assertSame('A', $r['grade']);
        $this->assertSame('none', $r['trend']['classification']);
        $this->assertTrue($r['recommendation']['eligible']);
        $this->assertGreaterThan(0, $r['recommendation']['max_amount']);
    }

    public function test_defaulted_plan_is_grade_e_and_not_eligible(): void
    {
        $this->plan('S-DEF', [
            ['due_date' => now()->subMonths(3)->toDateString(), 'due' => 500],
            ['due_date' => now()->subMonths(2)->toDateString(), 'due' => 500],
        ], 'defaulted');

        $r = $this->risk();

        $this->assertSame('defaulted', $r['status']);
        $this->assertSame('E', $r['grade']);
        $this->assertFalse($r['recommendation']['eligible']);
    }

    public function test_customer_without_history_is_na(): void
    {
        $r = $this->risk();

        $this->assertSame('N/A', $r['grade']);
        $this->assertNull($r['score']['total']);
    }

    public function test_authorised_user_can_open_page_print_pdf_and_excel(): void
    {
        $due = now()->subMonths(2)->toDateString();
        $this->plan('S-VIEW', [['due_date' => $due, 'due' => 700, 'paid_on' => $due]]);

        $this->actingAs($this->admin)->get(route('customers.risk', $this->customer))
            ->assertOk()->assertSee('S-VIEW', false)->assertSee('Risk analysis', false);
        $this->actingAs($this->admin)->get(route('customers.risk.print', $this->customer))
            ->assertOk()->assertSee('S-VIEW', false);
        $this->actingAs($this->admin)->get(route('customers.risk.pdf', $this->customer))
            ->assertOk()->assertHeader('content-type', 'application/pdf');
        $this->actingAs($this->admin)->get(route('customers.risk.excel', $this->customer))
            ->assertOk()->assertDownload();
    }

    public function test_user_without_permission_gets_403_and_other_branch_user_is_blocked(): void
    {
        $viewer = $this->userWithPermissions(['customer.view-any', 'customer.view', 'reports.view'], [$this->branch->id]);
        $this->actingAs($viewer)->get(route('customers.risk', $this->customer))->assertForbidden();

        $other = Location::create(['code' => 'BR-OTHER', 'name' => 'Other', 'type' => 'branch', 'is_active' => true]);
        $outsider = $this->userWithPermissions(['customer.view-any', 'customer.view', 'reports.view', 'reports.customer_risk.view'], [$other->id]);
        $this->actingAs($outsider)->get(route('customers.risk', $this->customer))->assertForbidden();

        $insider = $this->userWithPermissions(['customer.view-any', 'customer.view', 'reports.view', 'reports.customer_risk.view'], [$this->branch->id]);
        $this->actingAs($insider)->get(route('customers.risk', $this->customer))->assertOk();
    }

    public function test_reports_hub_shows_card_only_with_permission(): void
    {
        $this->actingAs($this->admin)->get(route('reports.index'))->assertOk()->assertSee('Customer Profile &amp; Risk', false);

        $without = $this->userWithPermissions(['reports.view', 'reports.sales.view'], [$this->branch->id]);
        $this->actingAs($without)->get(route('reports.index'))->assertOk()->assertDontSee('Customer Profile &amp; Risk', false);

        $with = $this->userWithPermissions(['reports.view', 'reports.customer_risk.view'], [$this->branch->id]);
        $this->actingAs($with)->get(route('reports.index'))->assertOk()->assertSee('Customer Profile &amp; Risk', false);
    }

    /** @param list<string> $slugs @param list<int> $locationIds */
    private function userWithPermissions(array $slugs, array $locationIds): User
    {
        $user = User::factory()->create(['is_active' => true, 'branch_id' => $locationIds[0]]);
        $user->accessibleLocations()->sync($locationIds);
        $role = Role::query()->create(['name' => 'R '.uniqid(), 'slug' => 'r-'.uniqid()]);
        $role->permissions()->sync(Permission::query()->whereIn('slug', $slugs)->pluck('id')->all());
        $user->roles()->sync([$role->id]);

        return $user;
    }
}
