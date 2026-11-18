<?php

namespace Tests\Feature;

use App\Models\Customer;
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
use App\Services\JournalService;
use App\Support\LedgerAccountCodes;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RepairHistoricalPaymentWalletGlCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(\App\Services\PermissionSyncService::class)->syncFromPolicies();
    }

    public function test_command_creates_wallet_gl_when_journal_is_missing(): void
    {
        [$admin, $invoice, $method, $walletLedger, $wallet] = $this->seedFixture();
        $payment = $this->createHistoricalPayment($invoice, $method, $admin, 1200.0);

        $this->assertNull(
            JournalEntry::query()
                ->where('source', 'sales_payment')
                ->where('reference_type', Payment::class)
                ->where('reference_id', $payment->id)
                ->first(),
        );

        $this->artisan('payments:repair-historical-wallet-gl')
            ->assertSuccessful()
            ->expectsOutputToContain('Created wallet GL');

        $payment->refresh();
        $this->assertSame((int) $wallet->id, (int) $payment->wallet_id);

        $entry = JournalEntry::query()
            ->where('source', 'sales_payment')
            ->where('reference_type', Payment::class)
            ->where('reference_id', $payment->id)
            ->first();

        $this->assertNotNull($entry);
        $this->assertSame(
            1200.0,
            (float) JournalLine::query()->where('journal_entry_id', $entry->id)->where('ledger_account_id', $walletLedger->id)->sum('debit'),
        );

        $clearing = LedgerAccount::query()->where('code', LedgerAccountCodes::HISTORICAL_PAYMENT_CLEARING)->first();
        $this->assertNotNull($clearing);
        $this->assertSame(
            1200.0,
            (float) JournalLine::query()->where('journal_entry_id', $entry->id)->where('ledger_account_id', $clearing->id)->sum('credit'),
        );
    }

    public function test_command_skips_historical_payments_that_already_have_journal_entries(): void
    {
        [$admin, $invoice, $method, $walletLedger, $wallet] = $this->seedFixture();
        $payment = $this->createHistoricalPayment($invoice, $method, $admin, 1200.0);

        app(JournalService::class)->postHistoricalCustomerPaymentGl(
            (int) $invoice->branch_id,
            1200.0,
            $payment,
            (int) $admin->id,
        );

        $entryBefore = JournalEntry::query()
            ->where('source', 'sales_payment')
            ->where('reference_type', Payment::class)
            ->where('reference_id', $payment->id)
            ->first();

        $this->artisan('payments:repair-historical-wallet-gl', ['-v' => true])
            ->assertSuccessful()
            ->expectsOutputToContain('Skipped');

        $this->assertSame(
            (int) $entryBefore->id,
            (int) JournalEntry::query()
                ->where('source', 'sales_payment')
                ->where('reference_type', Payment::class)
                ->where('reference_id', $payment->id)
                ->value('id'),
        );

        $this->assertSame(
            0.0,
            (float) JournalLine::query()->where('ledger_account_id', $walletLedger->id)->sum('debit'),
        );
    }

    public function test_command_sets_wallet_id_when_journal_already_debits_wallet(): void
    {
        [$admin, $invoice, $method, $walletLedger, $wallet] = $this->seedFixture();
        $payment = $this->createHistoricalPayment($invoice, $method, $admin, 800.0);

        app(JournalService::class)->postHistoricalPaymentToWalletGl(
            (int) $invoice->branch_id,
            800.0,
            (int) $method->id,
            $payment,
            (int) $admin->id,
        );

        $payment->update(['wallet_id' => null]);

        $this->artisan('payments:repair-historical-wallet-gl', ['-v' => true])
            ->assertSuccessful();

        $payment->refresh();
        $this->assertSame((int) $wallet->id, (int) $payment->wallet_id);
        $this->assertSame(
            800.0,
            (float) JournalLine::query()->where('ledger_account_id', $walletLedger->id)->sum('debit'),
        );
    }

    public function test_command_skips_when_wallet_ar_journal_already_exists(): void
    {
        [$admin, $invoice, $method, $walletLedger] = $this->seedFixture();
        $payment = $this->createHistoricalPayment($invoice, $method, $admin, 650.0);

        app(JournalService::class)->postCustomerPaymentGl(
            (int) $invoice->branch_id,
            650.0,
            (int) $method->id,
            $payment,
            (int) $admin->id,
            requireWallet: true,
        );

        $entryId = (int) JournalEntry::query()
            ->where('source', 'sales_payment')
            ->where('reference_type', Payment::class)
            ->where('reference_id', $payment->id)
            ->value('id');

        $this->artisan('payments:repair-historical-wallet-gl', ['-v' => true])
            ->assertSuccessful()
            ->expectsOutputToContain('Skipped');

        $this->assertSame(
            $entryId,
            (int) JournalEntry::query()
                ->where('source', 'sales_payment')
                ->where('reference_type', Payment::class)
                ->where('reference_id', $payment->id)
                ->value('id'),
        );

        $this->assertSame(
            650.0,
            (float) JournalLine::query()->where('ledger_account_id', $walletLedger->id)->sum('debit'),
        );
    }

    public function test_dry_run_leaves_existing_journal_unchanged(): void
    {
        [$admin, $invoice, $method] = $this->seedFixture();
        $payment = $this->createHistoricalPayment($invoice, $method, $admin, 900.0);

        $this->artisan('payments:repair-historical-wallet-gl', ['--dry-run' => true])
            ->assertSuccessful()
            ->expectsOutputToContain('Would create');

        $this->assertNull(
            JournalEntry::query()
                ->where('source', 'sales_payment')
                ->where('reference_type', Payment::class)
                ->where('reference_id', $payment->id)
                ->first(),
        );
    }

    public function test_command_skips_skip_wallet_historical_payments(): void
    {
        [$admin, $invoice, $method, $walletLedger] = $this->seedFixture();

        $payment = Payment::create([
            'payment_number' => 'PAY-SKIP-'.uniqid(),
            'customer_id' => $invoice->customer_id,
            'payment_date' => now()->subDay()->toDateString(),
            'payment_method' => 'cash',
            'payment_method_id' => $method->id,
            'amount' => 500,
            'direction' => 'inbound',
            'notes' => Payment::formatSkipWalletGlNotes(null, true),
            'recorded_by' => $admin->id,
        ]);

        PaymentAllocation::create([
            'payment_id' => $payment->id,
            'sales_invoice_id' => $invoice->id,
            'allocated_amount' => 500,
            'settlement_discount' => 0,
        ]);

        app(JournalService::class)->postHistoricalCustomerPaymentGl(
            (int) $invoice->branch_id,
            500.0,
            $payment,
            (int) $admin->id,
        );

        $this->artisan('payments:repair-historical-wallet-gl', ['-v' => true])
            ->assertSuccessful();

        $this->assertSame(
            0.0,
            (float) JournalLine::query()->where('ledger_account_id', $walletLedger->id)->sum('debit'),
        );
    }

    /**
     * @return array{0: User, 1: SalesInvoice, 2: PaymentMethod, 3: LedgerAccount, 4: Wallet}
     */
    private function seedFixture(): array
    {
        foreach ([
            [LedgerAccountCodes::ACCOUNTS_RECEIVABLE, 'Accounts Receivable', 'asset'],
            [LedgerAccountCodes::HISTORICAL_PAYMENT_CLEARING, 'Historical customer payments (no wallet)', 'asset'],
        ] as [$code, $name, $type]) {
            LedgerAccount::query()->firstOrCreate(
                ['code' => $code],
                ['name' => $name, 'type' => $type, 'is_system' => true, 'is_active' => true],
            );
        }

        $branch = Location::create([
            'code' => 'BR-'.uniqid(),
            'name' => 'Main Branch',
            'type' => 'branch',
            'is_active' => true,
        ]);

        $admin = User::factory()->create([
            'role' => 'admin',
            'is_active' => true,
            'branch_id' => $branch->id,
        ]);

        $walletLedger = LedgerAccount::create([
            'code' => 'W-REPAIR-'.uniqid(),
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
            'customer_code' => 'C-REPAIR-'.uniqid(),
            'name' => 'Customer',
            'phone' => '01700009999',
            'is_active' => true,
        ]);

        $invoice = SalesInvoice::create([
            'invoice_number' => 'INV-REPAIR-'.uniqid(),
            'invoice_date' => now()->subDays(5)->toDateString(),
            'branch_id' => $branch->id,
            'customer_id' => $customer->id,
            'sale_type' => 'credit',
            'status' => 'posted',
            'payment_status' => 'unpaid',
            'subtotal' => 5000,
            'discount_total' => 0,
            'tax_total' => 0,
            'grand_total' => 5000,
            'amount_paid' => 0,
            'amount_due' => 5000,
            'created_by' => $admin->id,
            'posted_by' => $admin->id,
            'posted_at' => now()->subDays(5),
        ]);

        return [$admin, $invoice, $method, $walletLedger, $wallet];
    }

    private function createHistoricalPayment(
        SalesInvoice $invoice,
        PaymentMethod $method,
        User $admin,
        float $amount,
    ): Payment {
        $payment = Payment::create([
            'payment_number' => 'PAY-REPAIR-'.uniqid(),
            'customer_id' => $invoice->customer_id,
            'payment_date' => now()->subDays(2)->toDateString(),
            'payment_method' => 'cash',
            'payment_method_id' => $method->id,
            'amount' => $amount,
            'direction' => 'inbound',
            'notes' => Payment::formatHistoricalPaymentNotes(null, true),
            'recorded_by' => $admin->id,
        ]);

        PaymentAllocation::create([
            'payment_id' => $payment->id,
            'sales_invoice_id' => $invoice->id,
            'allocated_amount' => $amount,
            'settlement_discount' => 0,
        ]);

        return $payment;
    }
}
