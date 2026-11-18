<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\InstallmentPaymentAllocation;
use App\Models\InstallmentPlan;
use App\Models\InstallmentSchedule;
use App\Models\Location;
use App\Models\Payment;
use App\Models\SalesInvoice;
use App\Models\User;
use App\Services\ReportDataService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Tests\TestCase;

class InstallmentDurationReportTest extends TestCase
{
    use RefreshDatabase;

    public function test_completed_plan_reports_planned_and_actual_duration_with_variance(): void
    {
        [$admin, $branch, $customer] = $this->seedBranchAndAdmin();

        // Schedule spans 2026-01-10 to 2026-02-10 (31 days planned).
        // Paid off on 2026-02-20 (41 days actual) -> +10 day variance (overran).
        $plan = $this->createPlan($admin, $branch, $customer, 'S-DUR-COMPLETE', 'completed', [
            ['due_date' => '2026-01-10', 'due_amount' => 500, 'paid_amount' => 500, 'status' => 'paid', 'paid_on' => '2026-01-08'],
            ['due_date' => '2026-02-10', 'due_amount' => 500, 'paid_amount' => 500, 'status' => 'paid', 'paid_on' => '2026-02-20'],
        ]);

        $plan->load(['schedules.paymentAllocations.payment']);

        $this->assertSame('2026-01-10', $plan->plannedStartDate()->toDateString());
        $this->assertSame('2026-02-10', $plan->plannedEndDate()->toDateString());
        $this->assertSame(31, $plan->plannedDurationDays());
        $this->assertSame('2026-02-20', $plan->actualCompletionDate()->toDateString());
        $this->assertSame(41, $plan->actualDurationDays());
        $this->assertSame(10, $plan->durationVarianceDays());
    }

    public function test_active_plan_has_no_actual_duration_but_reports_elapsed_days(): void
    {
        [$admin, $branch, $customer] = $this->seedBranchAndAdmin();

        $plan = $this->createPlan($admin, $branch, $customer, 'S-DUR-ACTIVE', 'active', [
            ['due_date' => '2026-01-05', 'due_amount' => 500, 'paid_amount' => 0, 'status' => 'pending'],
            ['due_date' => '2026-03-05', 'due_amount' => 500, 'paid_amount' => 0, 'status' => 'pending'],
        ]);

        $plan->load(['schedules.paymentAllocations.payment']);

        $this->assertSame(59, $plan->plannedDurationDays());
        $this->assertNull($plan->actualCompletionDate());
        $this->assertNull($plan->actualDurationDays());
        $this->assertNull($plan->durationVarianceDays());
        $this->assertNotNull($plan->elapsedDaysSinceStart());
    }

    public function test_totals_average_across_filtered_plans_and_count_overruns(): void
    {
        [$admin, $branch, $customer] = $this->seedBranchAndAdmin();

        $this->createPlan($admin, $branch, $customer, 'S-DUR-COMPLETE', 'completed', [
            ['due_date' => '2026-01-10', 'due_amount' => 500, 'paid_amount' => 500, 'status' => 'paid', 'paid_on' => '2026-01-08'],
            ['due_date' => '2026-02-10', 'due_amount' => 500, 'paid_amount' => 500, 'status' => 'paid', 'paid_on' => '2026-02-20'],
        ]);

        $this->createPlan($admin, $branch, $customer, 'S-DUR-ACTIVE', 'active', [
            ['due_date' => '2026-01-05', 'due_amount' => 500, 'paid_amount' => 0, 'status' => 'pending'],
            ['due_date' => '2026-03-05', 'due_amount' => 500, 'paid_amount' => 0, 'status' => 'pending'],
        ]);

        $service = app(ReportDataService::class);
        $filters = $service->installmentDurationFilters(Request::create('/', 'GET'));
        $totals = $service->installmentDurationTotals($filters);

        $this->assertSame(2, $totals['plans']);
        $this->assertSame(1, $totals['active']);
        $this->assertSame(1, $totals['completed']);
        $this->assertSame(1, $totals['overran_count']);
        $this->assertSame(41.0, $totals['avg_actual_days']);
        $this->assertSame(10.0, $totals['avg_variance_days']);
    }

