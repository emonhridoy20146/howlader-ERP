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

class MonthlyCollectionGroupingTest extends TestCase
{
    use RefreshDatabase;

    public function test_grouping_collapses_multiple_schedules_into_one_invoice_row(): void
    {
        $branch = Location::create([
            'code' => 'BR-MCG',
            'name' => 'Monthly Collection Branch',
            'type' => 'branch',
            'is_active' => true,
        ]);

        $user = User::factory()->create([
            'role' => 'admin',
            'is_active' => true,
            'branch_id' => $branch->id,
        ]);

        $customer = Customer::create([
            'customer_code' => 'C-MCG',
            'name' => 'Grouped Customer',
            'phone' => '01700005555',
            'is_active' => true,
        ]);

        $invoice = SalesInvoice::create([
            'invoice_number' => 'S-GROUP-1',
            'branch_id' => $branch->id,
            'customer_id' => $customer->id,
            'invoice_date' => now()->subYear()->toDateString(),
            'sale_type' => 'installment',
            'status' => 'active',
            'payment_status' => 'partial',
            'subtotal' => 30000,
            'discount_total' => 0,
            'tax_total' => 0,
            'grand_total' => 30000,
            'amount_paid' => 10000,
            'amount_due' => 20000,
            'created_by' => $user->id,
        ]);

        $plan = InstallmentPlan::create([
            'sales_invoice_id' => $invoice->id,
            'customer_id' => $customer->id,
            'total_amount' => 30000,
            'down_payment' => 0,
            'financed_amount' => 30000,
            'remaining_amount' => 20000,
            'penalty_enabled' => false,
            'penalty_rate' => 0,
            'status' => 'active',
            'created_by' => $user->id,
        ]);

        foreach ([1, 2, 3] as $no) {
            InstallmentSchedule::create([
                'installment_plan_id' => $plan->id,
                'installment_no' => $no,
                'due_date' => now()->subMonths(4 - $no)->toDateString(),
                'due_amount' => 10000,
                'paid_amount' => $no === 1 ? 10000 : 0,
                'late_fee_amount' => 0,
                'status' => $no === 1 ? 'paid' : 'overdue',
            ]);
        }

        $schedules = InstallmentSchedule::query()->with('installmentPlan.salesInvoice.branch')->get();
        $grouped = app(ReportDataService::class)->groupMonthlyCollectionInstallmentSchedules($schedules);

        $this->assertCount(1, $grouped);
        $row = $grouped->first();
        $this->assertSame('S-GROUP-1', $row->invoice?->invoice_number);
        $this->assertSame('1, 2, 3', $row->installment_nos);
        $this->assertSame(20000.0, (float) $row->remaining);
    }

    public function test_grouping_orders_rows_by_invoice_date(): void
    {
        $branch = Location::create([
            'code' => 'BR-MCG2',
            'name' => 'Monthly Collection Branch 2',
            'type' => 'branch',
            'is_active' => true,
        ]);

        $user = User::factory()->create([
            'role' => 'admin',
            'is_active' => true,
            'branch_id' => $branch->id,
        ]);

        $customer = Customer::create([
            'customer_code' => 'C-MCG2',
            'name' => 'Sort Customer',
            'phone' => '01700006666',
            'is_active' => true,
        ]);

        $olderInvoice = SalesInvoice::create([
            'invoice_number' => 'S-OLD',
            'branch_id' => $branch->id,
            'customer_id' => $customer->id,
            'invoice_date' => now()->subYears(2)->toDateString(),
            'sale_type' => 'installment',
            'status' => 'active',
            'payment_status' => 'partial',
            'subtotal' => 10000,
            'discount_total' => 0,
            'tax_total' => 0,
            'grand_total' => 10000,
            'amount_paid' => 0,
            'amount_due' => 10000,
            'created_by' => $user->id,
        ]);

        $newerInvoice = SalesInvoice::create([
            'invoice_number' => 'S-NEW',
            'branch_id' => $branch->id,
            'customer_id' => $customer->id,
            'invoice_date' => now()->subMonths(6)->toDateString(),
            'sale_type' => 'installment',
            'status' => 'active',
            'payment_status' => 'partial',
            'subtotal' => 10000,
            'discount_total' => 0,
            'tax_total' => 0,
            'grand_total' => 10000,
            'amount_paid' => 0,
            'amount_due' => 10000,
            'created_by' => $user->id,
        ]);

        foreach ([$olderInvoice, $newerInvoice] as $invoice) {
            $plan = InstallmentPlan::create([
                'sales_invoice_id' => $invoice->id,
                'customer_id' => $customer->id,
                'total_amount' => 10000,
                'down_payment' => 0,
                'financed_amount' => 10000,
                'remaining_amount' => 10000,
                'penalty_enabled' => false,
                'penalty_rate' => 0,
                'status' => 'active',
                'created_by' => $user->id,
            ]);

            InstallmentSchedule::create([
                'installment_plan_id' => $plan->id,
                'installment_no' => 1,
                'due_date' => now()->toDateString(),
                'due_amount' => 10000,
                'paid_amount' => 0,
                'late_fee_amount' => 0,
                'status' => 'pending',
            ]);
        }

        $schedules = InstallmentSchedule::query()->with('installmentPlan.salesInvoice.branch')->get();
        $grouped = app(ReportDataService::class)->groupMonthlyCollectionInstallmentSchedules($schedules);

        $this->assertSame(['S-OLD', 'S-NEW'], $grouped->pluck('invoice.invoice_number')->all());
    }

