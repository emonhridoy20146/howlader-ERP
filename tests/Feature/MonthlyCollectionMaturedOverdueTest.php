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
use Illuminate\Support\Carbon;
use Tests\TestCase;

class MonthlyCollectionMaturedOverdueTest extends TestCase
{
    use RefreshDatabase;

    public function test_matured_overdue_only_includes_plans_whose_final_installment_has_passed(): void
    {
        [$user, $branch, $customer] = $this->seedBranchAndAdmin();

        // Invoice A: first installment overdue, but the LAST installment is not
        // yet due — the plan hasn't matured, so this must NOT appear as matured overdue
        // (it still shows up in the plain "overdue installments" schedule-level set).
        $partiallyLate = $this->createInstallmentInvoice($user, $branch, $customer, 'S-MAT-PARTIAL', [
            ['due_date' => now()->subMonths(3)->toDateString(), 'due_amount' => 500, 'paid_amount' => 0, 'status' => 'overdue'],
            ['due_date' => now()->addMonth()->toDateString(), 'due_amount' => 500, 'paid_amount' => 0, 'status' => 'pending'],
        ]);

        // Invoice B: BOTH installments (including the last) are overdue — the whole
        // plan has matured and is still unpaid.
        $fullyMatured = $this->createInstallmentInvoice($user, $branch, $customer, 'S-MAT-FULL', [
            ['due_date' => now()->subMonths(3)->toDateString(), 'due_amount' => 500, 'paid_amount' => 0, 'status' => 'overdue'],
            ['due_date' => now()->subMonths(2)->toDateString(), 'due_amount' => 500, 'paid_amount' => 0, 'status' => 'overdue'],
        ]);

        // Invoice C: matured (last due date passed) but fully paid off — must be excluded.
        $maturedButPaid = $this->createInstallmentInvoice($user, $branch, $customer, 'S-MAT-PAID', [
            ['due_date' => now()->subMonths(3)->toDateString(), 'due_amount' => 500, 'paid_amount' => 500, 'status' => 'paid'],
            ['due_date' => now()->subMonths(2)->toDateString(), 'due_amount' => 500, 'paid_amount' => 500, 'status' => 'paid'],
        ], planStatus: 'completed', invoiceAmountPaid: 1000, invoiceAmountDue: 0, paymentStatus: 'paid');

        $service = app(ReportDataService::class);
        $request = request()->duplicate(['branch_id' => (string) $branch->id]);

        $data = $service->monthlyCollectionPaginated($request);
        $maturedNumbers = $data['maturedOverdueInstallments']->pluck('invoice_number')->all();

        $this->assertNotContains('S-MAT-PARTIAL', $maturedNumbers);
        $this->assertContains('S-MAT-FULL', $maturedNumbers);
        $this->assertNotContains('S-MAT-PAID', $maturedNumbers);

        // The partially-late plan's first schedule still shows up in the plain
        // schedule-level overdue set.
        $overdueInvoiceIds = $data['overdueInstallments']->pluck('invoice.id')->filter()->all();
        $this->assertContains($partiallyLate->id, $overdueInvoiceIds);
        $this->assertContains($fullyMatured->id, $overdueInvoiceIds);

        $totals = $service->monthlyCollectionTotals($request);
        $this->assertSame(1000.0, $totals['matured_overdue_due']);
        $this->assertSame(1, $totals['matured_overdue_invoices']);
        $this->assertSame(0.0, $totals['matured_overdue_paid']);

        // finalInstallmentDueDate() / installmentMaturityMonthsLate() on the model.
        $fullyMatured->load('installmentPlan.schedules');
        $this->assertSame(now()->subMonths(2)->toDateString(), $fullyMatured->finalInstallmentDueDate()->toDateString());
        $this->assertGreaterThanOrEqual(1, $fullyMatured->installmentMaturityMonthsLate());

        $partiallyLate->load('installmentPlan.schedules');
        $this->assertSame(0, $partiallyLate->installmentMaturityMonthsLate());
    }

    public function test_admin_can_view_print_and_export_the_matured_overdue_table(): void
    {
        [$user, $branch, $customer] = $this->seedBranchAndAdmin();

        $this->createInstallmentInvoice($user, $branch, $customer, 'S-MAT-PRINT', [
            ['due_date' => now()->subMonths(3)->toDateString(), 'due_amount' => 400, 'paid_amount' => 0, 'status' => 'overdue'],
            ['due_date' => now()->subMonths(2)->toDateString(), 'due_amount' => 600, 'paid_amount' => 0, 'status' => 'overdue'],
        ]);

        $page = $this->actingAs($user)->get(route('reports.monthly-collection', ['branch_id' => $branch->id]));
        $page->assertOk();
        $page->assertSee('S-MAT-PRINT', false);
        $page->assertSee('Overdue (matured plans)', false);

        $html = $this->actingAs($user)->get(route('reports.print', [
            'slug' => 'monthly-collection',
            'branch_id' => $branch->id,
        ]));
        $html->assertOk();
        $html->assertSee('S-MAT-PRINT', false);
        $html->assertSee('Overdue (matured plans)', false);
        $html->assertSee('Overdue (matured) subtotal', false);

        $xlsx = $this->actingAs($user)->get(route('reports.export', [
            'slug' => 'monthly-collection',
            'format' => 'xlsx',
            'branch_id' => $branch->id,
        ]));
        $xlsx->assertOk();
    }

    /**
     * @return array{0: User, 1: Location, 2: Customer}
     */
    private function seedBranchAndAdmin(): array
    {
        $branch = Location::create([
            'code' => 'BR-MAT-'.uniqid(),
            'name' => 'Matured Overdue Branch',
            'type' => 'branch',
            'is_active' => true,
        ]);

        $user = User::factory()->create([
            'role' => 'admin',
            'is_active' => true,
            'branch_id' => $branch->id,
        ]);

        $customer = Customer::create([
            'customer_code' => 'C-MAT-'.uniqid(),
            'name' => 'Matured Overdue Customer',
            'phone' => '01700007777',
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
