<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\InstallmentPlan;
use App\Models\InstallmentSchedule;
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

class AdminPaymentCollectionFlexibilityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionSyncService::class)->syncFromPolicies();
    }

    public function test_admin_can_collect_more_than_invoice_due_on_installment_invoice(): void
    {
        [$admin, $invoice, $method, $scheduleOne] = $this->seedInstallmentInvoiceWithPaidFirstSchedule();

        $response = $this->actingAs($admin)->post(
            route('payment-register.payments.store', $invoice),
            [
                'amount' => 1500,
                'settlement_discount' => 0,
                'payment_method_id' => $method->id,
                'payment_date' => now()->toDateString(),
                'installment_target' => 'schedule',
                'installment_schedule_id' => $scheduleOne->id,
            ],
        );

        $response->assertRedirect();
        $response->assertSessionHasNoErrors();

        $scheduleOne->refresh();
        $this->assertSame(7365.0, (float) $scheduleOne->paid_amount);
    }

    public function test_non_admin_cannot_collect_more_than_invoice_due(): void
    {
        [$admin, $invoice, $method, $scheduleFive] = $this->seedInstallmentInvoiceWithPaidFirstSchedule();
        unset($admin);

        $staff = User::factory()->create([
            'role' => 'sales',
            'is_active' => true,
            'branch_id' => $invoice->branch_id,
        ]);
        $this->grantSalesInvoiceView($staff, (int) $invoice->branch_id);

        $response = $this->actingAs($staff)->post(
            route('payment-register.payments.store', $invoice),
            [
                'amount' => 1500,
                'settlement_discount' => 0,
                'payment_method_id' => $method->id,
                'payment_date' => now()->toDateString(),
                'installment_target' => 'schedule',
                'installment_schedule_id' => $scheduleFive->id,
            ],
        );

        $response->assertRedirect();
        $response->assertSessionHasErrors('amount');
    }

    public function test_non_admin_cannot_target_already_paid_installment(): void
    {
        [$admin, $invoice, $method, $scheduleOne] = $this->seedInstallmentInvoiceWithPaidFirstSchedule();
        unset($admin);

        $staff = User::factory()->create([
            'role' => 'sales',
            'is_active' => true,
            'branch_id' => $invoice->branch_id,
        ]);
        $this->grantSalesInvoiceView($staff, (int) $invoice->branch_id);

        $response = $this->actingAs($staff)->post(
            route('payment-register.payments.store', $invoice),
            [
                'amount' => 500,
                'settlement_discount' => 0,
                'payment_method_id' => $method->id,
                'payment_date' => now()->toDateString(),
                'installment_target' => 'schedule',
                'installment_schedule_id' => $scheduleOne->id,
            ],
        );

        $response->assertRedirect();
        $response->assertSessionHasErrors('installment_schedule_id');
    }

    /**
     * @return array{0: User, 1: SalesInvoice, 2: PaymentMethod, 3: InstallmentSchedule, 4: InstallmentSchedule}
     */
    private function seedInstallmentInvoiceWithPaidFirstSchedule(): array
    {
        $this->seedCoreLedgerAccounts();

        $branch = Location::create([
            'code' => 'BR-ADMIN-PAY',
            'name' => 'Admin Pay Branch',
            'type' => 'branch',
            'is_active' => true,
        ]);

        $admin = User::factory()->create([
            'role' => 'admin',
            'is_active' => true,
            'branch_id' => $branch->id,
        ]);

        $walletLedger = LedgerAccount::create([
            'code' => 'W-ADMIN-'.uniqid(),
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
            'customer_code' => 'C-ADMIN-PAY',
            'name' => 'Installment Customer',
            'phone' => '01700002060',
            'is_active' => true,
        ]);

        $invoice = SalesInvoice::create([
            'invoice_number' => 'S-25073',
            'invoice_date' => now()->subMonths(2)->toDateString(),
            'branch_id' => $branch->id,
            'customer_id' => $customer->id,
            'sale_type' => 'installment',
            'status' => 'active',
            'payment_status' => 'partial',
            'subtotal' => 41794,
            'discount_total' => 0,
            'tax_total' => 0,
            'grand_total' => 41794,
            'amount_paid' => 41100,
            'amount_due' => 694,
            'installment_down_payment' => 0,
            'installment_count' => 5,
            'created_by' => $admin->id,
            'posted_by' => $admin->id,
            'posted_at' => now()->subMonths(2),
        ]);

        $plan = InstallmentPlan::create([
            'sales_invoice_id' => $invoice->id,
            'customer_id' => $customer->id,
            'total_amount' => 41794,
            'down_payment' => 0,
            'financed_amount' => 41794,
            'remaining_amount' => 694,
            'status' => 'active',
            'created_by' => $admin->id,
        ]);

        $scheduleOne = InstallmentSchedule::create([
            'installment_plan_id' => $plan->id,
            'installment_no' => 1,
            'due_date' => now()->subMonths(2)->toDateString(),
            'due_amount' => 5865,
            'paid_amount' => 5865,
            'status' => 'paid',
        ]);

        InstallmentSchedule::create([
            'installment_plan_id' => $plan->id,
            'installment_no' => 2,
            'due_date' => now()->subMonth()->toDateString(),
            'due_amount' => 6558,
            'paid_amount' => 6558,
            'status' => 'paid',
        ]);

        InstallmentSchedule::create([
            'installment_plan_id' => $plan->id,
            'installment_no' => 3,
            'due_date' => now()->subWeeks(3)->toDateString(),
            'due_amount' => 6559,
            'paid_amount' => 6559,
            'status' => 'paid',
        ]);

        InstallmentSchedule::create([
            'installment_plan_id' => $plan->id,
            'installment_no' => 4,
            'due_date' => now()->subWeeks(2)->toDateString(),
            'due_amount' => 6559,
            'paid_amount' => 6559,
            'status' => 'paid',
        ]);

        $scheduleFive = InstallmentSchedule::create([
            'installment_plan_id' => $plan->id,
            'installment_no' => 5,
            'due_date' => now()->subWeek()->toDateString(),
            'due_amount' => 7253,
            'paid_amount' => 6559,
            'status' => 'partially_paid',
        ]);

        return [$admin, $invoice, $method, $scheduleOne, $scheduleFive];
    }

    private function grantSalesInvoiceView(User $user, int $branchId): void
    {
        $role = Role::query()->create([
            'slug' => 'pay-reg-'.uniqid(),
            'name' => 'Payment register sales',
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
