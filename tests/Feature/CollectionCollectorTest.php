<?php

namespace Tests\Feature;

use App\Exports\CustomerPaymentByBranchExport;
use App\Exports\MonthlyCollectionExcelExport;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\InstallmentPlan;
use App\Models\InstallmentSchedule;
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
use App\Services\ReportDataService;
use App\Support\LedgerAccountCodes;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

class CollectionCollectorTest extends TestCase
{
    use RefreshDatabase;

    private Location $branch;

    private User $admin;

    private User $alice;

    private User $bob;

    protected function setUp(): void
    {
        parent::setUp();
        $this->branch = Location::create(['code' => 'BR-COL', 'name' => 'Collect Branch', 'type' => 'branch', 'is_active' => true]);
        $this->admin = User::factory()->create(['role' => 'admin', 'is_active' => true, 'branch_id' => $this->branch->id]);
        $this->alice = User::factory()->create(['name' => 'alice@example.com', 'full_name' => 'Alice Collector', 'is_active' => true, 'branch_id' => $this->branch->id]);
        $this->bob = User::factory()->create(['name' => 'bob@example.com', 'full_name' => 'Bob Collector', 'is_active' => true, 'branch_id' => $this->branch->id]);
    }

    private function installmentInvoice(string $number, array $payments): SalesInvoice
    {
        $customer = Customer::create(['customer_code' => 'CC-'.$number, 'name' => 'Cust '.$number, 'phone' => '01700000009', 'is_active' => true, 'branch_id' => $this->branch->id]);
        $invoice = SalesInvoice::create([
            'invoice_number' => $number, 'branch_id' => $this->branch->id, 'customer_id' => $customer->id, 'invoice_date' => now()->subMonth()->toDateString(),
            'sale_type' => 'installment', 'status' => 'posted', 'payment_status' => 'partial', 'subtotal' => 1000, 'discount_total' => 0, 'tax_total' => 0,
            'grand_total' => 1000, 'amount_paid' => 0, 'amount_due' => 1000, 'created_by' => $this->admin->id,
        ]);
        $plan = InstallmentPlan::create([
            'sales_invoice_id' => $invoice->id, 'customer_id' => $customer->id, 'total_amount' => 1000, 'down_payment' => 0, 'financed_amount' => 1000,
            'remaining_amount' => 1000, 'penalty_enabled' => false, 'penalty_rate' => 0, 'status' => 'active', 'created_by' => $this->admin->id,
        ]);
        InstallmentSchedule::create(['installment_plan_id' => $plan->id, 'installment_no' => 1, 'due_date' => now()->toDateString(), 'due_amount' => 1000, 'paid_amount' => 0, 'status' => 'pending']);

        foreach ($payments as $i => [$collector, $amount]) {
            $payment = Payment::create([
                'payment_number' => 'P-'.$number.'-'.$i, 'customer_id' => $customer->id, 'payment_date' => now()->toDateString(), 'payment_method' => 'cash',
                'amount' => $amount, 'direction' => 'inbound', 'recorded_by' => $collector?->id,
            ]);
            PaymentAllocation::create(['payment_id' => $payment->id, 'sales_invoice_id' => $invoice->id, 'allocated_amount' => $amount]);
        }

        return $invoice;
    }

    private function seedThree(): void
    {
        $this->installmentInvoice('INV-A', [[$this->alice, 300]]);
        $this->installmentInvoice('INV-B', [[$this->bob, 200]]);
        $this->installmentInvoice('INV-U', [[null, 100]]);
    }

