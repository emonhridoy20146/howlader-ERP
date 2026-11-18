<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\InstallmentPlan;
use App\Models\InstallmentSchedule;
use App\Models\Location;
use App\Models\SalesInvoice;
use App\Models\User;
use App\Services\ReportDataService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CreditOverdueReceivablesTest extends TestCase
{
    use RefreshDatabase;

    public function test_matured_plan_is_overdue_even_when_last_installment_was_paid(): void
    {
        [$user, $branch, $customer] = $this->seedBranchAndAdmin();

        $invoice = $this->createInstallmentInvoice($user, $branch, $customer, 'S-CO-MATURED-PAID-LAST', [
            ['due_date' => now()->subMonths(3)->toDateString(), 'due_amount' => 500, 'paid_amount' => 0, 'status' => 'overdue'],
            ['due_date' => now()->subMonth()->toDateString(), 'due_amount' => 500, 'paid_amount' => 500, 'status' => 'paid'],
        ], invoiceAmountPaid: 500, invoiceAmountDue: 500, paymentStatus: 'partial');

        $service = app(ReportDataService::class);
        $filters = ['q' => '', 'branch_id' => (string) $branch->id];

        $maturedNumbers = $service->creditOverdueMaturedInstallmentsBase($filters)->pluck('invoice_number')->all();
        $this->assertContains('S-CO-MATURED-PAID-LAST', $maturedNumbers);

        $kpis = $service->creditOverdueKpisForFilters($filters);
        $this->assertSame(1, $kpis['matured_invoices']);
        $this->assertSame(500.0, $kpis['matured_amount_due']);

        $page = $this->actingAs($user)->get(route('reports.credit-overdue', ['branch_id' => $branch->id]));
        $page->assertOk();
        $page->assertSee('S-CO-MATURED-PAID-LAST', false);
        $page->assertSee('Matured installment plans', false);
        $page->assertSee($branch->name, false);

        $html = $this->actingAs($user)->get(route('reports.print', [
            'slug' => 'credit-overdue',
            'branch_id' => $branch->id,
        ]));
        $html->assertOk();
        $html->assertSee('S-CO-MATURED-PAID-LAST', false);
        $html->assertSee($branch->name, false);
    }

    public function test_section_filter_limits_visible_receivable_types(): void
    {
        [$user, $branch, $customer] = $this->seedBranchAndAdmin();

        SalesInvoice::create([
            'invoice_number' => 'S-CO-CRED-FILTER',
            'branch_id' => $branch->id,
            'customer_id' => $customer->id,
            'invoice_date' => now()->subMonths(2)->toDateString(),
            'sale_type' => 'credit',
            'status' => 'posted',
            'payment_status' => 'unpaid',
            'subtotal' => 1000,
            'discount_total' => 0,
            'tax_total' => 0,
            'grand_total' => 1000,
            'amount_paid' => 0,
            'amount_due' => 1000,
            'credit_due_date' => now()->subDays(20)->toDateString(),
            'created_by' => $user->id,
        ]);

        $this->createInstallmentInvoice($user, $branch, $customer, 'S-CO-MAT-FILTER', [
            ['due_date' => now()->subMonths(2)->toDateString(), 'due_amount' => 400, 'paid_amount' => 0, 'status' => 'overdue'],
            ['due_date' => now()->subMonth()->toDateString(), 'due_amount' => 400, 'paid_amount' => 0, 'status' => 'overdue'],
        ]);

        $maturedOnly = $this->actingAs($user)->get(route('reports.credit-overdue', [
            'branch_id' => $branch->id,
            'section' => 'matured',
        ]));
        $maturedOnly->assertOk();
        $maturedOnly->assertSee('S-CO-MAT-FILTER', false);
        $maturedOnly->assertDontSee('S-CO-CRED-FILTER', false);

        $creditOnly = $this->actingAs($user)->get(route('reports.credit-overdue', [
            'branch_id' => $branch->id,
            'section' => 'credit',
        ]));
        $creditOnly->assertOk();
        $creditOnly->assertSee('S-CO-CRED-FILTER', false);
        $creditOnly->assertDontSee('S-CO-MAT-FILTER', false);
    }

    public function test_installment_overdue_includes_past_due_pending_schedules_not_only_overdue_status(): void
    {
        [$user, $branch, $customer] = $this->seedBranchAndAdmin();

        $this->createInstallmentInvoice($user, $branch, $customer, 'S-CO-PENDING-LATE', [
            ['due_date' => now()->subDays(10)->toDateString(), 'due_amount' => 300, 'paid_amount' => 0, 'status' => 'pending'],
            ['due_date' => now()->addMonth()->toDateString(), 'due_amount' => 300, 'paid_amount' => 0, 'status' => 'pending'],
        ]);

        $service = app(ReportDataService::class);
        $filters = ['q' => '', 'branch_id' => (string) $branch->id];
        $scheduleCount = $service->installmentOverdueBaseQuery($filters)->count();

        $this->assertSame(1, $scheduleCount);
    }

    /**
     * @return array{0: User, 1: Location, 2: Customer}
     */
    private function seedBranchAndAdmin(): array
    {
        $branch = Location::create([
            'code' => 'BR-CO-'.uniqid(),
            'name' => 'Credit Overdue Branch',
            'type' => 'branch',
            'is_active' => true,
        ]);

        $user = User::factory()->create([
            'role' => 'admin',
            'is_active' => true,
            'branch_id' => $branch->id,
        ]);

        $customer = Customer::create([
            'customer_code' => 'C-CO-'.uniqid(),
            'name' => 'Credit Overdue Customer',
            'phone' => '01700006666',
            'is_active' => true,
        ]);

        return [$user, $branch, $customer];
    }

    /**
     * @param  list<array{due_date: string, due_amount: float, paid_amount: float, status: string}>  $scheduleRows
     */
    private function createInstallmentInvoice(
        User $user,
        Location $branch,
        Customer $customer,
        string $invoiceNumber,
        array $scheduleRows,
        string $planStatus = 'active',
        float $invoiceAmountPaid = 0,
        ?float $invoiceAmountDue = null,
        string $paymentStatus = 'unpaid',
    ): SalesInvoice {
        $total = array_sum(array_column($scheduleRows, 'due_amount'));
        $invoiceAmountDue ??= $total;

        $invoice = SalesInvoice::create([
            'invoice_number' => $invoiceNumber,
            'branch_id' => $branch->id,
            'customer_id' => $customer->id,
            'invoice_date' => now()->subMonths(4)->toDateString(),
            'sale_type' => 'installment',
            'status' => 'active',
            'payment_status' => $paymentStatus,
            'subtotal' => $total,
            'discount_total' => 0,
            'tax_total' => 0,
            'grand_total' => $total,
            'amount_paid' => $invoiceAmountPaid,
            'amount_due' => $invoiceAmountDue,
            'installment_count' => count($scheduleRows),
            'installment_first_due_date' => $scheduleRows[0]['due_date'],
            'installment_frequency' => 'monthly',
            'created_by' => $user->id,
        ]);

        $plan = InstallmentPlan::create([
            'sales_invoice_id' => $invoice->id,
            'customer_id' => $customer->id,
            'total_amount' => $total,
            'down_payment' => 0,
            'financed_amount' => $total,
            'remaining_amount' => round($total - $invoiceAmountPaid, 2),
            'penalty_enabled' => false,
            'penalty_rate' => 0,
            'status' => $planStatus,
            'created_by' => $user->id,
        ]);

        foreach ($scheduleRows as $i => $row) {
            InstallmentSchedule::create([
                'installment_plan_id' => $plan->id,
                'installment_no' => $i + 1,
                'due_date' => $row['due_date'],
                'due_amount' => $row['due_amount'],
                'paid_amount' => $row['paid_amount'],
                'status' => $row['status'],
            ]);
        }

        return $invoice;
    }
}
