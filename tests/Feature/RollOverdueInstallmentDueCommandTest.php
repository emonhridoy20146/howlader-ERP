<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\InstallmentPlan;
use App\Models\InstallmentSchedule;
use App\Models\Location;
use App\Models\SalesInvoice;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RollOverdueInstallmentDueCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_rolls_single_overdue_installment_to_next(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-05-23'));

        [$plan, $first, $second] = $this->makePlanWithSchedules([
            ['due_date' => '2026-05-01', 'due_amount' => 100, 'paid_amount' => 0],
            ['due_date' => '2026-06-01', 'due_amount' => 100, 'paid_amount' => 0],
        ]);

        $this->artisan('installments:roll-overdue-due')
            ->assertSuccessful();

        $first->refresh();
        $second->refresh();

        $this->assertSame(0.0, $first->remainingDue());
        $this->assertSame(200.0, $second->remainingDue());
        $this->assertSame(200.0, (float) $plan->fresh()->remaining_amount);
    }

    public function test_consolidates_all_missed_installments_onto_last(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-05-23'));

        [, $first, $second, $third] = $this->makePlanWithSchedules([
            ['due_date' => '2026-04-01', 'due_amount' => 100, 'paid_amount' => 0],
            ['due_date' => '2026-04-15', 'due_amount' => 100, 'paid_amount' => 0],
            ['due_date' => '2026-05-01', 'due_amount' => 100, 'paid_amount' => 0],
        ]);

        $this->artisan('installments:roll-overdue-due')
            ->assertSuccessful();

        $first->refresh();
        $second->refresh();
        $third->refresh();

        $this->assertSame(0.0, $first->remainingDue());
        $this->assertSame(0.0, $second->remainingDue());
        $this->assertSame(300.0, $third->remainingDue());
        $this->assertSame(300.0, (float) $third->due_amount);
    }

    public function test_rolls_only_remaining_balance_when_partially_paid(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-05-23'));

        [, $first, $second] = $this->makePlanWithSchedules([
            ['due_date' => '2026-05-01', 'due_amount' => 100, 'paid_amount' => 30],
            ['due_date' => '2026-06-01', 'due_amount' => 100, 'paid_amount' => 0],
        ]);

        $this->artisan('installments:roll-overdue-due')
            ->assertSuccessful();

        $first->refresh();
        $second->refresh();

        $this->assertSame(0.0, $first->remainingDue());
        $this->assertSame(30.0, (float) $first->due_amount);
        $this->assertSame(170.0, $second->remainingDue());
    }

    public function test_dry_run_does_not_persist_changes(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-05-23'));

        [, $first, $second] = $this->makePlanWithSchedules([
            ['due_date' => '2026-05-01', 'due_amount' => 100, 'paid_amount' => 0],
            ['due_date' => '2026-06-01', 'due_amount' => 100, 'paid_amount' => 0],
        ]);

        $this->artisan('installments:roll-overdue-due', ['--dry-run' => true])
            ->assertSuccessful();

        $this->assertSame(100.0, $first->fresh()->remainingDue());
        $this->assertSame(100.0, $second->fresh()->remainingDue());
    }

    public function test_skips_installments_not_yet_due(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-05-23'));

        [, $first, $second] = $this->makePlanWithSchedules([
            ['due_date' => '2026-06-01', 'due_amount' => 100, 'paid_amount' => 0],
            ['due_date' => '2026-07-01', 'due_amount' => 100, 'paid_amount' => 0],
        ]);

        $this->artisan('installments:roll-overdue-due')
            ->assertSuccessful();

        $this->assertSame(100.0, $first->fresh()->remainingDue());
        $this->assertSame(100.0, $second->fresh()->remainingDue());
    }

    /**
     * @param  list<array{due_date: string, due_amount: float|int, paid_amount: float|int}>  $rows
     * @return array{0: InstallmentPlan, 1: InstallmentSchedule, ...}
     */
    private function makePlanWithSchedules(array $rows): array
    {
        $branch = Location::create([
            'code' => 'BR-ROLL',
            'name' => 'Roll Branch',
            'type' => 'branch',
            'is_active' => true,
        ]);

        $user = User::factory()->create([
            'role' => 'admin',
            'is_active' => true,
        ]);

        $customer = Customer::create([
            'customer_code' => 'C-ROLL',
            'name' => 'Roll Customer',
            'phone' => '01700001111',
            'is_active' => true,
        ]);

        $financed = array_sum(array_column($rows, 'due_amount'));

        $invoice = SalesInvoice::create([
            'invoice_number' => 'INV-ROLL-'.uniqid(),
            'branch_id' => $branch->id,
            'customer_id' => $customer->id,
            'invoice_date' => now()->subMonth()->toDateString(),
            'sale_type' => 'installment',
            'status' => 'posted',
            'payment_status' => 'partial',
            'subtotal' => $financed,
            'discount_total' => 0,
            'tax_total' => 0,
            'grand_total' => $financed,
            'amount_paid' => 0,
            'amount_due' => $financed,
            'created_by' => $user->id,
        ]);

        $plan = InstallmentPlan::create([
            'sales_invoice_id' => $invoice->id,
            'customer_id' => $customer->id,
            'total_amount' => $financed,
            'down_payment' => 0,
            'financed_amount' => $financed,
            'remaining_amount' => $financed,
            'penalty_enabled' => false,
            'penalty_rate' => 0,
            'reschedule_count' => 0,
            'status' => 'active',
            'created_by' => $user->id,
        ]);

        $schedules = [];
        foreach ($rows as $index => $row) {
            $schedules[] = InstallmentSchedule::create([
                'installment_plan_id' => $plan->id,
                'installment_no' => $index + 1,
                'due_date' => $row['due_date'],
                'due_amount' => $row['due_amount'],
                'paid_amount' => $row['paid_amount'],
                'late_fee_amount' => 0,
                'status' => 'pending',
            ]);
        }

        return array_merge([$plan], $schedules);
    }
}