    public function test_admin_can_view_print_and_export_installment_duration_report(): void
    {
        [$admin, $branch, $customer] = $this->seedBranchAndAdmin();

        $this->createPlan($admin, $branch, $customer, 'S-DUR-COMPLETE', 'completed', [
            ['due_date' => '2026-01-10', 'due_amount' => 500, 'paid_amount' => 500, 'status' => 'paid', 'paid_on' => '2026-01-08'],
            ['due_date' => '2026-02-10', 'due_amount' => 500, 'paid_amount' => 500, 'status' => 'paid', 'paid_on' => '2026-02-20'],
        ]);

        $page = $this->actingAs($admin)->get(route('reports.installment-duration'));
        $page->assertOk();
        $page->assertSee('S-DUR-COMPLETE', false);
        $page->assertSee(route('reports.print', ['slug' => 'installment-duration']), false);
        $page->assertSee(route('reports.export', ['slug' => 'installment-duration', 'format' => 'xlsx']), false);

        $html = $this->actingAs($admin)->get(route('reports.print', ['slug' => 'installment-duration']));
        $html->assertOk();
        $html->assertSee('S-DUR-COMPLETE', false);

        $xlsx = $this->actingAs($admin)->get(route('reports.export', [
            'slug' => 'installment-duration',
            'format' => 'xlsx',
        ]));
        $xlsx->assertOk();
        $ct = (string) $xlsx->headers->get('content-type');
        $this->assertTrue(
            str_contains($ct, 'spreadsheet') || str_contains($ct, 'octet-stream'),
            'Unexpected content-type: '.$ct
        );
        $binary = $xlsx->baseResponse;
        $this->assertInstanceOf(BinaryFileResponse::class, $binary);
        $this->assertGreaterThan(500, (int) filesize($binary->getFile()->getPathname()));
    }

    /**
     * @return array{0: User, 1: Location, 2: Customer}
     */
    private function seedBranchAndAdmin(): array
    {
        $branch = Location::query()->firstOrCreate(
            ['code' => 'BR-DUR'],
            ['name' => 'Duration Branch', 'type' => 'branch', 'is_active' => true],
        );

        $admin = User::query()->where('email', 'like', 'dur-admin%')->first();
        if (! $admin) {
            $admin = User::factory()->create([
                'role' => 'admin',
                'is_active' => true,
                'branch_id' => $branch->id,
                'email' => 'dur-admin@example.com',
            ]);
        }

        $customer = Customer::query()->firstOrCreate(
            ['customer_code' => 'C-DUR'],
            ['name' => 'Duration Customer', 'phone' => '01700005555', 'is_active' => true],
        );

        return [$admin, $branch, $customer];
    }

    /**
     * @param  list<array{due_date: string, due_amount: float, paid_amount: float, status: string, paid_on?: string}>  $scheduleRows
     */
    private function createPlan(User $admin, Location $branch, Customer $customer, string $invoiceNumber, string $status, array $scheduleRows): InstallmentPlan
    {
        $total = array_sum(array_column($scheduleRows, 'due_amount'));

        $invoice = SalesInvoice::create([
            'invoice_number' => $invoiceNumber,
            'branch_id' => $branch->id,
            'customer_id' => $customer->id,
            'invoice_date' => $scheduleRows[0]['due_date'],
            'sale_type' => 'installment',
            'status' => $status === 'completed' ? 'active' : 'active',
            'payment_status' => $status === 'completed' ? 'paid' : 'partial',
            'subtotal' => $total,
            'discount_total' => 0,
            'tax_total' => 0,
            'grand_total' => $total,
            'amount_paid' => $status === 'completed' ? $total : 0,
            'amount_due' => $status === 'completed' ? 0 : $total,
            'installment_count' => count($scheduleRows),
            'installment_first_due_date' => $scheduleRows[0]['due_date'],
            'installment_frequency' => 'monthly',
            'created_by' => $admin->id,
        ]);

        $plan = InstallmentPlan::create([
            'sales_invoice_id' => $invoice->id,
            'customer_id' => $customer->id,
            'total_amount' => $total,
            'down_payment' => 0,
            'financed_amount' => $total,
            'remaining_amount' => $status === 'completed' ? 0 : $total,
            'penalty_enabled' => false,
            'penalty_rate' => 0,
            'status' => $status,
            'created_by' => $admin->id,
        ]);

        foreach ($scheduleRows as $i => $row) {
            $schedule = InstallmentSchedule::create([
                'installment_plan_id' => $plan->id,
                'installment_no' => $i + 1,
                'due_date' => $row['due_date'],
                'due_amount' => $row['due_amount'],
                'paid_amount' => $row['paid_amount'],
                'status' => $row['status'],
            ]);

            if (isset($row['paid_on'])) {
                $payment = Payment::create([
                    'payment_number' => 'PAY-'.$invoiceNumber.'-'.($i + 1),
                    'customer_id' => $customer->id,
                    'payment_date' => $row['paid_on'],
                    'payment_method' => 'cash',
                    'amount' => $row['paid_amount'],
                    'direction' => 'inbound',
                    'recorded_by' => $admin->id,
                ]);

                InstallmentPaymentAllocation::create([
                    'installment_schedule_id' => $schedule->id,
                    'payment_id' => $payment->id,
                    'allocated_amount' => $row['paid_amount'],
                ]);
            }
        }

        return $plan;
    }
}
