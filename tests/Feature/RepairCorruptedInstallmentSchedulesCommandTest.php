<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\InstallmentPaymentAllocation;
use App\Models\InstallmentPlan;
use App\Models\InstallmentSchedule;
use App\Models\Location;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\SalesInvoice;
use App\Models\User;
use App\Services\InstallmentAdvanceService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RepairCorruptedInstallmentSchedulesCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_repair_command_skips_dizicashier_imported_plans(): void
    {
        ['plan' => $plan, 'invoice' => $invoice, 'first' => $first] = $this->makeAdvanceMisallocatedPlan();
        $invoice->update(['dizicashier_uuid' => 'dizi-sale-uuid-456']);

        $this->artisan('installments:repair-corrupted-schedules', ['--invoice' => (string) $invoice->id])
            ->assertSuccessful();

        $first->refresh();
        $payment = Payment::query()->where('reference_no', InstallmentAdvanceService::REFERENCE)->firstOrFail();
        $this->assertSame(5000.0, (float) $first->paid_amount);
        $this->assertSame(1, $payment->installmentAllocations()->count());
    }

    public function test_repair_command_removes_advance_from_installment_schedules(): void
    {
        ['plan' => $plan, 'invoice' => $invoice, 'first' => $first] = $this->makeAdvanceMisallocatedPlan();

        $this->artisan('installments:repair-corrupted-schedules', ['--invoice' => (string) $invoice->id])
            ->assertSuccessful();

        $plan->refresh();
        $first->refresh();
        $invoice->refresh();

        $this->assertSame(0.0, (float) $first->paid_amount);
        $this->assertSame(15000.0, (float) $plan->remaining_amount);
        $this->assertSame(15000.0, (float) $invoice->amount_due);
    }

    public function test_repair_command_fixes_negative_payments_and_inflated_remaining(): void
    {
        ['plan' => $plan, 'last' => $last, 'invoice' => $invoice] = $this->makeCorruptedPlan();

        $this->artisan('installments:repair-corrupted-schedules', ['--invoice' => (string) $invoice->id])
            ->assertSuccessful();

        $plan->refresh();
        $last->refresh();

        $this->assertSame(43500.0, (float) $plan->remaining_amount);
        $this->assertSame(7250.0, (float) $last->due_amount);
        $this->assertSame(0.0, (float) $last->paid_amount);
        $this->assertSame('overdue', $last->status);

        $this->assertSame(
            0,
            InstallmentSchedule::query()->where('paid_amount', '<', 0)->count(),
        );
    }

    public function test_dry_run_does_not_change_corrupted_plan(): void
    {
        ['plan' => $plan, 'invoice' => $invoice] = $this->makeCorruptedPlan();

        $this->artisan('installments:repair-corrupted-schedules', [
            '--invoice' => (string) $invoice->id,
            '--dry-run' => true,
        ])->assertSuccessful();

        $this->assertSame(48500.0, (float) $plan->fresh()->remaining_amount);
    }

    public function test_roll_overdue_skips_schedules_with_negative_paid_amount(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-05-23'));

        [, $first, $second] = $this->makePlanWithSchedules([
            ['due_date' => '2026-05-01', 'due_amount' => 0, 'paid_amount' => -1000],
            ['due_date' => '2026-06-01', 'due_amount' => 100, 'paid_amount' => 0],
        ]);

        $this->artisan('installments:roll-overdue-due')->assertSuccessful();

        $this->assertSame(-1000.0, (float) $first->fresh()->paid_amount);
        $this->assertSame(100.0, (float) $second->fresh()->remainingDue());
    }

    /**
     * @return array{plan: InstallmentPlan, invoice: SalesInvoice, first: InstallmentSchedule}
     */
    private function makeAdvanceMisallocatedPlan(): array
    {
        $branch = Location::create([
            'code' => 'BR-ADV-MIS',
            'name' => 'Advance Misalloc Branch',
            'type' => 'branch',
            'is_active' => true,
        ]);

        $user = User::factory()->create([
            'role' => 'admin',
            'is_active' => true,
        ]);

        $customer = Customer::create([
            'customer_code' => 'C-ADV-MIS',
            'name' => 'Advance Misalloc Customer',
            'phone' => '01700005555',
            'is_active' => true,
        ]);

        $invoice = SalesInvoice::create([
            'invoice_number' => 'INV-ADV-MIS',
            'branch_id' => $branch->id,
            'customer_id' => $customer->id,
            'invoice_date' => now()->subDays(3)->toDateString(),
            'sale_type' => 'installment',
            'status' => 'active',
            'payment_status' => 'partial',
            'subtotal' => 20000,
            'discount_total' => 0,
            'tax_total' => 0,
            'grand_total' => 20000,
            'amount_paid' => 5000,
            'amount_due' => 15000,
            'installment_down_payment' => 5000,
            'created_by' => $user->id,
        ]);

        $plan = InstallmentPlan::create([
            'sales_invoice_id' => $invoice->id,
            'customer_id' => $customer->id,
            'total_amount' => 20000,
            'down_payment' => 5000,
            'financed_amount' => 15000,
            'remaining_amount' => 10000,
            'penalty_enabled' => false,
            'penalty_rate' => 0,
            'status' => 'active',
            'created_by' => $user->id,
        ]);

        $schedules = [];
        foreach ([5000, 5000, 5000] as $index => $dueAmount) {
            $schedules[] = InstallmentSchedule::create([
                'installment_plan_id' => $plan->id,
                'installment_no' => $index + 1,
                'due_date' => now()->addMonths($index + 1)->toDateString(),
                'due_amount' => $dueAmount,
                'paid_amount' => $index === 0 ? 5000 : 0,
                'status' => $index === 0 ? 'paid' : 'pending',
            ]);
        }

        $payment = Payment::create([
            'payment_number' => 'PAY-MIS-1',
            'customer_id' => $customer->id,
            'payment_date' => now()->subDays(3)->toDateString(),
            'payment_method' => 'cash',
            'amount' => 5000,
            'direction' => 'inbound',
            'reference_no' => InstallmentAdvanceService::REFERENCE,
            'recorded_by' => $user->id,
        ]);

        PaymentAllocation::create([
            'payment_id' => $payment->id,
            'sales_invoice_id' => $invoice->id,
            'allocated_amount' => 5000,
            'settlement_discount' => 0,
        ]);

        InstallmentPaymentAllocation::create([
            'installment_schedule_id' => $schedules[0]->id,
            'payment_id' => $payment->id,
            'allocated_amount' => 5000,
        ]);

        return [
            'plan' => $plan,
            'invoice' => $invoice,
            'first' => $schedules[0],
        ];
    }

    /**
     * @return array{plan: InstallmentPlan, last: InstallmentSchedule, invoice: SalesInvoice}
     */
    private function makeCorruptedPlan(): array
    {
        $branch = Location::create([
            'code' => 'BR-REP',
            'name' => 'Repair Branch',
            'type' => 'branch',
            'is_active' => true,
        ]);

        $user = User::factory()->create([
            'role' => 'admin',
            'is_active' => true,
        ]);

        $customer = Customer::create([
            'customer_code' => 'C-REP',
            'name' => 'Repair Customer',
            'phone' => '01700002222',
            'is_active' => true,
        ]);

        $invoice = SalesInvoice::create([
            'invoice_number' => 'S-24068',
            'branch_id' => $branch->id,
            'customer_id' => $customer->id,
            'invoice_date' => now()->subMonths(3)->toDateString(),
            'sale_type' => 'installment',
            'status' => 'active',
            'payment_status' => 'unpaid',
            'subtotal' => 47500,
            'discount_total' => 0,
            'tax_total' => 0,
            'grand_total' => 47500,
            'amount_paid' => 0,
            'amount_due' => 47500,
            'created_by' => $user->id,
        ]);

        $plan = InstallmentPlan::create([
            'sales_invoice_id' => $invoice->id,
            'customer_id' => $customer->id,
            'total_amount' => 47500,
            'down_payment' => 4000,
            'financed_amount' => 43500,
            'remaining_amount' => 48500,
            'penalty_enabled' => false,
            'penalty_rate' => 0,
            'status' => 'active',
            'created_by' => $user->id,
        ]);

        $rows = [
            ['due_date' => '2026-02-01', 'due_amount' => -2000, 'paid_amount' => -2000],
            ['due_date' => '2026-03-01', 'due_amount' => 0, 'paid_amount' => 0],
            ['due_date' => '2026-04-01', 'due_amount' => -3000, 'paid_amount' => -3000],
            ['due_date' => '2026-05-01', 'due_amount' => 0, 'paid_amount' => 0],
            ['due_date' => '2026-06-01', 'due_amount' => 0, 'paid_amount' => 0],
            ['due_date' => '2026-04-01', 'due_amount' => 48500, 'paid_amount' => 0],
        ];

        $schedules = [];
        foreach ($rows as $index => $row) {
            $schedules[] = InstallmentSchedule::create([
                'installment_plan_id' => $plan->id,
                'installment_no' => $index + 1,
                'due_date' => $row['due_date'],
                'due_amount' => $row['due_amount'],
                'paid_amount' => $row['paid_amount'],
                'late_fee_amount' => 0,
                'status' => 'paid',
            ]);
        }

        return [
            'plan' => $plan,
            'last' => $schedules[5],
            'invoice' => $invoice,
        ];
    }

    /**
     * @param  list<array{due_date: string, due_amount: float|int, paid_amount: float|int}>  $rows
     * @return array{0: InstallmentPlan, 1: InstallmentSchedule, ...}
     */
    private function makePlanWithSchedules(array $rows): array
    {
        $branch = Location::create([
            'code' => 'BR-NEG',
            'name' => 'Negative Branch',
            'type' => 'branch',
            'is_active' => true,
        ]);

        $user = User::factory()->create([
            'role' => 'admin',
            'is_active' => true,
        ]);

        $customer = Customer::create([
            'customer_code' => 'C-NEG',
            'name' => 'Negative Customer',
            'phone' => '01700003333',
            'is_active' => true,
        ]);

        $financed = array_sum(array_column($rows, 'due_amount'));

        $invoice = SalesInvoice::create([
            'invoice_number' => 'INV-NEG-'.uniqid(),
            'branch_id' => $branch->id,
            'customer_id' => $customer->id,
            'invoice_date' => now()->subMonth()->toDateString(),
            'sale_type' => 'installment',
            'status' => 'active',
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
