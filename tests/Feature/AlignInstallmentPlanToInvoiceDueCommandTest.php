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

class AlignInstallmentPlanToInvoiceDueCommandTest extends TestCase
{
    use RefreshDatabase;

    /** Header: paid 30000 / due 7000 of 37000; schedule only credited so 12360 remains. */
    private function openInvoice(float $paid = 30000, float $due = 7000): SalesInvoice
    {
        $branch = Location::create(['code' => 'BR-AL', 'name' => 'Align Branch', 'type' => 'branch', 'is_active' => true]);
        $user = User::factory()->create(['role' => 'admin', 'is_active' => true, 'branch_id' => $branch->id]);
        $customer = Customer::create(['customer_code' => 'C-AL', 'name' => 'Align Customer', 'phone' => '01700000888', 'is_active' => true]);

        $invoice = SalesInvoice::create([
            'invoice_number' => 'S-AL-1', 'branch_id' => $branch->id, 'customer_id' => $customer->id,
            'invoice_date' => '2026-06-04', 'sale_type' => 'installment', 'status' => 'active', 'payment_status' => 'partial',
            'subtotal' => 37000, 'discount_total' => 0, 'tax_total' => 0, 'grand_total' => 37000,
            'amount_paid' => $paid, 'amount_due' => $due, 'created_by' => $user->id,
        ]);

        $plan = InstallmentPlan::create([
            'sales_invoice_id' => $invoice->id, 'customer_id' => $customer->id, 'total_amount' => 37000,
            'down_payment' => 10200, 'financed_amount' => 26800, 'remaining_amount' => 12360,
            'penalty_enabled' => false, 'penalty_rate' => 0, 'status' => 'active', 'created_by' => $user->id,
        ]);

        foreach ([[1, 5360, 5360, 'paid'], [2, 3280, 3280, 'paid'], [3, 18160, 5800, 'partially_paid']] as [$no, $amt, $paidAmt, $status]) {
            InstallmentSchedule::create([
                'installment_plan_id' => $plan->id, 'installment_no' => $no, 'due_date' => '2026-0'.($no + 3).'-01',
                'due_amount' => $amt, 'paid_amount' => $paidAmt, 'status' => $status,
            ]);
        }

        return $invoice;
    }

    public function test_preview_changes_nothing(): void
    {
        $invoice = $this->openInvoice();

        $this->artisan('invoices:align-plan-to-due', ['invoice' => 'S-AL-1'])
            ->expectsOutputToContain('Preview only')
            ->assertSuccessful();

        $this->assertSame(12360.0, (float) $invoice->installmentPlan->fresh()->remaining_amount);
    }

    public function test_apply_credits_schedule_so_remaining_matches_due(): void
    {
        $invoice = $this->openInvoice();

        $this->artisan('invoices:align-plan-to-due', ['invoice' => (string) $invoice->id, '--apply' => true])->assertSuccessful();

        $plan = $invoice->installmentPlan->fresh(['schedules']);
        $this->assertSame(7000.0, (float) $plan->remaining_amount);
        $this->assertSame('active', $plan->status);
        $this->assertSame(7000.0, round((float) $plan->schedules->sum(fn ($s) => $s->remainingDue()), 2));
        $this->assertSame(11160.0, (float) $plan->schedules->firstWhere('installment_no', 3)->paid_amount);
        $this->assertSame(30000.0, (float) $invoice->fresh()->amount_paid);
    }

    public function test_refuses_when_header_does_not_add_up(): void
    {
        $invoice = $this->openInvoice(30000, 5000);

        $this->artisan('invoices:align-plan-to-due', ['invoice' => (string) $invoice->id, '--apply' => true])
            ->expectsOutputToContain('Header does not add up')
            ->assertFailed();

        $this->assertSame(12360.0, (float) $invoice->installmentPlan->fresh()->remaining_amount);
    }

    public function test_refuses_when_schedule_is_already_lower_than_due(): void
    {
        $invoice = $this->openInvoice(20000, 17000);

        $this->artisan('invoices:align-plan-to-due', ['invoice' => (string) $invoice->id, '--apply' => true])
            ->expectsOutputToContain('only adds credit')
            ->assertFailed();
    }
}
