<?php

namespace Tests\Feature\FieldApi;

use App\Models\Customer;
use App\Models\Employee;
use App\Models\InstallmentPlan;
use App\Models\InstallmentSchedule;
use App\Models\LedgerAccount;
use App\Models\Location;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\PaymentMethod;
use App\Models\PaymentMethodWalletMap;
use App\Models\Permission;
use App\Models\Role;
use App\Models\SalesInvoice;
use App\Models\User;
use App\Models\Wallet;
use App\Services\PaymentService;
use App\Support\LedgerAccountCodes;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class FieldApiTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'test-field-token-0123456789';

    private Location $branch;

    private User $integration;

    private Employee $collector;

    private Customer $customer;

    private SalesInvoice $invoice;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setTimezone('Asia/Dhaka')->setTime(11, 0));

        $this->branch = Location::create(['code' => 'FB1', 'name' => 'Field Branch', 'type' => 'branch', 'is_active' => true]);
        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);

        $perm = Permission::query()->firstOrCreate(['slug' => 'payment.create'], ['name' => 'Payment Create']);
        $role = Role::query()->firstOrCreate(['slug' => 'field_integration'], ['name' => 'Field Integration']);
        $role->permissions()->sync([$perm->id]);
        $this->integration = User::factory()->create(['is_active' => true, 'role' => null]);
        $this->integration->roles()->sync([$role->id]);

        config([
            'field_api.enabled' => true,
            'field_api.token_sha256' => [hash('sha256', self::TOKEN)],
            'field_api.integration_user_id' => $this->integration->id,
        ]);

        foreach ([[LedgerAccountCodes::ACCOUNTS_RECEIVABLE, 'Accounts Receivable', 'asset'], [LedgerAccountCodes::SALES_REVENUE, 'Sales Revenue', 'revenue']] as [$code, $name, $type]) {
            LedgerAccount::query()->firstOrCreate(['code' => $code], ['name' => $name, 'type' => $type, 'is_system' => true, 'is_active' => true]);
        }
        $ledger = LedgerAccount::create(['code' => 'W-FB1', 'name' => 'Cash Wallet', 'type' => 'asset', 'is_system' => false, 'is_active' => true]);
        $wallet = Wallet::create(['ledger_account_id' => $ledger->id, 'code' => 'FB1-CASH', 'name' => 'Cash', 'kind' => 'cash', 'is_active' => true]);
        $wallet->locations()->sync([$this->branch->id]);
        $method = PaymentMethod::create(['location_id' => $this->branch->id, 'code' => 'FB1-CASH', 'name' => 'Branch Cash', 'type' => 'cash', 'is_active' => true, 'created_by' => $admin->id]);
        PaymentMethodWalletMap::create(['location_id' => $this->branch->id, 'payment_method_id' => $method->id, 'wallet_id' => $wallet->id]);

        $this->collector = Employee::create(['employee_code' => 'EMP-00001', 'first_name' => 'Test', 'last_name' => 'Collector', 'joining_date' => now()->subYear()->toDateString(), 'employment_status' => 'active', 'is_active' => true]);

        // Customer without branch_id: the API falls back to the latest invoice branch.
        $this->customer = Customer::create(['customer_code' => 'CUS-T1', 'name' => 'Test Buyer', 'phone' => '01700000001, 01800000002', 'phone_numbers' => ['01900000003'], 'is_active' => true, 'outstanding_balance' => 3000]);
        $this->invoice = SalesInvoice::create([
            'invoice_number' => 'INV-T1', 'branch_id' => $this->branch->id, 'customer_id' => $this->customer->id, 'invoice_date' => now()->subMonths(2)->toDateString(),
            'sale_type' => 'installment', 'status' => 'active', 'payment_status' => 'partial', 'subtotal' => 4000, 'discount_total' => 0, 'tax_total' => 0,
            'grand_total' => 4000, 'amount_paid' => 1000, 'amount_due' => 3000, 'installment_down_payment' => 1000, 'installment_frequency' => 'monthly',
            'guarantor_name' => 'Test Guarantor', 'guarantor_phone_numbers' => ['01500000004'], 'guarantor_nid_number' => '1234567890', 'created_by' => $admin->id,
        ]);
        $plan = InstallmentPlan::create([
            'sales_invoice_id' => $this->invoice->id, 'customer_id' => $this->customer->id, 'total_amount' => 4000, 'down_payment' => 1000, 'financed_amount' => 3000,
            'remaining_amount' => 3000, 'penalty_enabled' => false, 'penalty_rate' => 0, 'status' => 'active', 'created_by' => $admin->id,
        ]);
        foreach ([1 => -1, 2 => 0, 3 => 1] as $no => $monthOffset) {
            InstallmentSchedule::create(['installment_plan_id' => $plan->id, 'installment_no' => $no, 'due_date' => now()->addMonths($monthOffset)->toDateString(), 'due_amount' => 1000, 'paid_amount' => 0, 'status' => 'pending']);
        }
    }

    private function api(): self
    {
        return $this->withHeaders(['Authorization' => 'Bearer '.self::TOKEN, 'Accept' => 'application/json']);
    }

    /** @return array<string, mixed> */
    private function paymentBody(array $over = []): array
    {
        return array_merge([
            'sales_invoice_id' => $this->invoice->id,
            'customer_id' => $this->customer->id,
            'amount' => '1500.00',
            'collected_at' => now()->toIso8601String(),
            'method_type' => 'cash',
            'collector_employee_id' => $this->collector->id,
            'field_receipt_no' => 'FB1-FS001-0001',
            'field_collection_id' => 'col-1',
        ], $over);
    }

    public function test_mobile_banking_goes_to_the_matching_provider_only(): void
    {
        $admin = User::query()->where('role', 'admin')->firstOrFail();
        $ledger = LedgerAccount::create(['code' => 'W-FB1-BK', 'name' => 'bKash Wallet', 'type' => 'asset', 'is_system' => false, 'is_active' => true]);
        $wallet = Wallet::create(['ledger_account_id' => $ledger->id, 'code' => 'FB1-BKASH', 'name' => 'bKash', 'kind' => 'mobile', 'is_active' => true]);
        $wallet->locations()->sync([$this->branch->id]);
        $method = PaymentMethod::create(['location_id' => $this->branch->id, 'code' => 'FB1-BK', 'name' => 'bKash Merchant', 'type' => 'mobile_banking', 'mobile_provider' => 'bKash', 'is_active' => true, 'created_by' => $admin->id]);
        PaymentMethodWalletMap::create(['location_id' => $this->branch->id, 'payment_method_id' => $method->id, 'wallet_id' => $wallet->id]);

        $this->api()->withHeaders(['Idempotency-Key' => 'k-nagad'])
            ->postJson('/api/field/v1/payments', $this->paymentBody(['method_type' => 'mobile_banking', 'mobile_provider' => 'nagad', 'transaction_ref' => 'NG12345678']))
            ->assertStatus(422)->assertJsonPath('error.code', 'METHOD_NOT_AVAILABLE');
        $this->api()->withHeaders(['Idempotency-Key' => 'k-bkash'])
            ->postJson('/api/field/v1/payments', $this->paymentBody(['method_type' => 'mobile_banking', 'mobile_provider' => 'bkash', 'transaction_ref' => 'BK12345678']))
            ->assertCreated()->assertJsonPath('data.payment_method_id', $method->id);
    }

    public function test_disabled_or_bad_token_is_rejected(): void
    {
        $this->getJson('/api/field/v1/health')->assertStatus(401);
        $this->withHeaders(['Authorization' => 'Bearer wrong'])->getJson('/api/field/v1/health')->assertStatus(401);
        config(['field_api.enabled' => false]);
        $this->api()->getJson('/api/field/v1/health')->assertStatus(404);
    }

    public function test_customers_list_shape_and_branch_fallback(): void
    {
        $res = $this->api()->getJson('/api/field/v1/customers')->assertOk();
        $c = $res->json('data.0');

        $this->assertSame($this->customer->id, $c['id']);
        $this->assertSame($this->branch->id, $c['branch_id']);
        $this->assertSame('last_invoice', $c['branch_source']);
        $this->assertSame(['01900000003'], $c['phone_numbers']);
        $inv = $c['invoices'][0];
        $this->assertTrue($inv['collectable']);
        $this->assertSame('3000.00', $inv['amount_due']);
        $this->assertSame('Test Guarantor', $inv['guarantor']['name']);
        $this->assertArrayNotHasKey('nid', $inv['guarantor']);
        $this->assertStringNotContainsString('1234567890', $res->getContent());
        $this->assertCount(3, $inv['installment_plan']['schedules']);
        $this->assertSame('1000.00', $inv['installment_plan']['schedules'][0]['remaining_amount']);
        $this->assertNull($res->json('meta.next_cursor'));

        $this->api()->getJson('/api/field/v1/customers?branch_id='.$this->branch->id)->assertOk()->assertJsonCount(1, 'data');
        $this->api()->getJson('/api/field/v1/customers?branch_id=999')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_post_payment_is_idempotent_and_follows_erp_rules(): void
    {
        $key = (string) Str::uuid();
        $first = $this->api()->withHeaders(['Idempotency-Key' => $key])->postJson('/api/field/v1/payments', $this->paymentBody())->assertCreated();
        $this->assertFalse($first->json('idempotent_replay'));
        $this->assertSame('1500.00', $first->json('data.invoice_amount_due_after'));
        $this->assertSame([['installment_no' => 1, 'amount' => '1000.00'], ['installment_no' => 2, 'amount' => '500.00']], $first->json('data.allocations'));
        $paymentId = $first->json('data.id');

        $this->api()->withHeaders(['Idempotency-Key' => $key])->postJson('/api/field/v1/payments', $this->paymentBody())
            ->assertOk()->assertJsonPath('idempotent_replay', true)->assertJsonPath('data.id', $paymentId);
        $this->api()->withHeaders(['Idempotency-Key' => $key])->postJson('/api/field/v1/payments', $this->paymentBody(['amount' => '100.00']))
            ->assertStatus(422)->assertJsonPath('error.code', 'IDEMPOTENCY_MISMATCH');
        $this->assertSame(1, Payment::query()->where('direction', 'inbound')->count());

        // ERP rule: one payment per day per installment invoice (integration user lacks the bypass permission).
        $this->api()->withHeaders(['Idempotency-Key' => (string) Str::uuid()])->postJson('/api/field/v1/payments', $this->paymentBody(['amount' => '100.00']))
            ->assertStatus(422)->assertJsonPath('error.code', 'DUPLICATE_SAME_DAY');

        $this->api()->getJson('/api/field/v1/payments/by-idempotency-key/'.$key)->assertOk()->assertJsonPath('data.id', $paymentId);

        $payment = Payment::query()->findOrFail($paymentId);
        $this->assertSame($this->collector->id, (int) $payment->referrer_employee_id);
        $this->assertSame($this->integration->id, (int) $payment->recorded_by);
        $this->assertStringContainsString('[field_app]', (string) $payment->notes);

        $row = collect($this->api()->getJson('/api/field/v1/customers/'.$this->customer->id)->json('data.recent_payments'))->firstWhere('id', $paymentId);
        $this->assertSame('field_app', $row['source']);
        $this->assertSame($key, $row['idempotency_key']);
        $this->assertSame('Test Collector', $row['collector_name']);
    }

    public function test_any_past_date_is_accepted_by_default(): void
    {
        // OD-28 (owner, 2026-11-18): no back-date window unless FIELD_API_MAX_BACKDATE_DAYS is set.
        $this->assertNull(config('field_api.max_backdate_days'));
        $date = now()->subDays(45);
        $res = $this->api()->withHeaders(['Idempotency-Key' => (string) Str::uuid()])
            ->postJson('/api/field/v1/payments', $this->paymentBody(['collected_at' => $date->toIso8601String()]));
        $res->assertStatus(201);
        $this->assertSame($date->toDateString(), $res->json('data.payment_date'));
    }

    public function test_business_errors(): void
    {
        $post = fn (array $over) => $this->api()->withHeaders(['Idempotency-Key' => (string) Str::uuid()])->postJson('/api/field/v1/payments', $this->paymentBody($over));

        $post(['amount' => '3000.01'])->assertStatus(422)->assertJsonPath('error.code', 'AMOUNT_EXCEEDS_OUTSTANDING');
        $post(['method_type' => 'mobile_banking'])->assertStatus(422)->assertJsonPath('error.code', 'METHOD_NOT_AVAILABLE');
        $post(['collector_employee_id' => 99999])->assertStatus(422)->assertJsonPath('error.code', 'COLLECTOR_NOT_LINKED');
        config(['field_api.max_backdate_days' => 7]);
        $post(['collected_at' => now()->subDays(30)->toIso8601String()])->assertStatus(423)->assertJsonPath('error.code', 'PERIOD_LOCKED');
        config(['field_api.max_backdate_days' => null]);
        $post(['collected_at' => now()->addDay()->toIso8601String()])->assertStatus(422)->assertJsonPath('error.code', 'VALIDATION_FAILED');
        $post(['customer_id' => 99999])->assertStatus(404)->assertJsonPath('error.code', 'PLAN_NOT_FOUND');
        $this->invoice->update(['status' => 'returned']);
        $post([])->assertStatus(422)->assertJsonPath('error.code', 'PLAN_CLOSED');
        $this->invoice->update(['status' => 'active', 'amount_due' => 0]);
        $post([])->assertStatus(422)->assertJsonPath('error.code', 'PLAN_SETTLED');
        $this->assertSame(0, Payment::query()->count());
    }

    public function test_office_removal_shows_up_as_removed_payment_with_its_key(): void
    {
        $key = (string) Str::uuid();
        $paymentId = $this->api()->withHeaders(['Idempotency-Key' => $key])->postJson('/api/field/v1/payments', $this->paymentBody())->assertCreated()->json('data.id');

        $this->travel(1)->minutes();
        $allocation = PaymentAllocation::query()->where('payment_id', $paymentId)->firstOrFail();
        app(PaymentService::class)->removeAllocationFromInvoice($this->invoice->fresh(), $allocation, $this->integration->id);

        $since = now()->subSeconds(30)->toIso8601String();
        $data = $this->api()->getJson('/api/field/v1/customers?updated_since='.urlencode($since))->assertOk()->json('data');
        $this->assertCount(1, $data);
        $this->assertSame([], $data[0]['recent_payments']);
        $this->assertSame($paymentId, $data[0]['removed_payments'][0]['id']);
        $this->assertSame($key, $data[0]['removed_payments'][0]['idempotency_key']);
        $this->assertSame('1500.00', $data[0]['removed_payments'][0]['amount']);
        $this->assertSame(now()->toDateString(), $data[0]['removed_payments'][0]['payment_date']);
    }
}
