<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\InstallmentPlan;
use App\Models\InstallmentSchedule;
use App\Models\Location;
use App\Models\SalesInvoice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class RepairInvoiceCommandTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: SalesInvoice, 1: InstallmentPlan, 2: InstallmentSchedule} */
    private function settledInvoiceWithOpenPlan(float $paid, float $due): array
    {
        $branch = Location::create(['code' => 'BR-RP', 'name' => 'Repair Branch', 'type' => 'branch', 'is_active' => true]);
        $user = User::factory()->create(['role' => 'admin', 'is_active' => true, 'branch_id' => $branch->id]);
        $customer = Customer::create(['customer_code' => 'C-RP', 'name' => 'Repair Customer', 'phone' => '01700000654', 'is_active' => true]);

        $invoice = SalesInvoice::create([
            'invoice_number' => 'S-RP-1',
            'branch_id' => $branch->id,
            'customer_id' => $customer->id,
            'invoice_date' => '2026-06-04',
            'sale_type' => 'installment',
            'status' => 'active',
            'payment_status' => 'paid',
            'subtotal' => 10000,
            'discount_total' => 0,
            'tax_total' => 0,
            'grand_total' => 10000,
            'amount_paid' => $paid,
            'amount_due' => $due,
            'created_by' => $user->id,
        ]);

        $plan = InstallmentPlan::create([
            'sales_invoice_id' => $invoice->id,
            'customer_id' => $customer->id,
            'total_amount' => 10000,
            'down_payment' => 2000,
            'financed_amount' => 8000,
            'remaining_amount' => 1000,
            'penalty_enabled' => false,
            'penalty_rate' => 0,
            'status' => 'active',
            'created_by' => $user->id,
        ]);

        $schedule = InstallmentSchedule::create([
            'installment_plan_id' => $plan->id,
            'installment_no' => 1,
            'due_date' => '2026-07-01',
            'due_amount' => 8000,
            'paid_amount' => 7000,
            'status' => 'partially_paid',
        ]);

        return [$invoice, $plan, $schedule];
    }

    public function test_preview_changes_nothing(): void
    {
        [$invoice, $plan, $schedule] = $this->settledInvoiceWithOpenPlan(10000, 0);

        $this->artisan('invoices:repair-invoice', ['invoice' => 'S-RP-1'])
            ->expectsOutputToContain('Preview only')
            ->assertSuccessful();

        $this->assertSame(7000.0, (float) $schedule->fresh()->paid_amount);
        $this->assertSame('active', $plan->fresh()->status);
    }

    public function test_apply_closes_plan_to_match_settled_header_and_keeps_header(): void
    {
        [$invoice, $plan, $schedule] = $this->settledInvoiceWithOpenPlan(10000, 0);

        $this->artisan('invoices:repair-invoice', ['invoice' => (string) $invoice->id, '--apply' => true])
            ->assertSuccessful();

        $this->assertSame(8000.0, (float) $schedule->fresh()->paid_amount);
        $this->assertSame('paid', $schedule->fresh()->status);
        $this->assertSame('completed', $plan->fresh()->status);
        $this->assertSame(0.0, (float) $plan->fresh()->remaining_amount);
        $this->assertSame(10000.0, (float) $invoice->fresh()->amount_paid);
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'sales_invoice.integrity_repair')->count());
    }

    public function test_unsettled_header_leaves_plan_untouched(): void
    {
        [$invoice, $plan, $schedule] = $this->settledInvoiceWithOpenPlan(9000, 1000);

        $this->artisan('invoices:repair-invoice', ['invoice' => (string) $invoice->id, '--apply' => true])
            ->expectsOutputToContain('left untouched')
            ->assertSuccessful();

        $this->assertSame(7000.0, (float) $schedule->fresh()->paid_amount);
        $this->assertSame('active', $plan->fresh()->status);
    }

    public function test_apply_removes_orphan_allocation(): void
    {
        [$invoice] = $this->settledInvoiceWithOpenPlan(10000, 0);
        // Production lacks the payment_id foreign key, so orphans can exist there; simulate that.
        DB::statement('PRAGMA defer_foreign_keys = ON');
        DB::table('payment_allocations')->insert([
            'payment_id' => 999999,
            'sales_invoice_id' => $invoice->id,
            'allocated_amount' => 7000,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->artisan('invoices:repair-invoice', ['invoice' => (string) $invoice->id, '--apply' => true])
            ->expectsOutputToContain('delete orphan allocation')
            ->assertSuccessful();

        $this->assertSame(0, DB::table('payment_allocations')->where('sales_invoice_id', $invoice->id)->count());
    }
}