    public function test_payment_service_stores_recorded_by(): void
    {
        $invoice = SalesInvoice::create([
            'invoice_number' => 'S-SVC', 'branch_id' => $this->branch->id, 'customer_id' => Customer::create(['customer_code' => 'C-SVC', 'name' => 'Svc', 'phone' => '01700000010', 'is_active' => true])->id,
            'invoice_date' => now()->toDateString(), 'sale_type' => 'credit', 'status' => 'posted', 'payment_status' => 'unpaid', 'subtotal' => 500, 'discount_total' => 0,
            'tax_total' => 0, 'grand_total' => 500, 'amount_paid' => 0, 'amount_due' => 500, 'credit_due_date' => now()->addMonth()->toDateString(), 'created_by' => $this->admin->id,
        ]);

        foreach ([[LedgerAccountCodes::ACCOUNTS_RECEIVABLE, 'Accounts Receivable', 'asset'], [LedgerAccountCodes::SALES_REVENUE, 'Sales Revenue', 'revenue']] as [$code, $name, $type]) {
            LedgerAccount::query()->firstOrCreate(['code' => $code], ['name' => $name, 'type' => $type, 'is_system' => true, 'is_active' => true]);
        }
        $ledger = LedgerAccount::create(['code' => 'W-COL', 'name' => 'Cash Wallet', 'type' => 'asset', 'is_system' => false, 'is_active' => true]);
        $wallet = Wallet::create(['ledger_account_id' => $ledger->id, 'code' => 'COL-CASH', 'name' => 'Cash', 'kind' => 'cash', 'is_active' => true]);
        $wallet->locations()->sync([$this->branch->id]);
        $method = PaymentMethod::create(['location_id' => $this->branch->id, 'code' => 'COL-CASH', 'name' => 'Cash', 'type' => 'cash', 'is_active' => true, 'created_by' => $this->admin->id]);
        PaymentMethodWalletMap::create(['location_id' => $this->branch->id, 'payment_method_id' => $method->id, 'wallet_id' => $wallet->id]);

        $payment = app(PaymentService::class)->recordAndAllocate($invoice, ['amount' => 100, 'payment_method_id' => $method->id, 'date' => now()->toDateString()], $this->alice->id);

        $this->assertSame($this->alice->id, (int) $payment->fresh()->recorded_by);
        $this->assertSame('Alice Collector', $payment->fresh()->collector->displayName());
    }

    public function test_both_reports_show_collector_and_unknown(): void
    {
        $this->seedThree();

        $this->actingAs($this->admin)->get(route('reports.customer-payment-by-branch'))
            ->assertOk()->assertSee('Collected by')->assertSee('Alice Collector')->assertSee('Bob Collector')->assertSee('Unknown')->assertDontSee('alice@example.com');
        $this->actingAs($this->admin)->get(route('reports.monthly-collection'))
            ->assertOk()->assertSee('Collected by')->assertSee('Alice Collector')->assertSee('Bob Collector')->assertSee('Unknown');
        $this->actingAs($this->admin)->get(route('reports.print', ['slug' => 'monthly-collection']))
            ->assertOk()->assertSee('Collected by')->assertSee('Alice Collector')->assertSee('By collector')->assertDontSee('alice@example.com');
        $this->actingAs($this->admin)->get(route('reports.print', ['slug' => 'customer-payment-by-branch']))
            ->assertOk()->assertSee('Collected by')->assertSee('Bob Collector')->assertSee('By collector');
    }

    public function test_collector_filter_narrows_rows_in_both_reports(): void
    {
        $this->seedThree();

        $cp = $this->actingAs($this->admin)->get(route('reports.customer-payment-by-branch', ['collector_id' => $this->alice->id]))->assertOk();
        $cp->assertSee('P-INV-A-0')->assertDontSee('P-INV-B-0')->assertDontSee('P-INV-U-0');

        $mc = $this->actingAs($this->admin)->get(route('reports.monthly-collection', ['collector_id' => $this->bob->id]))->assertOk();
        $mc->assertSee('INV-B')->assertDontSee('INV-A')->assertDontSee('INV-U');

        $unknown = $this->actingAs($this->admin)->get(route('reports.customer-payment-by-branch', ['collector_id' => 'unknown']))->assertOk();
        $unknown->assertSee('P-INV-U-0')->assertDontSee('P-INV-A-0');
    }

