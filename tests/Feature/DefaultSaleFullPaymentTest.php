<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\LedgerAccount;
use App\Models\Location;
use App\Models\PaymentMethod;
use App\Models\PaymentMethodWalletMap;
use App\Models\SalesInvoice;
use App\Models\User;
use App\Models\Wallet;
use App\Models\Role;
use App\Models\Permission;
use App\Support\LedgerAccountCodes;
use App\Services\PermissionSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DefaultSaleFullPaymentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionSyncService::class)->syncFromPolicies();
    }

    public function test_payment_register_rejects_partial_payment_on_cash_sale(): void
    {
        [$user, $invoice, $method] = $this->seedPostedDefaultSale(5000.0);
        $this->grantSalesInvoiceView($user, (int) $invoice->branch_id);

        $response = $this->actingAs($user)->post(
            route('payment-register.payments.store', $invoice),
            [
                'amount' => 2000,
                'settlement_discount' => 0,
                'payment_method_id' => $method->id,
                'payment_date' => now()->toDateString(),
            ],
        );

        $response->assertRedirect();
        $response->assertSessionHasErrors('amount');
        $invoice->refresh();
        $this->assertSame(5000.0, (float) $invoice->amount_due);
        $this->assertSame(0.0, (float) $invoice->amount_paid);
    }

    public function test_credit_sale_still_allows_partial_payment(): void
    {
        [$user, $invoice, $method] = $this->seedPostedDefaultSale(5000.0, 'credit');
        $admin = User::factory()->create([
            'role' => 'admin',
            'is_active' => true,
            'branch_id' => $invoice->branch_id,
        ]);

        $response = $this->actingAs($admin)->post(
            route('payment-register.payments.store', $invoice),
            [
                'amount' => 2000,
                'settlement_discount' => 0,
                'payment_method_id' => $method->id,
                'payment_date' => now()->toDateString(),
            ],
        );

        $response->assertRedirect();
        $response->assertSessionHasNoErrors();
        $invoice->refresh();
        $this->assertSame(3000.0, (float) $invoice->amount_due);
        $this->assertSame(2000.0, (float) $invoice->amount_paid);
    }

    public function test_non_admin_cannot_apply_settlement_discount_on_payment_register(): void
    {
        [$user, $invoice, $method] = $this->seedPostedDefaultSale(5000.0, 'credit');
        $this->grantSalesInvoiceView($user, (int) $invoice->branch_id);

        $this->actingAs($user)
            ->get(route('payment-register.index', ['invoice' => $invoice->id]))
            ->assertOk()
            ->assertDontSee('Tap to set — reduces balance without extra cash', false);

        $response = $this->actingAs($user)->post(
            route('payment-register.payments.store', $invoice),
            [
                'amount' => 4000,
                'settlement_discount' => 1000,
                'payment_method_id' => $method->id,
                'payment_date' => now()->toDateString(),
            ],
        );

        $response->assertRedirect();
        $response->assertSessionHasNoErrors();
        $invoice->refresh();
        $this->assertSame(4000.0, (float) $invoice->amount_paid);
        $this->assertSame(1000.0, (float) $invoice->amount_due);
        $this->assertSame(0.0, (float) $invoice->displaySettlementDiscountApplied());
    }

    /**
     * @return array{0: User, 1: SalesInvoice, 2: PaymentMethod}
     */
    private function seedPostedDefaultSale(float $amountDue, string $saleType = 'default'): array
    {
        $this->seedCoreLedgerAccounts();

        $branch = Location::create([
            'code' => 'BR-FULL-'.uniqid(),
            'name' => 'Full Pay Branch',
            'type' => 'branch',
            'is_active' => true,
        ]);

        $user = User::factory()->create([
            'role' => 'sales',
            'is_active' => true,
            'branch_id' => $branch->id,
        ]);

        $walletLedger = LedgerAccount::create([
            'code' => 'W-FULL-'.uniqid(),
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
            'created_by' => $user->id,
        ]);

        PaymentMethodWalletMap::create([
            'location_id' => $branch->id,
            'payment_method_id' => $method->id,
            'wallet_id' => $wallet->id,
        ]);

        $customer = Customer::create([
            'customer_code' => 'C-FULL-'.uniqid(),
            'name' => 'Customer',
            'phone' => '01700001234',
            'is_active' => true,
        ]);

        $grandTotal = $amountDue;
        $invoice = SalesInvoice::create([
            'invoice_number' => 'INV-FULL-'.uniqid(),
            'invoice_date' => now()->toDateString(),
            'branch_id' => $branch->id,
            'customer_id' => $customer->id,
            'sale_type' => $saleType,
            'status' => 'posted',
            'payment_status' => 'unpaid',
            'subtotal' => $grandTotal,
            'discount_total' => 0,
            'tax_total' => 0,
            'grand_total' => $grandTotal,
            'amount_paid' => 0,
            'amount_due' => $amountDue,
            'created_by' => $user->id,
            'posted_by' => $user->id,
            'posted_at' => now(),
        ]);

        return [$user, $invoice, $method];
    }

    private function grantSalesInvoiceView(User $user, int $branchId): void
    {
        $role = Role::query()->create([
            'slug' => 'full-pay-'.uniqid(),
            'name' => 'Full pay sales',
        ]);

        $role->permissions()->sync(
            Permission::query()
                ->whereIn('slug', ['sales-invoice.view', 'sales-invoice.view-any'])
                ->pluck('id')
                ->all(),
        );

        $user->roles()->sync([$role->id]);
        $user->accessibleLocations()->sync([$branchId]);
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
