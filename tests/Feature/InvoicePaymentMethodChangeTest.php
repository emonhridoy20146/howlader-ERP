<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\JournalEntry;
use App\Models\LedgerAccount;
use App\Models\Location;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\PaymentMethod;
use App\Models\PaymentMethodWalletMap;
use App\Models\SalesInvoice;
use App\Models\User;
use App\Models\Wallet;
use App\Support\LedgerAccountCodes;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InvoicePaymentMethodChangeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(\App\Services\PermissionSyncService::class)->syncFromPolicies();
    }

    public function test_posted_invoice_payment_method_can_be_changed(): void
    {
        [$user, $invoice, $cashMethod, $bankMethod, $payment, $bankWallet] = $this->seedPostedInvoiceWithPayment();

        $response = $this->actingAs($user)->patch(
            route('sales.invoices.payments.update-method', [$invoice, $payment]),
            ['payment_method_id' => $bankMethod->id],
        );

        $response->assertRedirect();
        $response->assertSessionHasNoErrors();
        $payment->refresh();
        $this->assertSame($bankMethod->id, (int) $payment->payment_method_id);
        $this->assertSame((int) $bankWallet->id, (int) $payment->wallet_id);
        $this->assertDatabaseHas('journal_entries', [
            'source' => 'sales_payment',
            'reference_type' => Payment::class,
            'reference_id' => $payment->id,
        ]);
    }

    /**
     * @return array{0: User, 1: SalesInvoice, 2: PaymentMethod, 3: PaymentMethod, 4: Payment, 5: Wallet}
     */
    private function seedPostedInvoiceWithPayment(): array
    {
        LedgerAccount::query()->firstOrCreate(
            ['code' => LedgerAccountCodes::ACCOUNTS_RECEIVABLE],
            ['name' => 'Accounts Receivable', 'type' => 'asset', 'is_system' => true, 'is_active' => true],
        );

        $branch = Location::create([
            'code' => 'BR-PM',
            'name' => 'PM Branch',
            'type' => 'branch',
            'is_active' => true,
        ]);

        $user = User::factory()->create([
            'role' => 'admin',
            'is_active' => true,
            'branch_id' => $branch->id,
        ]);

        $cashWallet = $this->createWalletForBranch($branch, 'CASH-W', 'Cash wallet');
        $bankWallet = $this->createWalletForBranch($branch, 'BANK-W', 'Bank wallet');

        $cashMethod = PaymentMethod::create([
            'location_id' => $branch->id,
            'code' => 'CASH',
            'name' => 'Cash',
            'type' => 'cash',
            'is_active' => true,
            'created_by' => $user->id,
        ]);

        $bankMethod = PaymentMethod::create([
            'location_id' => $branch->id,
            'code' => 'BANK',
            'name' => 'Bank',
            'type' => 'bank',
            'is_active' => true,
            'created_by' => $user->id,
        ]);

        PaymentMethodWalletMap::create([
            'location_id' => $branch->id,
            'payment_method_id' => $cashMethod->id,
            'wallet_id' => $cashWallet->id,
        ]);

        PaymentMethodWalletMap::create([
            'location_id' => $branch->id,
            'payment_method_id' => $bankMethod->id,
            'wallet_id' => $bankWallet->id,
        ]);

        $customer = Customer::create([
            'customer_code' => 'C-PM-001',
            'name' => 'Customer',
            'phone' => '01700009999',
            'is_active' => true,
        ]);

        $invoice = SalesInvoice::create([
            'invoice_number' => 'INV-PM-001',
            'invoice_date' => now()->toDateString(),
            'branch_id' => $branch->id,
            'customer_id' => $customer->id,
            'sale_type' => 'credit',
            'status' => 'posted',
            'payment_status' => 'partial',
            'subtotal' => 1000,
            'discount_total' => 0,
            'tax_total' => 0,
            'grand_total' => 1000,
            'amount_paid' => 500,
            'amount_due' => 500,
            'created_by' => $user->id,
        ]);

        $payment = Payment::create([
            'payment_number' => 'PAY-PM-001',
            'customer_id' => $customer->id,
            'payment_date' => now()->toDateString(),
            'payment_method' => 'cash',
            'payment_method_id' => $cashMethod->id,
            'amount' => 500,
            'direction' => 'inbound',
            'recorded_by' => $user->id,
        ]);

        PaymentAllocation::create([
            'payment_id' => $payment->id,
            'sales_invoice_id' => $invoice->id,
            'allocated_amount' => 500,
            'settlement_discount' => 0,
        ]);

        JournalEntry::create([
            'branch_id' => $branch->id,
            'entry_date' => now()->toDateString(),
            'description' => 'Seed payment',
            'source' => 'sales_payment',
            'reference_type' => Payment::class,
            'reference_id' => $payment->id,
            'created_by' => $user->id,
            'status' => 'posted',
        ]);

        return [$user, $invoice, $cashMethod, $bankMethod, $payment, $bankWallet];
    }

    private function createWalletForBranch(Location $branch, string $code, string $name): Wallet
    {
        $ledger = LedgerAccount::create([
            'code' => $code.'-LA',
            'name' => $name.' ledger',
            'type' => 'asset',
            'is_system' => false,
            'is_active' => true,
        ]);

        $wallet = Wallet::create([
            'ledger_account_id' => $ledger->id,
            'code' => $code,
            'name' => $name,
            'kind' => 'cash',
            'is_active' => true,
        ]);
        $wallet->locations()->sync([$branch->id]);

        return $wallet;
    }
}
