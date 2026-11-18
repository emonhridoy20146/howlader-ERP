<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Employee;
use App\Models\LedgerAccount;
use App\Models\Location;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\PaymentMethodWalletMap;
use App\Models\SalesInvoice;
use App\Models\User;
use App\Models\Wallet;
use App\Support\LedgerAccountCodes;
use App\Services\PermissionSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PaymentRegisterReferrerEmployeeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionSyncService::class)->syncFromPolicies();
    }

    public function test_payment_register_stores_referrer_employee(): void
    {
        [$user, $invoice, $method] = $this->seedPostedCreditSale(3000.0);

        $employee = Employee::create([
            'employee_code' => 'EMP-PAY-1',
            'first_name' => 'Collector',
            'last_name' => 'Staff',
            'joining_date' => now()->toDateString(),
            'employment_status' => 'active',
            'is_active' => true,
        ]);

        $this->actingAs($user)->post(
            route('payment-register.payments.store', $invoice),
            [
                'amount' => 1000,
                'settlement_discount' => 0,
                'payment_method_id' => $method->id,
                'payment_date' => now()->toDateString(),
                'referrer_employee_id' => $employee->id,
            ],
        )->assertRedirect();

        $payment = Payment::query()->latest('id')->first();

        $this->assertNotNull($payment);
        $this->assertSame($employee->id, $payment->referrer_employee_id);
    }

    /**
     * @return array{0: User, 1: SalesInvoice, 2: PaymentMethod}
     */
    private function seedPostedCreditSale(float $amountDue): array
    {
        $this->seedCoreLedgerAccounts();

        $branch = Location::create([
            'code' => 'BR-PAY-REF',
            'name' => 'Payment Ref Branch',
            'type' => 'branch',
            'is_active' => true,
        ]);

        $user = User::factory()->create([
            'role' => 'admin',
            'is_active' => true,
            'branch_id' => $branch->id,
        ]);

        $walletLedger = LedgerAccount::create([
            'code' => 'W-PAY-REF',
            'name' => 'Cash Wallet',
            'type' => 'asset',
            'is_system' => false,
            'is_active' => true,
        ]);

        $wallet = Wallet::create([
            'ledger_account_id' => $walletLedger->id,
            'code' => 'CASH-PAY-REF',
            'name' => 'Cash',
            'kind' => 'cash',
            'is_active' => true,
        ]);
        $wallet->locations()->sync([$branch->id]);

        $method = PaymentMethod::create([
            'location_id' => $branch->id,
            'code' => 'CASH-PAY-REF',
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
            'customer_code' => 'C-PAY-REF',
            'name' => 'Payment Ref Customer',
            'phone' => '01710000099',
            'is_active' => true,
        ]);

        $invoice = SalesInvoice::create([
            'invoice_number' => 'S-PAY-REF',
            'branch_id' => $branch->id,
            'customer_id' => $customer->id,
            'invoice_date' => now()->toDateString(),
            'credit_due_date' => now()->addDays(30)->toDateString(),
            'sale_type' => 'credit',
            'status' => 'posted',
            'payment_status' => 'unpaid',
            'subtotal' => $amountDue,
            'discount_total' => 0,
            'tax_total' => 0,
            'grand_total' => $amountDue,
            'amount_paid' => 0,
            'amount_due' => $amountDue,
            'created_by' => $user->id,
        ]);

        return [$user, $invoice, $method];
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