    public function test_grouping_does_not_order_by_schedule_due_date_when_sales_dates_match(): void
    {
        $branch = Location::create([
            'code' => 'BR-MCG3',
            'name' => 'Monthly Collection Branch 3',
            'type' => 'branch',
            'is_active' => true,
        ]);

        $user = User::factory()->create([
            'role' => 'admin',
            'is_active' => true,
            'branch_id' => $branch->id,
        ]);

        $customer = Customer::create([
            'customer_code' => 'C-MCG3',
            'name' => 'Due Sort Customer',
            'phone' => '01700007777',
            'is_active' => true,
        ]);

        $salesDate = now()->subYear()->toDateString();

        $firstInvoice = SalesInvoice::create([
            'invoice_number' => 'S-FIRST',
            'branch_id' => $branch->id,
            'customer_id' => $customer->id,
            'invoice_date' => $salesDate,
            'sale_type' => 'installment',
            'status' => 'active',
            'payment_status' => 'partial',
            'subtotal' => 10000,
            'discount_total' => 0,
            'tax_total' => 0,
            'grand_total' => 10000,
            'amount_paid' => 0,
            'amount_due' => 10000,
            'created_by' => $user->id,
        ]);

        $secondInvoice = SalesInvoice::create([
            'invoice_number' => 'S-SECOND',
            'branch_id' => $branch->id,
            'customer_id' => $customer->id,
            'invoice_date' => $salesDate,
            'sale_type' => 'installment',
            'status' => 'active',
            'payment_status' => 'partial',
            'subtotal' => 10000,
            'discount_total' => 0,
            'tax_total' => 0,
            'grand_total' => 10000,
            'amount_paid' => 0,
            'amount_due' => 10000,
            'created_by' => $user->id,
        ]);

        foreach ([[$firstInvoice, now()->addMonth()], [$secondInvoice, now()->subMonth()]] as [$invoice, $dueDate]) {
            $plan = InstallmentPlan::create([
                'sales_invoice_id' => $invoice->id,
                'customer_id' => $customer->id,
                'total_amount' => 10000,
                'down_payment' => 0,
                'financed_amount' => 10000,
                'remaining_amount' => 10000,
                'penalty_enabled' => false,
                'penalty_rate' => 0,
                'status' => 'active',
                'created_by' => $user->id,
            ]);

            InstallmentSchedule::create([
                'installment_plan_id' => $plan->id,
                'installment_no' => 1,
                'due_date' => $dueDate->toDateString(),
                'due_amount' => 10000,
                'paid_amount' => 0,
                'late_fee_amount' => 0,
                'status' => 'pending',
            ]);
        }

        $schedules = InstallmentSchedule::query()->with('installmentPlan.salesInvoice.branch')->get();
        $grouped = app(ReportDataService::class)->groupMonthlyCollectionInstallmentSchedules($schedules);

        $this->assertSame(['S-FIRST', 'S-SECOND'], $grouped->pluck('invoice.invoice_number')->all());
    }
}
