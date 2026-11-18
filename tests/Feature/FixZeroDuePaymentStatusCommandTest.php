<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\InstallmentPlan;
use App\Models\InstallmentSchedule;
use App\Models\Location;
use App\Models\SalesInvoice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FixZeroDuePaymentStatusCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_marks_zero_due_invoices_as_paid(): void
    {
        $location = Location::create([
            'code' => 'LOC-FZD',
            'name' => 'Fix zero due branch',
            'type' => 'branch',
            'address' => 'Test',
            'is_active' => true,
        ]);

        $user = User::factory()->create([
            'role' => 'admin',
            'is_active' => true,
            'branch_id' => $location->id,
        ]);

        $customer = Customer::create([
            'customer_code' => 'C-FZD',
            'name' => 'Fix Zero Due Customer',
            'phone' => '01701000099',
            'is_active' => true,
            'outstanding_balance' => 0,
        ]);

        $invoice = SalesInvoice::create([
            'invoice_number' => 'INV-FZD-1',
            'invoice_date' => now()->toDateString(),
            'branch_id' => $location->id,
            'customer_id' => $customer->id,
            'sale_type' => 'credit',
            'status' => 'posted',
            'subtotal' => 5000,
            'payment_status' => 'partial',
            'discount_total' => 0,
            'tax_total' => 0,
            'grand_total' => 5000,
            'amount_paid' => 5000,
            'amount_due' => 0,
            'created_by' => $user->id,
        ]);

        $this->artisan('invoices:fix-zero-due-payment-status')
            ->assertSuccessful();

        $invoice->refresh();
        $this->assertSame('paid', $invoice->payment_status);
        $this->assertFalse($invoice->isPayable());
    }

    public function test_command_refreshes_installment_plan_when_invoice_is_settled(): void
    {
        $location = Location::create([
            'code' => 'LOC-FZI',
            'name' => 'Fix installment branch',
            'type' => 'branch',
            'address' => 'Test',
            'is_active' => true,
        ]);

        $user = User::factory()->create([
            'role' => 'admin',
            'is_active' => true,
            'branch_id' => $location->id,
        ]);

        $customer = Customer::create([
            'customer_code' => 'C-FZI',
            'name' => 'Installment Customer',
            'phone' => '01701000100',
            'is_active' => true,
            'outstanding_balance' => 0,
        ]);

        $invoice = SalesInvoice::create([
            'invoice_number' => '424',
            'invoice_date' => now()->toDateString(),
            'branch_id' => $location->id,
            'customer_id' => $customer->id,
            'sale_type' => 'installment',
            'status' => 'active',
            'subtotal' => 10000,
            'payment_status' => 'unpaid',
            'discount_total' => 0,
            'tax_total' => 0,
            'grand_total' => 10000,
            'amount_paid' => 10000,
            'amount_due' => 0,
            'created_by' => $user->id,
        ]);

        $plan = InstallmentPlan::create([
            'sales_invoice_id' => $invoice->id,
            'customer_id' => $customer->id,
            'total_amount' => 10000,
            'down_payment' => 2000,
            'financed_amount' => 8000,
            'remaining_amount' => 8000,
            'penalty_enabled' => false,
            'penalty_rate' => 0,
            'status' => 'active',
            'created_by' => $user->id,
        ]);

        InstallmentSchedule::create([
            'installment_plan_id' => $plan->id,
            'installment_no' => 1,
            'due_date' => now()->subMonth()->toDateString(),
            'due_amount' => 4000,
            'paid_amount' => 4000,
            'status' => 'pending',
        ]);

        InstallmentSchedule::create([
            'installment_plan_id' => $plan->id,
            'installment_no' => 2,
            'due_date' => now()->toDateString(),
            'due_amount' => 4000,
            'paid_amount' => 4000,
            'status' => 'pending',
        ]);

        $this->artisan('invoices:fix-zero-due-payment-status', ['--invoice' => '424'])
            ->assertSuccessful();

        $invoice->refresh();
        $plan->refresh();

        $this->assertSame('paid', $invoice->payment_status);
        $this->assertSame('completed', $plan->status);
        $this->assertEquals(0.0, (float) $plan->remaining_amount);
        $this->assertSame('paid', $plan->schedules()->orderBy('installment_no')->first()->status);
    }

    public function test_command_settles_open_installment_schedules_when_invoice_already_paid(): void
    {
        $location = Location::create([
            'code' => 'LOC-FZI2',
            'name' => 'Fix installment branch 2',
            'type' => 'branch',
            'address' => 'Test',
            'is_active' => true,
        ]);

        $user = User::factory()->create([
            'role' => 'admin',
            'is_active' => true,
            'branch_id' => $location->id,
        ]);

        $customer = Customer::create([
            'customer_code' => 'C-FZI2',
            'name' => 'Installment Customer 2',
            'phone' => '01701000102',
            'is_active' => true,
        ]);

        $invoice = SalesInvoice::create([
            'invoice_number' => 'S-PAID-OPEN',
            'invoice_date' => now()->toDateString(),
            'branch_id' => $location->id,
            'customer_id' => $customer->id,
            'sale_type' => 'installment',
            'status' => 'active',
            'subtotal' => 0,
            'payment_status' => 'paid',
            'discount_total' => 0,
            'tax_total' => 0,
            'grand_total' => 0,
            'amount_paid' => 0,
            'amount_due' => 0,
            'created_by' => $user->id,
        ]);

        $plan = InstallmentPlan::create([
            'sales_invoice_id' => $invoice->id,
            'customer_id' => $customer->id,
            'total_amount' => 0,
            'down_payment' => 0,
            'financed_amount' => 3666.67,
            'remaining_amount' => 3666.67,
            'penalty_enabled' => false,
            'penalty_rate' => 0,
            'status' => 'active',
            'created_by' => $user->id,
        ]);

        $schedule = InstallmentSchedule::create([
            'installment_plan_id' => $plan->id,
            'installment_no' => 3,
            'due_date' => now()->subDay()->toDateString(),
            'due_amount' => 3666.67,
            'paid_amount' => 0,
            'status' => 'overdue',
        ]);

        $this->artisan('invoices:fix-zero-due-payment-status', ['--invoice' => (string) $invoice->id])
            ->assertSuccessful();

        $plan->refresh();
        $schedule->refresh();

        $this->assertSame('paid', $invoice->fresh()->payment_status);
        $this->assertSame('completed', $plan->status);
        $this->assertEquals(0.0, (float) $plan->remaining_amount);
        $this->assertSame('paid', $schedule->status);
        $this->assertEquals(3666.67, (float) $schedule->paid_amount);
    }

    public function test_command_does_not_mark_installment_schedules_paid_when_only_partial_cash_collected(): void
    {
        $location = Location::create([
            'code' => 'LOC-FZI3',
            'name' => 'Fix installment branch 3',
            'type' => 'branch',
            'address' => 'Test',
            'is_active' => true,
        ]);

        $user = User::factory()->create([
            'role' => 'admin',
            'is_active' => true,
            'branch_id' => $location->id,
        ]);

        $customer = Customer::create([
            'customer_code' => 'C-FZI3',
            'name' => 'Partial Installment Customer',
            'phone' => '01701000103',
            'is_active' => true,
        ]);

        $invoice = SalesInvoice::create([
            'invoice_number' => 'S-PARTIAL-INST',
            'invoice_date' => now()->toDateString(),
            'branch_id' => $location->id,
            'customer_id' => $customer->id,
            'sale_type' => 'installment',
            'status' => 'active',
            'subtotal' => 28000,
            'payment_status' => 'paid',
            'discount_total' => 0,
            'tax_total' => 0,
            'grand_total' => 28000,
            'amount_paid' => 9000,
            'amount_due' => 0,
            'installment_down_payment' => 15000,
            'created_by' => $user->id,
        ]);

        $plan = InstallmentPlan::create([
            'sales_invoice_id' => $invoice->id,
            'customer_id' => $customer->id,
            'total_amount' => 28000,
            'down_payment' => 15000,
            'financed_amount' => 13000,
            'remaining_amount' => 13000,
            'penalty_enabled' => false,
            'penalty_rate' => 0,
            'status' => 'active',
            'created_by' => $user->id,
        ]);

        InstallmentSchedule::create([
            'installment_plan_id' => $plan->id,
            'installment_no' => 1,
            'due_date' => now()->subMonth()->toDateString(),
            'due_amount' => 2166.67,
            'paid_amount' => 0,
            'status' => 'overdue',
        ]);

        $this->artisan('invoices:fix-zero-due-payment-status', ['--invoice' => 'S-PARTIAL-INST'])
            ->assertSuccessful();

        $schedule = $plan->schedules()->first();
        $this->assertEquals(0.0, (float) $schedule->paid_amount);
        $this->assertSame('overdue', $schedule->status);
        $this->assertEquals(2166.67, (float) $plan->fresh()->remaining_amount);
    }

    public function test_dry_run_leaves_invoice_unchanged(): void
    {
        $location = Location::create([
            'code' => 'LOC-FZD-DRY',
            'name' => 'Dry branch',
            'type' => 'branch',
            'address' => 'Test',
            'is_active' => true,
        ]);

        $user = User::factory()->create([
            'role' => 'admin',
            'is_active' => true,
            'branch_id' => $location->id,
        ]);

        $customer = Customer::create([
            'customer_code' => 'C-FZD-DRY',
            'name' => 'Dry Customer',
            'phone' => '01701000101',
            'is_active' => true,
        ]);

        $invoice = SalesInvoice::create([
            'invoice_number' => 'INV-FZD-DRY',
            'invoice_date' => now()->toDateString(),
            'branch_id' => $location->id,
            'customer_id' => $customer->id,
            'sale_type' => 'credit',
            'status' => 'posted',
            'subtotal' => 100,
            'payment_status' => 'unpaid',
            'discount_total' => 0,
            'tax_total' => 0,
            'grand_total' => 100,
            'amount_paid' => 100,
            'amount_due' => 0,
            'created_by' => $user->id,
        ]);

        $this->artisan('invoices:fix-zero-due-payment-status', ['--dry-run' => true])
            ->assertSuccessful();

        $this->assertSame('unpaid', $invoice->fresh()->payment_status);
    }

    public function test_command_assigns_missing_invoice_number_for_zero_due_posted_invoice(): void
    {
        $location = Location::create([
            'code' => 'LOC-FZD-NUM',
            'name' => 'Fix number branch',
            'type' => 'branch',
            'address' => 'Test',
            'is_active' => true,
        ]);

        $user = User::factory()->create([
            'role' => 'admin',
            'is_active' => true,
            'branch_id' => $location->id,
        ]);

        $customer = Customer::create([
            'customer_code' => 'C-FZD-NUM',
            'name' => 'Fix Number Customer',
            'phone' => '01701000103',
            'is_active' => true,
        ]);

        $invoice = SalesInvoice::create([
            'invoice_number' => null,
            'invoice_date' => now()->toDateString(),
            'branch_id' => $location->id,
            'customer_id' => $customer->id,
            'sale_type' => 'credit',
            'status' => 'posted',
            'subtotal' => 5000,
            'payment_status' => 'unpaid',
            'discount_total' => 5000,
            'tax_total' => 0,
            'grand_total' => 0,
            'amount_paid' => 0,
            'amount_due' => 0,
            'created_by' => $user->id,
        ]);

        $this->artisan('invoices:fix-zero-due-payment-status', ['--invoice' => (string) $invoice->id])
            ->assertSuccessful();

        $invoice->refresh();
        $this->assertSame('paid', $invoice->payment_status);
        $this->assertNotNull($invoice->invoice_number);
        $this->assertMatchesRegularExpression('/^INV-\d{5}$/', $invoice->invoice_number);
    }
}
