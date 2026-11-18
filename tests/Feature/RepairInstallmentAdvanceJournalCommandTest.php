<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\InstallmentPaymentAllocation;
use App\Models\InstallmentPlan;
use App\Models\InstallmentSchedule;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\LedgerAccount;
use App\Models\Location;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\PaymentMethod;
use App\Models\PaymentMethodWalletMap;
use App\Models\SalesInvoice;
use App\Models\User;
use App\Models\Wallet;
use App\Services\InstallmentAdvanceService;
use App\Support\LedgerAccountCodes;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RepairInstallmentAdvanceJournalCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_repair_command_only_processes_current_month_by_default(): void
    {
        [$currentInvoice, $currentPayment] = array_slice(
            $this->seedInstallmentWithAdvancePayment(journalPosted: false),
            0,
            2,
        );

        $oldInvoice = SalesInvoice::query()->findOrFail($currentInvoice->id)->replicate();
        $oldInvoice->invoice_number = 'INV-ADV-OLD';
        $oldInvoice->invoice_date = now()->subMonths(2)->toDateString();
        $oldInvoice->posted_at = now()->subMonths(2);
        $oldInvoice->save();

        $oldPayment = Payment::query()->findOrFail($currentPayment->id)->replicate();
        $oldPayment->payment_number = 'PAY-ADV-OLD';
        $oldPayment->payment_date = now()->subMonths(2)->toDateString();
        $oldPayment->save();

        PaymentAllocation::create([
            'payment_id' => $oldPayment->id,
            'sales_invoice_id' => $oldInvoice->id,
            'allocated_amount' => 5000,
            'settlement_discount' => 0,
        ]);

        $this->artisan('installments:repair-advance-journal')
            ->assertSuccessful();

        $this->assertTrue(
            JournalEntry::query()
                ->where('source', 'sales_payment')
                ->where('reference_id', $currentPayment->id)
                ->exists()
        );

        $this->assertFalse(
            JournalEntry::query()
                ->where('source', 'sales_payment')
                ->where('reference_id', $oldPayment->id)
                ->exists()
        );
    }

    public function test_repair_command_posts_missing_advance_journal_without_duplicating(): void
    {
        [$invoice, $payment, $wallet] = $this->seedInstallmentWithAdvancePayment(journalPosted: false);

        $this->artisan('installments:repair-advance-journal', ['--invoice' => (string) $invoice->id])
            ->assertSuccessful();

        $this->assertTrue(
            JournalEntry::query()
                ->where('source', 'sales_payment')
                ->where('reference_type', Payment::class)
                ->where('reference_id', $payment->id)
                ->exists()
        );

        $wallet->refresh();
        $this->assertSame(5000.0, $wallet->currentBalance());

        $entryCount = JournalEntry::query()
            ->where('source', 'sales_payment')
            ->where('reference_id', $payment->id)
            ->count();
        $this->assertSame(1, $entryCount);

        $this->artisan('installments:repair-advance-journal', ['--invoice' => (string) $invoice->id])
            ->assertSuccessful();

        $this->assertSame(1, JournalEntry::query()
            ->where('source', 'sales_payment')
            ->where('reference_id', $payment->id)
            ->count());
    }

    public function test_repair_command_skips_dizicashier_imported_invoices(): void
    {
        [$invoice, $payment, , , , $firstSchedule] = $this->seedInstallmentWithMisallocatedAdvance();
        $invoice->update(['dizicashier_uuid' => 'dizi-sale-uuid-123']);

        $this->artisan('installments:repair-advance-journal', [
            '--invoice' => (string) $invoice->id,
            '--all' => true,
        ])
            ->expectsOutputToContain('Dizicashier import')
            ->assertSuccessful();

        $firstSchedule->refresh();
        $payment->refresh();

        $this->assertSame(5000.0, (float) $firstSchedule->paid_amount);
        $this->assertSame(1, $payment->installmentAllocations()->count());
    }

    public function test_repair_command_removes_advance_allocations_from_schedules(): void
    {
        [$invoice, $payment, , $method, $user, $firstSchedule] = $this->seedInstallmentWithMisallocatedAdvance();

        $this->artisan('installments:repair-advance-journal', [
            '--invoice' => (string) $invoice->id,
            '--all' => true,
        ])->assertSuccessful();

        $invoice->refresh()->load('installmentPlan.schedules');
        $firstSchedule->refresh();

        $this->assertSame(0.0, (float) $firstSchedule->paid_amount);
        $this->assertSame(15000.0, (float) $invoice->installmentPlan->remaining_amount);
        $this->assertSame(15000.0, (float) $invoice->amount_due);
        $this->assertSame(5000.0, (float) $invoice->amount_paid);
        $this->assertSame(0, $payment->fresh()->installmentAllocations()->count());
    }

    public function test_record_advance_on_posting_does_not_allocate_to_schedules(): void
    {
        [$invoice, , $wallet, $method, $user] = $this->seedInstallmentWithPlanForAdvancePosting();

        app(InstallmentAdvanceService::class)->recordAdvanceOnPosting(
            $invoice->fresh(['installmentPlan.schedules']),
            (int) $method->id,
            (int) $user->id,
        );

        $invoice->refresh()->load('installmentPlan.schedules');

        $this->assertSame(5000.0, (float) $invoice->amount_paid);
        $this->assertSame(15000.0, (float) $invoice->amount_due);
        $this->assertSame(0.0, (float) $invoice->installmentPlan->schedules->sum('paid_amount'));
        $this->assertSame(15000.0, (float) $invoice->installmentPlan->remaining_amount);

        $payment = Payment::query()
            ->where('reference_no', InstallmentAdvanceService::REFERENCE)
            ->whereHas('paymentAllocations', fn ($q) => $q->where('sales_invoice_id', $invoice->id))
            ->first();

        $this->assertNotNull($payment);
        $this->assertSame(0, $payment->installmentAllocations()->count());

        $wallet->refresh();
        $this->assertSame(5000.0, $wallet->currentBalance());
    }

    public function test_backfill_creates_advance_payment_and_journal_for_old_invoice(): void
    {
        [$invoice, , $wallet] = $this->seedInstallmentWithAdvancePayment(journalPosted: false, createPayment: false);

        $result = app(InstallmentAdvanceService::class)->backfillMissingAdvancePayment($invoice, User::factory()->create()->id);

        $this->assertTrue($result['created']);
        $this->assertTrue($result['journal_posted']);
        $this->assertSame(5000.0, (float) $invoice->fresh()->amount_paid);

        $wallet->refresh();
        $this->assertSame(5000.0, $wallet->currentBalance());
    }

    public function test_backfill_uses_invoice_date_for_payment_and_journal_date(): void
    {
        [$invoice, , $wallet] = $this->seedInstallmentWithAdvancePayment(journalPosted: false, createPayment: false);

        $invoiceDate = now()->subDays(5)->startOfDay();
        SalesInvoice::query()->whereKey($invoice->id)->update([
            'invoice_date' => $invoiceDate->toDateString(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $invoice->refresh();

        app(InstallmentAdvanceService::class)->backfillMissingAdvancePayment($invoice, User::factory()->create()->id);

        $payment = Payment::query()
            ->where('reference_no', InstallmentAdvanceService::REFERENCE)
            ->whereHas('paymentAllocations', fn ($q) => $q->where('sales_invoice_id', $invoice->id))
            ->first();

        $this->assertNotNull($payment);
        $this->assertSame($invoiceDate->toDateString(), $payment->payment_date->toDateString());

        $entry = JournalEntry::query()
            ->where('source', 'sales_payment')
            ->where('reference_id', $payment->id)
            ->first();

        $this->assertNotNull($entry);
        $this->assertSame($invoiceDate->toDateString(), $entry->entry_date->toDateString());
    }

    public function test_repair_syncs_existing_advance_payment_date_to_invoice_date(): void
    {
        [$invoice, $payment, $wallet] = $this->seedInstallmentWithAdvancePayment(journalPosted: true);

        $invoiceDate = now()->subDays(8)->startOfDay();
        SalesInvoice::query()->whereKey($invoice->id)->update([
            'invoice_date' => $invoiceDate->toDateString(),
        ]);
        Payment::query()->whereKey($payment->id)->update([
            'payment_date' => now()->toDateString(),
        ]);
        JournalEntry::query()
            ->where('source', 'sales_payment')
            ->where('reference_id', $payment->id)
            ->update(['entry_date' => now()->toDateString()]);

        $this->artisan('installments:repair-advance-journal', ['--invoice' => (string) $invoice->id])
            ->assertSuccessful();

        $payment->refresh();
        $this->assertSame($invoiceDate->toDateString(), $payment->payment_date->toDateString());

        $entry = JournalEntry::query()
            ->where('source', 'sales_payment')
            ->where('reference_id', $payment->id)
            ->first();
        $this->assertSame($invoiceDate->toDateString(), $entry->entry_date->toDateString());
    }

    /**
     * @return array{0: SalesInvoice, 1: Payment|null, 2: Wallet}
     */
    private function seedInstallmentWithAdvancePayment(bool $journalPosted, bool $createPayment = true): array
    {
        $this->seedCoreLedgerAccounts();

        $branch = Location::create([
            'code' => 'BR-ADV',
            'name' => 'Advance Branch',
            'type' => 'branch',
            'is_active' => true,
        ]);

        $user = User::factory()->create([
            'role' => 'admin',
            'is_active' => true,
            'branch_id' => $branch->id,
        ]);

        $walletLedger = LedgerAccount::create([
            'code' => 'W-ADV-CASH',
            'name' => 'Advance Cash',
            'type' => 'asset',
            'is_system' => false,
            'is_active' => true,
        ]);

        $wallet = Wallet::create([
            'ledger_account_id' => $walletLedger->id,
            'code' => 'ADV-CASH',
            'name' => 'Advance Cash Wallet',
            'kind' => 'cash',
            'is_active' => true,
        ]);
        $wallet->locations()->sync([$branch->id]);

        $method = PaymentMethod::create([
            'location_id' => $branch->id,
            'code' => 'CASH',
            'name' => 'Cash',
            'type' => 'cash',
            'is_active' => true,
            'created_by' => $user->id,
        ]);

        PaymentMethodWalletMap::create([
            'location_id' => $branch->id,
            'payment_method_id' => $method->id,
            'wallet_id' => $wallet->id,
        ]);

        $customer = Customer::create([
            'customer_code' => 'C-ADV',
            'name' => 'Advance Customer',
            'phone' => '01700001111',
            'is_active' => true,
        ]);

        $invoice = SalesInvoice::create([
            'invoice_number' => 'INV-ADV-1',
            'invoice_date' => now()->toDateString(),
            'branch_id' => $branch->id,
            'customer_id' => $customer->id,
            'sale_type' => 'installment',
            'status' => 'active',
            'payment_status' => 'partial',
            'subtotal' => 30000,
            'discount_total' => 0,
            'tax_total' => 0,
            'grand_total' => 30000,
            'amount_paid' => 5000,
            'amount_due' => 25000,
            'installment_down_payment' => 5000,
            'installment_count' => 3,
            'installment_first_due_date' => now()->addMonth()->toDateString(),
            'created_by' => $user->id,
            'posted_by' => $user->id,
            'posted_at' => now(),
        ]);

        $payment = null;
        if ($createPayment) {
            $payment = Payment::create([
                'payment_number' => 'PAY-ADV-1',
                'customer_id' => $customer->id,
                'payment_date' => now()->toDateString(),
                'payment_method' => 'cash',
                'payment_method_id' => $method->id,
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
        }

        if ($journalPosted && $payment) {
            app(\App\Services\JournalService::class)->postCustomerPaymentGl(
                $branch->id,
                5000,
                $method->id,
                $payment,
                $user->id,
            );
        }

        return [$invoice, $payment, $wallet];
    }

    /**
     * @return array{0: SalesInvoice, 1: null, 2: Wallet, 3: PaymentMethod, 4: User}
     */
    private function seedInstallmentWithPlanForAdvancePosting(): array
    {
        $this->seedCoreLedgerAccounts();

        $branch = Location::create([
            'code' => 'BR-ADV-POST',
            'name' => 'Advance Post Branch',
            'type' => 'branch',
            'is_active' => true,
        ]);

        $user = User::factory()->create([
            'role' => 'admin',
            'is_active' => true,
            'branch_id' => $branch->id,
        ]);

        $walletLedger = LedgerAccount::create([
            'code' => 'W-ADV-POST',
            'name' => 'Advance Post Cash',
            'type' => 'asset',
            'is_system' => false,
            'is_active' => true,
        ]);

        $wallet = Wallet::create([
            'ledger_account_id' => $walletLedger->id,
            'code' => 'ADV-POST-CASH',
            'name' => 'Advance Post Cash Wallet',
            'kind' => 'cash',
            'is_active' => true,
        ]);
        $wallet->locations()->sync([$branch->id]);

        $method = PaymentMethod::create([
            'location_id' => $branch->id,
            'code' => 'CASH',
            'name' => 'Cash',
            'type' => 'cash',
            'is_active' => true,
            'created_by' => $user->id,
        ]);

        PaymentMethodWalletMap::create([
            'location_id' => $branch->id,
            'payment_method_id' => $method->id,
            'wallet_id' => $wallet->id,
        ]);

        $customer = Customer::create([
            'customer_code' => 'C-ADV-POST',
            'name' => 'Advance Post Customer',
            'phone' => '01700002222',
            'is_active' => true,
        ]);

        $invoice = SalesInvoice::create([
            'invoice_number' => 'INV-ADV-POST',
            'invoice_date' => now()->toDateString(),
            'branch_id' => $branch->id,
            'customer_id' => $customer->id,
            'sale_type' => 'installment',
            'status' => 'active',
            'payment_status' => 'unpaid',
            'subtotal' => 20000,
            'discount_total' => 0,
            'tax_total' => 0,
            'grand_total' => 20000,
            'amount_paid' => 0,
            'amount_due' => 15000,
            'installment_down_payment' => 5000,
            'installment_count' => 3,
            'installment_first_due_date' => now()->addMonth()->toDateString(),
            'installment_frequency' => 'monthly',
            'created_by' => $user->id,
            'posted_by' => $user->id,
            'posted_at' => now(),
        ]);

        $plan = InstallmentPlan::create([
            'sales_invoice_id' => $invoice->id,
            'customer_id' => $customer->id,
            'total_amount' => 20000,
            'down_payment' => 5000,
            'financed_amount' => 15000,
            'remaining_amount' => 15000,
            'penalty_enabled' => false,
            'penalty_rate' => 0.02,
            'created_by' => $user->id,
        ]);

        foreach ([5000, 5000, 5000] as $index => $dueAmount) {
            InstallmentSchedule::create([
                'installment_plan_id' => $plan->id,
                'installment_no' => $index + 1,
                'due_date' => now()->addMonths($index + 1)->toDateString(),
                'due_amount' => $dueAmount,
            ]);
        }

        return [$invoice, null, $wallet, $method, $user];
    }

    /**
     * @return array{0: SalesInvoice, 1: Payment, 2: Wallet, 3: PaymentMethod, 4: User, 5: InstallmentSchedule}
     */
    private function seedInstallmentWithMisallocatedAdvance(): array
    {
        [$invoice, , $wallet, $method, $user] = $this->seedInstallmentWithPlanForAdvancePosting();

        $payment = Payment::create([
            'payment_number' => 'PAY-ADV-MIS',
            'customer_id' => $invoice->customer_id,
            'payment_date' => now()->toDateString(),
            'payment_method' => 'cash',
            'payment_method_id' => $method->id,
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

        $firstSchedule = InstallmentSchedule::query()
            ->where('installment_plan_id', $invoice->installmentPlan->id)
            ->orderBy('installment_no')
            ->firstOrFail();

        InstallmentPaymentAllocation::create([
            'installment_schedule_id' => $firstSchedule->id,
            'payment_id' => $payment->id,
            'allocated_amount' => 5000,
        ]);

        $firstSchedule->update([
            'paid_amount' => 5000,
            'status' => 'paid',
        ]);

        $invoice->update([
            'amount_paid' => 5000,
            'amount_due' => 15000,
            'payment_status' => 'partial',
        ]);

        $invoice->installmentPlan->update(['remaining_amount' => 10000]);

        return [$invoice, $payment, $wallet, $method, $user, $firstSchedule];
    }

    private function seedCoreLedgerAccounts(): void
    {
        foreach ([
            [LedgerAccountCodes::ACCOUNTS_RECEIVABLE, 'Accounts Receivable', 'asset'],
            [LedgerAccountCodes::SALES_REVENUE, 'Sales Revenue', 'revenue'],
        ] as [$code, $name, $type]) {
            LedgerAccount::query()->firstOrCreate(
                ['code' => $code],
                ['name' => $name, 'type' => $type, 'is_system' => true, 'is_active' => true],
            );
        }
    }
}