    public function test_collector_subtotals_sum_to_report_totals(): void
    {
        $this->seedThree();
        $service = app(ReportDataService::class);

        $cp = $service->customerPaymentByBranchPaginated(new Request);
        $this->assertSame(600.0, round(array_sum(array_column($cp['collectorTotals'], 'amount')), 2));
        $this->assertSame($cp['kpis']['total_allocated'], round(array_sum(array_column($cp['collectorTotals'], 'amount')), 2));
        $this->assertSame($cp['kpis']['payment_count'], array_sum(array_column($cp['collectorTotals'], 'payment_count')));

        $mc = $service->monthlyCollectionTotals(new Request);
        $this->assertSame(600.0, $mc['period_paid']);
        $this->assertSame($mc['period_paid'], round(array_sum(array_column($mc['collector_totals'], 'amount')), 2));
        $this->assertContains('Unknown', array_column($mc['collector_totals'], 'collector'));
    }

    public function test_excel_exports_have_collected_by_column_and_by_collector_sheet(): void
    {
        $this->seedThree();

        $this->actingAs($this->admin)->get(route('reports.export', ['slug' => 'customer-payment-by-branch', 'format' => 'xlsx']))->assertOk()->assertDownload();
        $this->actingAs($this->admin)->get(route('reports.export', ['slug' => 'monthly-collection', 'format' => 'xlsx']))->assertOk()->assertDownload();

        $service = app(ReportDataService::class);
        $payload = $service->customerPaymentByBranchForExport(new Request);
        $main = new CustomerPaymentByBranchExport($payload['rows'], [], 'T', 'S');
        $this->assertContains('Collected by', $main->headings());
        $this->assertContains('Unknown', $main->collection()->pluck(13)->all());

        $mc = $service->monthlyCollectionForExport(new Request);
        $workbook = new MonthlyCollectionExcelExport(
            $mc['thisMonthInstallments'], $mc['thisMonthCredits'], $mc['overdueInstallments'], $mc['overdueCredits'], [], 'S',
            $mc['periodPaymentsByInvoiceId'], $mc['maturedOverdueInstallments'], $mc['collectorsByInvoiceId'],
            $service->monthlyCollectionTotals(new Request)['collector_totals'],
        );
        $sheets = $workbook->sheets();
        $this->assertSame('By collector', end($sheets)->title());
        $this->assertContains('Collected by', $sheets[0]->headings());
    }

    public function test_backfill_command_fills_from_audit_logs_and_respects_dry_run(): void
    {
        $customer = Customer::create(['customer_code' => 'C-BF', 'name' => 'Backfill', 'phone' => '01700000011', 'is_active' => true]);
        $payment = Payment::create(['payment_number' => 'P-BF', 'customer_id' => $customer->id, 'payment_date' => now()->toDateString(), 'payment_method' => 'cash', 'amount' => 50, 'direction' => 'inbound', 'recorded_by' => null]);
        $orphan = Payment::create(['payment_number' => 'P-BF2', 'customer_id' => $customer->id, 'payment_date' => now()->toDateString(), 'payment_method' => 'cash', 'amount' => 20, 'direction' => 'inbound', 'recorded_by' => null]);
        AuditLog::create([
            'actor_user_id' => $this->bob->id, 'action' => 'sales_invoice.payment_allocated', 'entity_type' => 'SalesInvoice', 'entity_id' => 1,
            'after_snapshot' => ['payment_id' => $payment->id], 'created_at' => now(),
        ]);

        $this->artisan('payments:backfill-collector', ['--dry-run' => true])->assertSuccessful();
        $this->assertNull($payment->fresh()->recorded_by);

        $this->artisan('payments:backfill-collector')->assertSuccessful();
        $this->assertSame($this->bob->id, (int) $payment->fresh()->recorded_by);
        $this->assertNull($orphan->fresh()->recorded_by);
    }
}
