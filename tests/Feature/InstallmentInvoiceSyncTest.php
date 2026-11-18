<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\InstallmentPlan;
use App\Models\InstallmentSchedule;
use App\Models\Location;
use App\Models\SalesInvoice;
use App\Models\User;
use App\Services\InstallmentInvoiceSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InstallmentInvoiceSyncTest extends TestCase
{
    use RefreshDatabase;

    public function test_rebalance_fixes_inflated_schedule_totals_and_syncs_invoice_header(): void
    {
        [$invoice, $plan, $lastSchedule] = $this->makeInflatedInstallmentInvoice();

        $result = app(InstallmentInvoiceSyncService::class)->reconcilePlanAndInvoice($plan);

        $invoice->refresh();
        $plan->refresh();
        $lastSchedule->refresh();

        $this->assertTrue($result['rebalanced']);
        $this->assertSame(28000.0, $result['before_remaining']);
        $this->assertSame(3500.0, $result['after_remaining']);
        $this->assertSame(3500.0, $result['after_invoice_due']);
        $this->assertSame(3500.0, (float) $invoice->amount_due);
        $this->assertSame(3500.0, (float) $plan->remaining_amount);
        $this->assertSame(24500.0, (float) $plan->financed_amount);
        $this->assertSame(7000.0, (float) $lastSchedule->due_amount);
        $this->assertSame(3500.0, $lastSchedule->remainingDue());
    }

    public function test_repair_command_fixes_invoice_four_style_mismatch(): void
    {
        [$invoice] = $this->makeInflatedInstallmentInvoice();

        $this->artisan('installments:repair-corrupted-schedules', ['--invoice' => (string) $invoice->id])
            ->assertSuccessful();

        $invoice->refresh();
        $plan = $invoice->installmentPlan()->first();

        $this->assertSame(3500.0, (float) $invoice->amount_due);
        $this->assertSame(3500.0, (float) $plan->remaining_amount);
    }

    /**
     * @return array{0: SalesInvoice, 1: InstallmentPlan, 2: InstallmentSchedule}
     */
    private function makeInflatedInstallmentInvoice(): array
    {
        $branch = Location::create([
            'code' => 'BR-SYNC',
            'name' => 'Sync Branch',
            'type' => 'branch',
            'is_active' => true,
        ]);

        $user = User::factory()->create([
            'role' => 'admin',
            'is_active' => true,
        ]);

        $customer = Customer::create([
            'customer_code' => 'C-SYNC',
            'name' => 'Sync Customer',
            'phone' => '01700004444',
            'is_active' => true,
        ]);

        $invoice = SalesInvoice::create([
            'invoice_number' => 'S-24028',
            'branch_id' => $branch->id,
            'customer_id' => $customer->id,
            'invoice_date' => now()->subYear()->toDateString(),
            'sale_type' => 'installment',
            'status' => 'active',
            'payment_status' => 'partial',
            'subtotal' => 29500,
            'discount_total' => 0,
            'tax_total' => 0,
            'grand_total' => 29500,
            'amount_paid' => 26000,
            'amount_due' => 3500,
            'installment_down_payment' => 5000,
            'created_by' => $user->id,
        ]);

        $plan = InstallmentPlan::create([
            'sales_invoice_id' => $invoice->id,
            'customer_id' => $customer->id,
            'total_amount' => 29500,
            'down_payment' => 5000,
            'financed_amount' => 49000,
            'remaining_amount' => 28000,
            'penalty_enabled' => false,
            'penalty_rate' => 0,
            'status' => 'active',
            'created_by' => $user->id,
        ]);

        $rows = [
            ['no' => 1, 'due' => 0, 'paid' => 0],
            ['no' => 2, 'due' => 4083, 'paid' => 4083],
            ['no' => 4, 'due' => 2749, 'paid' => 2749],
            ['no' => 7, 'due' => 4083, 'paid' => 4083],
            ['no' => 8, 'due' => 2500, 'paid' => 2500],
            ['no' => 9, 'due' => 4085, 'paid' => 4085],
            ['no' => 12, 'due' => 31500, 'paid' => 3500],
        ];

        $lastSchedule = null;
        foreach ($rows as $row) {
            $lastSchedule = InstallmentSchedule::create([
                'installment_plan_id' => $plan->id,
                'installment_no' => $row['no'],
                'due_date' => now()->subMonths(2)->toDateString(),
                'due_amount' => $row['due'],
                'paid_amount' => $row['paid'],
                'late_fee_amount' => 0,
                'status' => 'partially_paid',
            ]);
        }

        return [$invoice, $plan, $lastSchedule];
    }
}
