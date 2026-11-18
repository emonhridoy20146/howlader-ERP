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
use App\Services\PaymentService;
use App\Services\PermissionSyncService;
use App\Support\LedgerAccountCodes;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class JournalEntryPaymentRemovalTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionSyncService::class)->syncFromPolicies();
    }

    public function test_admin_can_remove_payment_from_journal_entry_show(): void
    {
        [$admin, $invoice, $method] = $this->seedPostedCreditSaleWithPayment(2000);

        $payment = Payment::query()->where('customer_id', $invoice->customer_id)->firstOrFail();
        $journalEntry = JournalEntry::query()
            ->where('source', 'sales_payment')
            ->where('reference_type', Payment::class)
            ->where('reference_id', $payment->id)
            ->firstOrFail();

        $invoice->refresh();
        $this->assertSame(2000.0, (float) $invoice->amount_paid);
        $this->assertSame(3000.0, (float) $invoice->amount_due);

        $this->actingAs($admin)
            ->delete(route('journal-entries.linked-payment.destroy', $journalEntry))
            ->assertRedirect(route('journal-entries.index'))
            ->assertSessionHas('status');

        $this->assertNull(Payment::query()->find($payment->id));
        $this->assertNull(JournalEntry::query()->find($journalEntry->id));

        $invoice->refresh();
        $this->assertSame(0.0, (float) $invoice->amount_paid);
        $this->assertSame(5000.0, (float) $invoice->amount_due);
        $this->assertSame('unpaid', $invoice->payment_status);
    }

    public function test_user_without_permission_cannot_remove_payment_from_journal_entry(): void
    {
        [$admin, $invoice] = $this->seedPostedCreditSaleWithPayment(1500);
        unset($admin);

        $payment = Payment::query()->where('customer_id', $invoice->customer_id)->firstOrFail();
        $journalEntry = JournalEntry::query()
            ->where('source', 'sales_payment')
            ->where('reference_id', $payment->id)
            ->firstOrFail();

        $staff = User::factory()->create([
            'role' => 'sales',
            'is_active' => true,
            'branch_id' => $invoice->branch_id,
        ]);
        $staff->accessibleLocations()->sync([$invoice->branch_id]);

        $this->actingAs($staff)
            ->delete(route('journal-entries.linked-payment.destroy', $journalEntry))
            ->assertForbidden();

        $this->assertNotNull(Payment::query()->find($payment->id));
    }

    public function test_linked_payment_removal_resolves_when_payment_record_missing(): void
    {
        [$admin, $invoice] = $this->seedPostedCreditSaleWithPayment(2000);
        unset($admin);

        $payment = Payment::query()->where('customer_id', $invoice->customer_id)->firstOrFail();
        $allocation = PaymentAllocation::query()->where('payment_id', $payment->id)->firstOrFail();
        $journalEntry = JournalEntry::query()
            ->where('source', 'sales_payment')
            ->where('reference_id', $payment->id)
            ->firstOrFail();

        $journalEntry->setRelation('reference', null);

        $target = $journalEntry->linkedPaymentRemovalTarget();
        $this->assertNotNull($target);
        $this->assertSame($allocation->id, $target['allocation']->id);
        $this->assertSame($invoice->id, $target['invoice']->id);
    }

    /**
     * @return array{0: User, 1: SalesInvoice, 2: PaymentMethod}
     */
    private function seedPostedCreditSaleWithPayment(float $amount): array
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

        $branch = Location::create([
            'code' => 'BR-JE-PAY-'.uniqid(),
            'name' => 'Journal Payment Branch',
            'type' => 'branch',
            'is_active' => true,
        ]);

        $admin = User::factory()->create([
            'role' => 'admin',
            'is_active' => true,
            'branch_id' => $branch->id,
        ]);
        $admin->accessibleLocations()->sync([$branch->id]);

        $walletLedger = LedgerAccount::create([
            'code' => 'W-JE-PAY-'.uniqid(),
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
            'customer_code' => 'C-JE-PAY-'.uniqid(),
            'name' => 'Customer',
            'phone' => '01700001234',
            'is_active' => true,
        ]);

        $grandTotal = 5000.0;
        $invoice = SalesInvoice::create([
            'invoice_number' => 'INV-JE-PAY-'.uniqid(),
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

        app(PaymentService::class)->recordAndAllocate($invoice, [
            'amount' => $amount,
            'payment_method_id' => $method->id,
            'date' => now()->toDateString(),
        ], $admin->id);

        return [$admin, $invoice->fresh(), $method];
    }
}
