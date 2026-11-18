<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\InstallmentPlan;
use App\Models\InstallmentSchedule;
use App\Models\Location;
use App\Models\SalesInvoice;
use App\Models\SmsBatch;
use App\Models\SmsMessage;
use App\Models\User;
use App\Services\Sms\SmsText;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class SmsModuleTest extends TestCase
{
    use RefreshDatabase;

    private bool $faked = false;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'sms.enabled' => true,
            'sms.bulksmsbd.api_key' => 'test-key',
            'sms.bulksmsbd.sender_id_non_masking' => '8809617000000',
            'sms.bulksmsbd.sender_id_masking' => 'HOWLADER',
            'queue.default' => 'sync',
        ]);
    }

    private function fakeProvider(array $sendResponse = ['response_code' => 202, 'success_message' => 'SMS Submitted Successfully', 'error_message' => '']): void
    {
        $this->faked = true;
        Http::fake([
            '*/getBalanceApi*' => Http::response(['balance' => 1234.5]),
            '*/smsapi*' => Http::response($sendResponse),
        ]);
    }

    public function test_phone_normalisation_and_part_counting(): void
    {
        $this->assertSame('8801712345678', SmsText::normalizePhone('01712-345678'));
        $this->assertSame('8801712345678', SmsText::normalizePhone('+8801712345678'));
        $this->assertSame('8801712345678', SmsText::normalizePhone('০১৭১২৩৪৫৬৭৮'));
        $this->assertNull(SmsText::normalizePhone('0121234567'));

        $this->assertSame(['unicode' => false, 'characters' => 160, 'parts' => 1, 'per_part' => 160], SmsText::analyze(str_repeat('a', 160)));
        $this->assertSame(2, SmsText::analyze(str_repeat('a', 161))['parts']);
        $this->assertTrue(SmsText::analyze('প্রিয় গ্রাহক')['unicode']);
        $this->assertSame(1, SmsText::analyze(str_repeat('ক', 70))['parts']);
        $this->assertSame(2, SmsText::analyze(str_repeat('ক', 71))['parts']);

        $this->assertSame('মোট ১,২০০ টাকা', SmsText::render('মোট {amount} টাকা', ['amount' => '1,200'], true));
    }

    public function test_non_admin_cannot_open_or_send(): void
    {
        $user = User::factory()->create(['role' => 'sales', 'is_active' => true]);

        $this->actingAs($user)->get(route('sms.index'))->assertForbidden();
        $this->actingAs($user)->post(route('sms.send-single'), [])->assertForbidden();
    }

    public function test_admin_sees_page_and_sidebar_link(): void
    {
        [$admin] = $this->seed_();

        $this->actingAs($admin)->get(route('sms.index'))
            ->assertOk()
            ->assertSee('Upcoming installment')
            ->assertSee(route('sms.history'), false);

        $this->actingAs($admin)->get(route('sms.history'))->assertOk();
        $this->actingAs($admin)->get(route('sms.templates.index'))->assertOk()->assertSee('কিস্তির অগ্রিম রিমাইন্ডার');
    }

    public function test_audiences_filter_by_scenario_and_date(): void
    {
        [$admin, $branch, $soon, $late, $credit] = $this->seed_();

        $upcoming = $this->actingAs($admin)->getJson(route('sms.audience', [
            'audience' => 'installment_upcoming',
            'from' => now()->toDateString(),
            'to' => now()->addDays(5)->toDateString(),
        ]))->assertOk()->json();
        $this->assertSame([$soon->id], array_column($upcoming['rows'], 'customer_id'));
        $this->assertSame('700', $upcoming['rows'][0]['vars']['due_amount']);

        $overdue = $this->actingAs($admin)->getJson(route('sms.audience', ['audience' => 'installment_overdue']))->json();
        $this->assertSame([$late->id], array_column($overdue['rows'], 'customer_id'));
        $this->assertSame('10', $overdue['rows'][0]['vars']['overdue_days']);

        $tooStrict = $this->actingAs($admin)->getJson(route('sms.audience', ['audience' => 'installment_overdue', 'min_overdue_days' => 30]))->json();
        $this->assertSame(0, $tooStrict['count']);

        $creditOverdue = $this->actingAs($admin)->getJson(route('sms.audience', ['audience' => 'credit_overdue']))->json();
        $this->assertSame([$credit->id], array_column($creditOverdue['rows'], 'customer_id'));

        $dues = $this->actingAs($admin)->getJson(route('sms.audience', ['audience' => 'outstanding_dues', 'min_due' => 900]))->json();
        $this->assertEqualsCanonicalizing([$late->id, $credit->id], array_column($dues['rows'], 'customer_id'));

        $all = $this->actingAs($admin)->getJson(route('sms.audience', ['audience' => 'all_customers']))->json();
        $this->assertSame(3, $all['count']);
        $this->assertSame(1, $all['skipped']); // customer without a valid mobile
    }

    public function test_single_send_calls_provider_and_logs(): void
    {
        [$admin, , $soon] = $this->seed_();

        $this->actingAs($admin)->post(route('sms.send-single'), [
            'phones' => '01711000001, bad-number',
            'customer_id' => $soon->id,
            'message' => 'প্রিয় {name}, ধন্যবাদ - {company}',
            'sender_type' => 'masking',
            'request_token' => (string) Str::uuid(),
        ])->assertRedirect();

        $message = SmsMessage::query()->sole();
        $this->assertSame('sent', $message->status);
        $this->assertSame('8801711000001', $message->phone);
        $this->assertSame('HOWLADER', $message->sender_id);
        $this->assertStringContainsString('Soon Customer', $message->message);
        $this->assertTrue($message->is_unicode);

        Http::assertSent(fn ($request) => str_contains($request->url(), '/smsapi')
            && $request['senderid'] === 'HOWLADER'
            && $request['number'] === '8801711000001');
    }

    public function test_bulk_send_renders_each_customer_and_ignores_unselected(): void
    {
        [$admin, , $soon, $late, $credit] = $this->seed_();

        $this->actingAs($admin)->post(route('sms.send-bulk'), [
            'audience' => 'outstanding_dues',
            'customer_ids' => $late->id.','.$credit->id,
            'message' => '{name}: due {due_amount}',
            'sender_type' => 'non_masking',
            'request_token' => (string) Str::uuid(),
        ])->assertRedirect();

        $batch = SmsBatch::query()->sole();
        $this->assertSame('completed', $batch->status);
        $this->assertSame(2, $batch->sent_count);

        $texts = SmsMessage::query()->orderBy('id')->pluck('message')->all();
        $this->assertContains('Late Customer: due 1,000', $texts);
        $this->assertContains('Credit Customer: due 1,500', $texts);
        $this->assertSame(0, SmsMessage::query()->where('customer_id', $soon->id)->count());

        Http::assertSent(fn ($request) => str_contains($request->url(), '/smsapimany'));
    }

    public function test_same_request_token_is_not_sent_twice(): void
    {
        [$admin] = $this->seed_();
        $payload = [
            'phones' => '01711000009',
            'message' => 'hello',
            'sender_type' => 'non_masking',
            'request_token' => (string) Str::uuid(),
        ];

        $this->actingAs($admin)->post(route('sms.send-single'), $payload);
        $this->actingAs($admin)->post(route('sms.send-single'), $payload)->assertSessionHasErrors('message');

        $this->assertSame(1, SmsMessage::query()->count());
    }

    public function test_provider_error_is_recorded(): void
    {
        $this->fakeProvider(['response_code' => 1007, 'error_message' => 'Balance Insufficient']);
        [$admin] = $this->seed_();

        $this->actingAs($admin)->post(route('sms.send-single'), [
            'phones' => '01711000009',
            'message' => 'hello',
            'sender_type' => 'non_masking',
            'request_token' => (string) Str::uuid(),
        ]);

        $message = SmsMessage::query()->sole();
        $this->assertSame('failed', $message->status);
        $this->assertSame('1007', $message->response_code);
    }

    /**
     * @return array{0: User, 1: Location, 2: Customer, 3: Customer, 4: Customer}
     */
    private function seed_(): array
    {
        if (! $this->faked) {
            $this->fakeProvider();
        }

        $branch = Location::create(['code' => 'BR-SMS', 'name' => 'SMS Branch', 'type' => 'branch', 'is_active' => true]);
        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true, 'branch_id' => $branch->id]);

        $soon = Customer::create(['customer_code' => 'C-1', 'name' => 'Soon Customer', 'phone' => '01711000001', 'branch_id' => $branch->id, 'is_active' => true]);
        $late = Customer::create(['customer_code' => 'C-2', 'name' => 'Late Customer', 'phone' => '01711000002', 'branch_id' => $branch->id, 'is_active' => true]);
        $credit = Customer::create(['customer_code' => 'C-3', 'name' => 'Credit Customer', 'phone' => '01711000003', 'branch_id' => $branch->id, 'is_active' => true]);
        Customer::create(['customer_code' => 'C-4', 'name' => 'No Phone', 'phone' => '123', 'branch_id' => $branch->id, 'is_active' => true]);

        $this->installment($admin, $branch, $soon, 'INV-SOON', now()->addDays(3)->toDateString(), 700);
        $this->installment($admin, $branch, $late, 'INV-LATE', now()->subDays(10)->toDateString(), 1000, 'overdue');

        SalesInvoice::create([
            'invoice_number' => 'INV-CREDIT', 'branch_id' => $branch->id, 'customer_id' => $credit->id,
            'invoice_date' => now()->subMonth()->toDateString(), 'sale_type' => 'credit', 'status' => 'posted',
            'payment_status' => 'unpaid', 'subtotal' => 1500, 'discount_total' => 0, 'tax_total' => 0,
            'grand_total' => 1500, 'amount_paid' => 0, 'amount_due' => 1500,
            'credit_due_date' => now()->subDays(5)->toDateString(), 'created_by' => $admin->id,
        ]);

        return [$admin, $branch, $soon, $late, $credit];
    }

    private function installment(User $user, Location $branch, Customer $customer, string $number, string $due, float $amount, string $status = 'pending'): void
    {
        $invoice = SalesInvoice::create([
            'invoice_number' => $number, 'branch_id' => $branch->id, 'customer_id' => $customer->id,
            'invoice_date' => now()->subMonths(2)->toDateString(), 'sale_type' => 'installment', 'status' => 'active',
            'payment_status' => 'unpaid', 'subtotal' => $amount, 'discount_total' => 0, 'tax_total' => 0,
            'grand_total' => $amount, 'amount_paid' => 0, 'amount_due' => $amount,
            'installment_count' => 1, 'installment_first_due_date' => $due, 'installment_frequency' => 'monthly',
            'created_by' => $user->id,
        ]);

        $plan = InstallmentPlan::create([
            'sales_invoice_id' => $invoice->id, 'customer_id' => $customer->id, 'total_amount' => $amount,
            'down_payment' => 0, 'financed_amount' => $amount, 'remaining_amount' => $amount,
            'penalty_enabled' => false, 'penalty_rate' => 0, 'status' => 'active', 'created_by' => $user->id,
        ]);

        InstallmentSchedule::create([
            'installment_plan_id' => $plan->id, 'installment_no' => 1, 'due_date' => $due,
            'due_amount' => $amount, 'paid_amount' => 0, 'late_fee_amount' => 0, 'status' => $status,
        ]);
    }
}
