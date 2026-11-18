<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\LedgerAccount;
use App\Models\Location;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\PaymentMethodWalletMap;
use App\Models\SalesInvoice;
use App\Models\User;
use App\Models\Wallet;
use App\Support\LedgerAccountCodes;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InvoiceHistoricalPaymentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(\App\Services\PermissionSyncService::class)->syncFromPolicies();
    }

    public function test_admin_can_record_backdated_historical_payment_with_wallet_gl(): void
    {
        [$admin, $invoice, $method, $walletLedger] = $this->seedPostedCreditSale();

        $backDate = now()->subDays(3)->toDateString();

        $response = $this->actingAs($admin)->post(
            route('sales.invoices.historical-payments.store', $invoice),
            [
                '_form' => 'historical_payment',
                'payment_date' => $backDate,
                'amount' => 1500,
                'settlement_discount' => 0,
                'payment_method_id' => $method->id,
                'reference' => 'BANK-REF-1',
                'notes' => 'Collected earlier, wallet already reconciled',
            ],
        );

        $response->assertRedirect(route('sales.invoices.show', $invoice));
        $response->assertSessionHas('status');

        $invoice->refresh();
        $this->assertSame(5000.0, (float) $invoice->amount_due);
        $this->assertSame(0.0, (float) $invoice->amount_paid);
        $this->assertSame('unpaid', $invoice->payment_status);

        $payment = Payment::query()->where('customer_id', $invoice->customer_id)->first();
        $this->assertNotNull($payment);
        $this->assertSame($backDate, $payment->payment_date->toDateString());
        $this->assertTrue($payment->isHistoricalPayment());
        $this->assertTrue($payment->isPaymentBackfill());
        $this->assertFalse($payment->skipsWalletGl());
        $this->assertSame('BANK-REF-1', $payment->reference_no);
        $this->assertNotNull($payment->wallet_id);

        $journalEntry = JournalEntry::query()
            ->where('source', 'sales_payment')
            ->where('reference_type', Payment::class)
            ->where('reference_id', $payment->id)
            ->first();

        $this->assertNotNull($journalEntry);
        $this->assertSame($backDate, $journalEntry->entry_date->toDateString());
        $this->assertStringContainsString('Historical customer payment', $journalEntry->description);

        $this->assertSame(
            1500.0,
            (float) JournalLine::query()->where('journal_entry_id', $journalEntry->id)->where('ledger_account_id', $walletLedger->id)->sum('debit'),
        );

        $clearingLedger = LedgerAccount::query()->where('code', LedgerAccountCodes::HISTORICAL_PAYMENT_CLEARING)->first();
        $this->assertNotNull($clearingLedger);
        $this->assertSame(
            1500.0,
            (float) JournalLine::query()->where('journal_entry_id', $journalEntry->id)->where('ledger_account_id', $clearingLedger->id)->sum('credit'),
        );
        $arLedger = LedgerAccount::query()->where('code', LedgerAccountCodes::ACCOUNTS_RECEIVABLE)->first();
        $this->assertSame(
            0.0,
            (float) JournalLine::query()->where('journal_entry_id', $journalEntry->id)->where('ledger_account_id', $arLedger->id)->sum('credit'),
        );

        $walletBalance = (float) JournalLine::query()
            ->where('ledger_account_id', $walletLedger->id)
            ->selectRaw('COALESCE(SUM(debit), 0) - COALESCE(SUM(credit), 0) as balance')
            ->value('balance');

        $this->assertSame(1500.0, $walletBalance);
    }

    public function test_user_with_backdate_permission_can_record_historical_payment(): void
    {
        [$admin, $invoice, $method, $walletLedger] = $this->seedPostedCreditSale();
        unset($admin);

        $user = User::factory()->create([
            'role' => 'sales',
            'is_active' => true,
            'branch_id' => $invoice->branch_id,
        ]);

        $role = \App\Models\Role::query()->create([
            'slug' => 'hist-pay-'.uniqid(),
            'name' => 'Historical payment',
        ]);
        $permIds = \App\Models\Permission::query()
            ->whereIn('slug', ['sales.invoice.backdate', 'sales-invoice.view', 'sales-invoice.view-any'])
            ->pluck('id')
            ->all();
        $role->permissions()->sync($permIds);
        $user->roles()->sync([$role->id]);
        $user->accessibleLocations()->sync([$invoice->branch_id]);

        app(\App\Services\PermissionSyncService::class)->syncFromPolicies();

        $response = $this->actingAs($user)->post(
            route('sales.invoices.historical-payments.store', $invoice),
            [
                '_form' => 'historical_payment',
                'payment_date' => now()->subDays(2)->toDateString(),
                'amount' => 1000,
                'settlement_discount' => 0,
                'payment_method_id' => $method->id,
            ],
        );

        $response->assertRedirect(route('sales.invoices.show', $invoice));
        $this->assertSame(1, Payment::query()->where('customer_id', $invoice->customer_id)->count());
    }

    public function test_non_admin_cannot_record_historical_payment(): void
    {
        [$admin, $invoice, $method] = $this->seedPostedCreditSale();
        unset($admin);

        $staff = User::factory()->create([
            'role' => 'sales',
            'is_active' => true,
            'branch_id' => $invoice->branch_id,
        ]);

        $response = $this->actingAs($staff)->post(
            route('sales.invoices.historical-payments.store', $invoice),
            [
                '_form' => 'historical_payment',
                'payment_date' => now()->subDays(5)->toDateString(),
                'amount' => 1000,
                'settlement_discount' => 0,
                'payment_method_id' => $method->id,
            ],
        );

        $response->assertForbidden();
        $invoice->refresh();
        $this->assertSame(5000.0, (float) $invoice->amount_due);
        $this->assertSame(0.0, (float) $invoice->amount_paid);
    }

    public function test_historical_payment_date_cannot_be_before_invoice_date(): void
    {
        [$admin, $invoice, $method] = $this->seedPostedCreditSale();

        $response = $this->actingAs($admin)->post(
            route('sales.invoices.historical-payments.store', $invoice),
            [
                '_form' => 'historical_payment',
                'payment_date' => now()->subDays(30)->toDateString(),
                'amount' => 500,
                'settlement_discount' => 0,
                'payment_method_id' => $method->id,
            ],
        );

        $response->assertSessionHasErrors('payment_date');
    }

    public function test_paid_imported_invoice_with_header_totals_but_no_payment_rows_allows_backfill(): void
    {
        [$admin, $invoice, $method] = $this->seedPostedCreditSale();

        $invoice->update([
            'amount_paid' => 5000,
            'amount_due' => 0,
            'payment_status' => 'paid',
        ]);

        $this->assertFalse($invoice->fresh()->hasInboundPaymentTransactions());
        $this->assertTrue($invoice->fresh()->allowsAdminHistoricalPaymentBackfill());
        $this->assertSame(5000.0, $invoice->fresh()->maxHistoricalPaymentAmount());

        $response = $this->actingAs($admin)->post(
            route('sales.invoices.historical-payments.store', $invoice),
            [
                '_form' => 'historical_payment',
                'payment_date' => now()->subDays(2)->toDateString(),
                'amount' => 5000,
                'settlement_discount' => 0,
                'payment_method_id' => $method->id,
            ],
        );

        $response->assertRedirect(route('sales.invoices.show', $invoice));
        $this->assertSame(1, Payment::query()->where('customer_id', $invoice->customer_id)->count());
    }

    public function test_admin_can_backfill_missing_transaction_on_paid_invoice_with_wallet_gl(): void
    {
        [$admin, $invoice, $method, $walletLedger] = $this->seedPostedCreditSale();

        $invoice->update([
            'amount_paid' => 5000,
            'amount_due' => 0,
            'payment_status' => 'paid',
        ]);

        $backDate = now()->subDays(3)->toDateString();

        $response = $this->actingAs($admin)->post(
            route('sales.invoices.historical-payments.store', $invoice),
            [
                '_form' => 'historical_payment',
                'payment_date' => $backDate,
                'amount' => 5000,
                'settlement_discount' => 0,
                'payment_method_id' => $method->id,
            ],
        );

        $response->assertRedirect(route('sales.invoices.show', $invoice));
        $response->assertSessionHas('status');

        $invoice->refresh();
        $this->assertSame(0.0, (float) $invoice->amount_due);
        $this->assertSame(5000.0, (float) $invoice->amount_paid);
        $this->assertSame('paid', $invoice->payment_status);

        $payment = Payment::query()->where('customer_id', $invoice->customer_id)->first();
        $this->assertNotNull($payment);
        $this->assertTrue($payment->isHistoricalPayment());
        $this->assertFalse($payment->skipsWalletGl());
        $this->assertTrue($payment->isPaymentBackfill());
        $this->assertSame($backDate, $payment->payment_date->toDateString());

        $journalEntry = JournalEntry::query()
            ->where('source', 'sales_payment')
            ->where('reference_type', Payment::class)
            ->where('reference_id', $payment->id)
            ->first();

        $this->assertNotNull($journalEntry);
        $this->assertSame($backDate, $journalEntry->entry_date->toDateString());

        $this->assertSame(
            5000.0,
            (float) JournalLine::query()->where('journal_entry_id', $journalEntry->id)->where('ledger_account_id', $walletLedger->id)->sum('debit'),
        );

        $walletBalance = (float) JournalLine::query()
            ->where('ledger_account_id', $walletLedger->id)
            ->selectRaw('COALESCE(SUM(debit), 0) - COALESCE(SUM(credit), 0) as balance')
            ->value('balance');

        $this->assertSame(5000.0, $walletBalance);
    }

    public function test_historical_payment_can_skip_wallet_gl(): void
    {
        [$admin, $invoice, $method, $walletLedger] = $this->seedPostedCreditSale();

        $backDate = now()->subDays(3)->toDateString();

        $response = $this->actingAs($admin)->post(
            route('sales.invoices.historical-payments.store', $invoice),
            [
                '_form' => 'historical_payment',
                'payment_date' => $backDate,
                'amount' => 1500,
                'settlement_discount' => 0,
                'payment_method_id' => $method->id,
                'skip_wallet_gl' => 1,
            ],
        );

        $response->assertRedirect(route('sales.invoices.show', $invoice));
        $response->assertSessionHasNoErrors();

        $payment = Payment::query()->where('customer_id', $invoice->customer_id)->first();
        $this->assertNotNull($payment);
        $this->assertTrue($payment->skipsWalletGl());

        $walletBalance = (float) JournalLine::query()
            ->where('ledger_account_id', $walletLedger->id)
            ->selectRaw('COALESCE(SUM(debit), 0) - COALESCE(SUM(credit), 0) as balance')
            ->value('balance');

        $this->assertSame(0.0, $walletBalance);

        $clearingCode = LedgerAccountCodes::HISTORICAL_PAYMENT_CLEARING;
        $clearingLedger = LedgerAccount::query()->where('code', $clearingCode)->first();
        $this->assertNotNull($clearingLedger);
        $this->assertSame(
            1500.0,
            (float) JournalLine::query()->where('ledger_account_id', $clearingLedger->id)->sum('debit'),
        );
    }

    public function test_historical_installment_advance_does_not_allocate_to_schedules(): void
    {
        [$admin, $invoice, $method] = $this->seedActiveInstallmentWithPlan();

        $response = $this->actingAs($admin)->post(
            route('sales.invoices.historical-payments.store', $invoice),
            [
                '_form' => 'historical_payment',
                'payment_date' => now()->subDays(2)->toDateString(),
                'amount' => 2000,
                'settlement_discount' => 0,
                'payment_method_id' => $method->id,
                'installment_target' => 'advance',
                'skip_wallet_gl' => 1,
            ],
        );

        $response->assertRedirect(route('sales.invoices.show', $invoice));
        $response->assertSessionHasNoErrors();

        $payment = Payment::query()->where('customer_id', $invoice->customer_id)->first();
        $this->assertNotNull($payment);
        $this->assertSame(\App\Services\InstallmentAdvanceService::REFERENCE, $payment->reference_no);
        $this->assertSame(0, $payment->installmentAllocations()->count());

        $invoice->refresh();
        $this->assertSame(0.0, (float) $invoice->amount_paid);
        $this->assertSame(10000.0, (float) $invoice->amount_due);
        $this->assertSame('unpaid', $invoice->payment_status);

        $schedulePaid = (float) $invoice->installmentPlan->schedules()->sum('paid_amount');
        $this->assertSame(0.0, $schedulePaid);
    }

    public function test_historical_installment_waterfall_does_not_change_invoice_due(): void
    {
        [$admin, $invoice, $method] = $this->seedActiveInstallmentWithPlan();

        $response = $this->actingAs($admin)->post(
            route('sales.invoices.historical-payments.store', $invoice),
            [
                '_form' => 'historical_payment',
                'payment_date' => now()->subDays(2)->toDateString(),
                'amount' => 2000,
                'settlement_discount' => 0,
                'payment_method_id' => $method->id,
                'installment_target' => 'waterfall',
            ],
        );

        $response->assertRedirect(route('sales.invoices.show', $invoice));
        $response->assertSessionHasNoErrors();

        $invoice->refresh();
        $this->assertSame(0.0, (float) $invoice->amount_paid);
        $this->assertSame(10000.0, (float) $invoice->amount_due);
        $this->assertSame(0.0, (float) $invoice->installmentPlan->schedules()->sum('paid_amount'));
    }

    /**
     * @return array{0: User, 1: SalesInvoice, 2: PaymentMethod}
     */
    private function seedActiveInstallmentWithPlan(): array
    {
        [$admin, $invoice, $method] = array_pad($this->seedPostedCreditSale(), 3, null);
        unset($invoice);

        $branch = Location::query()->where('code', 'like', 'BR-HIST-%')->first();
        $customer = Customer::query()->where('phone', '01700005678')->first();

        $invoice = SalesInvoice::create([
            'invoice_number' => 'INV-HIST-INST-'.uniqid(),
            'invoice_date' => now()->subDays(7)->toDateString(),
            'branch_id' => $branch->id,
            'customer_id' => $customer->id,
            'sale_type' => 'installment',
            'installment_down_payment' => 2000,
            'installment_count' => 4,
            'installment_first_due_date' => now()->addMonth()->toDateString(),
            'installment_frequency' => 'monthly',
            'status' => 'active',
            'payment_status' => 'unpaid',
            'subtotal' => 10000,
            'discount_total' => 0,
            'tax_total' => 0,
            'grand_total' => 10000,
            'amount_paid' => 0,
            'amount_due' => 10000,
            'created_by' => $admin->id,
            'posted_by' => $admin->id,
            'posted_at' => now()->subDays(7),
        ]);

        $plan = \App\Models\InstallmentPlan::create([
            'sales_invoice_id' => $invoice->id,
            'customer_id' => $customer->id,
            'total_amount' => 10000,
            'down_payment' => 2000,
            'financed_amount' => 8000,
            'remaining_amount' => 8000,
            'penalty_enabled' => false,
            'penalty_rate' => 0.02,
            'created_by' => $admin->id,
        ]);

        for ($i = 1; $i <= 4; $i++) {
            \App\Models\InstallmentSchedule::create([
                'installment_plan_id' => $plan->id,
                'installment_no' => $i,
                'due_date' => now()->addMonths($i)->toDateString(),
                'due_amount' => 2000,
            ]);
        }

        return [$admin, $invoice, $method];
    }

    /**
     * @return array{0: User, 1: SalesInvoice, 2: PaymentMethod, 3: LedgerAccount}
     */
    private function seedPostedCreditSale(): array
    {
        $this->seedCoreLedgerAccounts();

        $branch = Location::create([
            'code' => 'BR-HIST-'.uniqid(),
            'name' => 'Historical Pay Branch',
            'type' => 'branch',
            'is_active' => true,
        ]);

        $admin = User::factory()->create([
            'role' => 'admin',
            'is_active' => true,
            'branch_id' => $branch->id,
        ]);

        $walletLedger = LedgerAccount::create([
            'code' => 'W-HIST-'.uniqid(),
            'name' => 'Cash Wallet',
            'type' => 'asset',
            'is_system' => false,
            'is_active' => true,
        ]);

        $wallet = Wallet::create([
            'ledger_account_id' => $walletLedger->id,
            'code' => 'CASH-'.uniqid(),
            'name' => 'Cash',
            'kind' => 'cash',
            'is_active' => true,
        ]);
        $wallet->locations()->sync([$branch->id]);

        $method = PaymentMethod::create([
            'location_id' => $branch->id,
            'code' => 'CASH-PM',
            'name' => 'Cash',
            'type' => 'cash',
            'is_active' => true,
            'created_by' => $admin->id,
        ]);

        PaymentMethodWalletMap::create([
            'location_id' => $branch->id,
            'payment_method_id' => $method->id,
            'wallet_id' => $wallet->id,
        ]);

        $customer = Customer::create([
            'customer_code' => 'C-HIST-'.uniqid(),
            'name' => 'Customer',
            'phone' => '01700005678',
            'is_active' => true,
        ]);

        $grandTotal = 5000.0;
        $invoice = SalesInvoice::create([
            'invoice_number' => 'INV-HIST-'.uniqid(),
            'invoice_date' => now()->subDays(7)->toDateString(),
            'branch_id' => $branch->id,
            'customer_id' => $customer->id,
            'sale_type' => 'credit',
            'status' => 'posted',
            'payment_status' => 'unpaid',
            'subtotal' => $grandTotal,
            'discount_total' => 0,
            'tax_total' => 0,
            'grand_total' => $grandTotal,
            'amount_paid' => 0,
            'amount_due' => $grandTotal,
            'created_by' => $admin->id,
            'posted_by' => $admin->id,
            'posted_at' => now()->subDays(7),
        ]);

        return [$admin, $invoice, $method, $walletLedger];
    }

    private function seedCoreLedgerAccounts(): void
    {
        foreach ([
            [LedgerAccountCodes::ACCOUNTS_RECEIVABLE, 'Accounts Receivable', 'asset'],
            [LedgerAccountCodes::SALES_REVENUE, 'Sales Revenue', 'revenue'],
            [LedgerAccountCodes::HISTORICAL_PAYMENT_CLEARING, 'Historical customer payments (no wallet)', 'asset'],
        ] as [$code, $name, $type]) {
            LedgerAccount::query()->firstOrCreate(
                ['code' => $code],
                ['name' => $name, 'type' => $type, 'is_system' => true, 'is_active' => true],
            );
        }
    }
}
