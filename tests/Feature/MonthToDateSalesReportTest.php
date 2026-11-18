<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Location;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\SalesInvoice;
use App\Models\User;
use App\Services\DebtWriteoffService;
use App\Services\PermissionSyncService;
use App\Services\ReportDataService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

class MonthToDateSalesReportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionSyncService::class)->syncFromPolicies();
    }

    public function test_month_to_date_sales_shows_payments_in_period_not_lifetime_paid(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-20'));

        $branch = Location::create([
            'code' => 'BR-MTD',
            'name' => 'MTD Branch',
            'type' => 'branch',
            'is_active' => true,
        ]);

        $user = User::factory()->create([
            'role' => 'admin',
            'is_active' => true,
            'branch_id' => $branch->id,
        ]);

        $customer = Customer::create([
            'customer_code' => 'C-MTD',
            'name' => 'MTD Customer',
            'phone' => '01700000099',
            'is_active' => true,
        ]);

        $invoice = SalesInvoice::create([
            'invoice_number' => 'INV-MTD-1',
            'branch_id' => $branch->id,
            'customer_id' => $customer->id,
            'invoice_date' => '2026-06-10',
            'sale_type' => 'credit',
            'status' => 'posted',
            'payment_status' => 'partial',
            'subtotal' => 10000,
            'discount_total' => 0,
            'tax_total' => 0,
            'grand_total' => 10000,
            'amount_paid' => 7000,
            'amount_due' => 3000,
            'created_by' => $user->id,
        ]);

        $priorPayment = Payment::create([
            'payment_number' => 'PAY-MTD-PRIOR',
            'customer_id' => $customer->id,
            'payment_date' => '2026-05-15',
            'payment_method' => 'cash',
            'amount' => 3000,
            'direction' => 'inbound',
            'recorded_by' => $user->id,
        ]);

        PaymentAllocation::create([
            'payment_id' => $priorPayment->id,
            'sales_invoice_id' => $invoice->id,
            'allocated_amount' => 3000,
            'settlement_discount' => 0,
        ]);

        $periodPayment = Payment::create([
            'payment_number' => 'PAY-MTD-PERIOD',
            'customer_id' => $customer->id,
            'payment_date' => '2026-06-12',
            'payment_method' => 'cash',
            'amount' => 4000,
            'direction' => 'inbound',
            'recorded_by' => $user->id,
        ]);

        PaymentAllocation::create([
            'payment_id' => $periodPayment->id,
            'sales_invoice_id' => $invoice->id,
            'allocated_amount' => 4000,
            'settlement_discount' => 0,
        ]);

        $secondPeriodPayment = Payment::create([
            'payment_number' => 'PAY-MTD-PERIOD-2',
            'customer_id' => $customer->id,
            'payment_date' => '2026-06-20',
            'payment_method' => 'cash',
            'amount' => 1000,
            'direction' => 'inbound',
            'recorded_by' => $user->id,
        ]);

        PaymentAllocation::create([
            'payment_id' => $secondPeriodPayment->id,
            'sales_invoice_id' => $invoice->id,
            'allocated_amount' => 1000,
            'settlement_discount' => 0,
        ]);

        $request = Request::create('/reports/month-to-date-sales', 'GET', [
            'date_from' => '2026-06-01',
            'date_to' => '2026-06-30',
        ]);

        $data = app(ReportDataService::class)->monthToDateSalesPaginated($request);
        $mtdRows = $data['rows']->getCollection()->where('invoice_number', 'INV-MTD-1')->values();

        $this->assertCount(2, $mtdRows);
        $this->assertEqualsCanonicalizing(
            ['2026-06-12', '2026-06-20'],
            $mtdRows->pluck('period_payment_dates')->all(),
        );
        $this->assertEqualsCanonicalizing([4000.0, 1000.0], $mtdRows->pluck('period_paid')->map(fn ($v) => (float) $v)->all());

        $jun12Row = $mtdRows->firstWhere('period_payment_dates', '2026-06-12');
        $jun20Row = $mtdRows->firstWhere('period_payment_dates', '2026-06-20');
        $this->assertNotNull($jun12Row);
        $this->assertNotNull($jun20Row);
        $this->assertSame(7000.0, (float) $jun12Row->paid_at_transaction);
        $this->assertSame(3000.0, (float) $jun12Row->due_at_transaction);
        $this->assertSame(8000.0, (float) $jun20Row->paid_at_transaction);
        $this->assertSame(2000.0, (float) $jun20Row->due_at_transaction);

        $this->assertSame(7000.0, (float) $mtdRows->first()->amount_paid);
        $this->assertSame(5000.0, (float) $data['kpis']['period_payments']);

        $this->assertSame(1, (int) $data['kpis']['invoices']);

        SalesInvoice::create([
            'invoice_number' => 'INV-MTD-2',
            'branch_id' => $branch->id,
            'customer_id' => $customer->id,
            'invoice_date' => '2026-06-08',
            'sale_type' => 'cash',
            'status' => 'posted',
            'payment_status' => 'unpaid',
            'subtotal' => 5000,
            'discount_total' => 0,
            'tax_total' => 0,
            'grand_total' => 5000,
            'amount_paid' => 0,
            'amount_due' => 5000,
            'created_by' => $user->id,
        ]);

        $dataWithUnpaid = app(ReportDataService::class)->monthToDateSalesPaginated($request);
        $this->assertSame(2, (int) $dataWithUnpaid['kpis']['invoices']);
        $this->assertSame('INV-MTD-1', $dataWithUnpaid['rows']->getCollection()->first()?->invoice_number);

        $previousInvoice = SalesInvoice::create([
            'invoice_number' => 'INV-MTD-OLD',
            'branch_id' => $branch->id,
            'customer_id' => $customer->id,
            'invoice_date' => '2026-05-05',
            'sale_type' => 'credit',
            'status' => 'posted',
            'payment_status' => 'partial',
            'subtotal' => 8000,
            'discount_total' => 0,
            'tax_total' => 0,
            'grand_total' => 8000,
            'amount_paid' => 3000,
            'amount_due' => 5000,
            'created_by' => $user->id,
        ]);

        $previousCollectionPayment = Payment::create([
            'payment_number' => 'PAY-MTD-OLD-COL',
            'customer_id' => $customer->id,
            'payment_date' => '2026-06-18',
            'payment_method' => 'cash',
            'amount' => 2500,
            'direction' => 'inbound',
            'recorded_by' => $user->id,
        ]);

        PaymentAllocation::create([
            'payment_id' => $previousCollectionPayment->id,
            'sales_invoice_id' => $previousInvoice->id,
            'allocated_amount' => 2500,
            'settlement_discount' => 0,
        ]);

        $dataWithPrevious = app(ReportDataService::class)->monthToDateSalesPaginated($request);
        $this->assertSame(7500.0, (float) $dataWithPrevious['kpis']['period_payments']);
        $this->assertSame(2500.0, (float) $dataWithPrevious['kpis']['previous_collections']);
        $this->assertTrue($dataWithPrevious['rows']->getCollection()->contains(
            fn (SalesInvoice $row): bool => $row->invoice_number === 'INV-MTD-OLD' && ! empty($row->is_previous_invoice_collection)
        ));

        $summaryByBranch = $dataWithPrevious['summaryByBranch'];
        $this->assertCount(1, $summaryByBranch);
        $branchSummary = $summaryByBranch->first();
        $this->assertSame('MTD Branch', $branchSummary['branch_name']);
        $this->assertSame(3, (int) $branchSummary['invoices']);
        $this->assertSame(7500.0, (float) $branchSummary['period_paid']);
        $this->assertSame(2500.0, (float) $branchSummary['previous_collections']);

        $summaryByType = $dataWithPrevious['summaryByType']->keyBy('type');
        $this->assertSame(2, (int) ($summaryByType->get('credit')['invoices'] ?? 0));
        $this->assertSame(7500.0, (float) ($summaryByType->get('credit')['period_paid'] ?? 0));

        $response = $this->actingAs($user)->get(route('reports.month-to-date-sales', [
            'date_from' => '2026-06-01',
            'date_to' => '2026-06-30',
        ]));

        $response->assertOk();
        $response->assertSee('Collection summary by type', false);
        $response->assertSee('Payment date', false);
        $response->assertSee('2026-06-12', false);
        $response->assertSee('2026-06-18', false);
        $response->assertDontSee('2026-06-10', false);
        $response->assertSee('MTD Branch', false);
        $response->assertDontSee('Report totals', false);
        $response->assertSee('INV-MTD-1', false);
        $response->assertSee('INV-MTD-OLD', false);
        $response->assertSee('Paid (cash)', false);
        $response->assertSee('Credit', false);
        $response->assertSee('7,500.00', false);
        $response->assertSee('2,500.00', false);
        $response->assertDontSee('INV-MTD-2', false);
    }

    public function test_month_to_date_sales_includes_period_cash_collections_on_written_off_invoices(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-20'));

        $branch = Location::create([
            'code' => 'BR-MTD-WO',
            'name' => 'MTD Write-off Branch',
            'type' => 'branch',
            'is_active' => true,
        ]);

        $user = User::factory()->create([
            'role' => 'admin',
            'is_active' => true,
            'branch_id' => $branch->id,
        ]);

        $customer = Customer::create([
            'customer_code' => 'C-MTD-WO',
            'name' => 'Write-off Customer',
            'phone' => '01700000111',
            'is_active' => true,
            'outstanding_balance' => 10000,
        ]);

        $invoice = SalesInvoice::create([
            'invoice_number' => 'INV-MTD-WO',
            'branch_id' => $branch->id,
            'customer_id' => $customer->id,
            'invoice_date' => '2026-06-05',
            'sale_type' => 'credit',
            'status' => 'posted',
            'payment_status' => 'unpaid',
            'subtotal' => 10000,
            'discount_total' => 0,
            'tax_total' => 0,
            'grand_total' => 10000,
            'amount_paid' => 0,
            'amount_due' => 10000,
            'created_by' => $user->id,
        ]);

        $periodPayment = Payment::create([
            'payment_number' => 'PAY-MTD-WO',
            'customer_id' => $customer->id,
            'payment_date' => '2026-06-12',
            'payment_method' => 'cash',
            'amount' => 3000,
            'direction' => 'inbound',
            'recorded_by' => $user->id,
        ]);

        PaymentAllocation::create([
            'payment_id' => $periodPayment->id,
            'sales_invoice_id' => $invoice->id,
            'allocated_amount' => 3000,
            'settlement_discount' => 0,
        ]);

        $invoice->update([
            'amount_paid' => 3000,
            'amount_due' => 7000,
            'payment_status' => 'partial',
        ]);

        app(DebtWriteoffService::class)->writeOffInvoiceReceivable(
            $invoice->fresh(),
            7000,
            'Uncollectible balance',
            $user->id,
        );

        $invoice->refresh();
        $this->assertSame(10000.0, (float) $invoice->amount_paid);

        $request = Request::create('/reports/month-to-date-sales', 'GET', [
            'date_from' => '2026-06-01',
            'date_to' => '2026-06-30',
        ]);

        $data = app(ReportDataService::class)->monthToDateSalesPaginated($request);
        $mtdRow = $data['rows']->getCollection()->firstWhere('invoice_number', 'INV-MTD-WO');

        $this->assertNotNull($mtdRow);
        $this->assertSame(3000.0, (float) $mtdRow->period_paid);
        $this->assertSame('2026-06-12', $mtdRow->period_payment_dates);
        $this->assertSame(3000.0, (float) $mtdRow->cash_paid_total);
        $this->assertSame(7000.0, (float) $mtdRow->writeoff_total);
        $this->assertSame(3000.0, (float) $data['kpis']['period_payments']);
        $this->assertSame(1, (int) $data['kpis']['invoices']);
        $this->assertSame(3000.0, (float) $data['summaryByType']->firstWhere('type', 'credit')['period_paid']);
    }

    public function test_month_to_date_sales_print_with_blank_dates_defaults_to_current_month(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-20'));

        $branch = Location::create([
            'code' => 'BR-MTD-PR',
            'name' => 'MTD Print Branch',
            'type' => 'branch',
            'is_active' => true,
        ]);

        $user = User::factory()->create([
            'role' => 'admin',
            'is_active' => true,
            'branch_id' => $branch->id,
        ]);

        $customer = Customer::create([
            'customer_code' => 'C-MTD-PR',
            'name' => 'MTD Print Customer',
            'phone' => '01700000222',
            'is_active' => true,
        ]);

        $invoice = SalesInvoice::create([
            'invoice_number' => 'INV-MTD-PR-1642',
            'branch_id' => $branch->id,
            'customer_id' => $customer->id,
            'invoice_date' => '2026-05-28',
            'sale_type' => 'credit',
            'status' => 'posted',
            'payment_status' => 'partial',
            'subtotal' => 12000,
            'discount_total' => 0,
            'tax_total' => 0,
            'grand_total' => 12000,
            'amount_paid' => 5000,
            'amount_due' => 7000,
            'created_by' => $user->id,
        ]);

        $periodPayment = Payment::create([
            'payment_number' => 'PAY-MTD-PR',
            'customer_id' => $customer->id,
            'payment_date' => '2026-06-15',
            'payment_method' => 'cash',
            'amount' => 5000,
            'direction' => 'inbound',
            'recorded_by' => $user->id,
        ]);

        PaymentAllocation::create([
            'payment_id' => $periodPayment->id,
            'sales_invoice_id' => $invoice->id,
            'allocated_amount' => 5000,
            'settlement_discount' => 0,
        ]);

        $request = Request::create('/reports/month-to-date-sales/print', 'GET', [
            'q' => '',
            'branch_id' => '',
            'payment_status' => '',
            'sale_type' => '',
            'date_from' => '',
            'date_to' => '',
        ]);

        $payload = app(ReportDataService::class)->monthToDateSalesForExport($request);

        $this->assertSame('2026-06-01', $payload['filters']['date_from']);
        $this->assertSame('2026-06-30', $payload['filters']['date_to']);
        $printRow = $payload['rows']->first(
            fn (SalesInvoice $row): bool => $row->invoice_number === 'INV-MTD-PR-1642',
        );
        $this->assertNotNull($printRow);
        $this->assertSame(5000.0, (float) $printRow->period_paid);
        $this->assertSame(5000.0, (float) $printRow->paid_at_transaction);
        $this->assertSame(7000.0, (float) $printRow->due_at_transaction);

        $html = $this->actingAs($user)->get(route('reports.print', [
            'slug' => 'month-to-date-sales',
            'q' => '',
            'branch_id' => '',
            'payment_status' => '',
            'sale_type' => '',
            'date_from' => '',
            'date_to' => '',
        ]));

        $html->assertOk();
        $html->assertSee('INV-MTD-PR-1642', false);
        $html->assertSee('2026-06-15', false);
        $html->assertSee('5,000.00', false);
    }

    public function test_month_to_date_sales_includes_payments_on_fully_returned_invoices(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-20'));

        $branch = Location::create([
            'code' => 'BR-MTD-RET',
            'name' => 'MTD Return Branch',
            'type' => 'branch',
            'is_active' => true,
        ]);

        $user = User::factory()->create([
            'role' => 'admin',
            'is_active' => true,
            'branch_id' => $branch->id,
        ]);

        $customer = Customer::create([
            'customer_code' => 'C-MTD-RET',
            'name' => 'Return Customer',
            'phone' => '01700000333',
            'is_active' => true,
        ]);

        $invoice = SalesInvoice::create([
            'invoice_number' => 'INV-MTD-RET',
            'branch_id' => $branch->id,
            'customer_id' => $customer->id,
            'invoice_date' => '2026-06-08',
            'sale_type' => 'default',
            'status' => 'posted',
            'payment_status' => 'paid',
            'subtotal' => 15000,
            'discount_total' => 0,
            'tax_total' => 0,
            'grand_total' => 15000,
            'amount_paid' => 15000,
            'amount_due' => 0,
            'created_by' => $user->id,
        ]);

        $periodPayment = Payment::create([
            'payment_number' => 'PAY-MTD-RET',
            'customer_id' => $customer->id,
            'payment_date' => '2026-06-08',
            'payment_method' => 'cash',
            'amount' => 15000,
            'direction' => 'inbound',
            'recorded_by' => $user->id,
        ]);

        PaymentAllocation::create([
            'payment_id' => $periodPayment->id,
            'sales_invoice_id' => $invoice->id,
            'allocated_amount' => 15000,
            'settlement_discount' => 0,
        ]);

        $brand = \App\Models\Brand::create(['name' => 'Return Brand']);
        $category = \App\Models\Category::create(['name' => 'Return Category']);
        $product = \App\Models\Product::create([
            'sku' => 'SKU-RET',
            'brand_id' => $brand->id,
            'category_id' => $category->id,
            'name' => 'Returned Phone',
            'warranty_months' => 12,
            'default_purchase_price' => 10000,
            'default_sale_price' => 15000,
            'reorder_level' => 0,
            'unit_of_measure' => 'unit',
            'is_serialized' => true,
            'is_active' => true,
        ]);

        $line = \App\Models\SalesInvoiceLine::create([
            'sales_invoice_id' => $invoice->id,
            'product_id' => $product->id,
            'qty' => 1,
            'unit_price' => 15000,
            'discount_amount' => 0,
            'tax_amount' => 0,
            'line_total' => 15000,
            'warranty_months_snapshot' => 12,
        ]);

        $serial = \App\Models\SerialItem::create([
            'product_id' => $product->id,
            'serial_number' => 'SN-RET-001',
            'serial_source' => 'internal',
            'current_location_id' => $branch->id,
            'status' => 'sold',
            'sale_invoice_line_id' => $line->id,
            'purchase_cost' => 10000,
        ]);

        \App\Models\SaleLineSerial::create([
            'sales_invoice_line_id' => $line->id,
            'serial_item_id' => $serial->id,
        ]);

        $returnOrder = \App\Models\ReturnOrder::create([
            'return_number' => 'RET-MTD-001',
            'sales_invoice_id' => $invoice->id,
            'customer_id' => $customer->id,
            'branch_id' => $branch->id,
            'status' => 'draft',
            'return_date' => '2026-06-15',
            'reason' => 'Customer return',
            'created_by' => $user->id,
        ]);

        \App\Models\ReturnLine::create([
            'return_order_id' => $returnOrder->id,
            'sales_invoice_line_id' => $line->id,
            'serial_item_id' => $serial->id,
            'qty' => null,
            'resolution' => 'restock',
        ]);

        $ledger = \App\Models\LedgerAccount::create([
            'code' => 'W-MTD-RET',
            'name' => 'MTD Return Wallet Ledger',
            'type' => 'asset',
            'is_system' => false,
            'is_active' => true,
        ]);

        $wallet = \App\Models\Wallet::create([
            'ledger_account_id' => $ledger->id,
            'code' => 'W-MTD-RET',
            'name' => 'MTD Return Wallet',
            'kind' => 'cash',
            'is_active' => true,
        ]);
        $wallet->locations()->sync([$branch->id]);

        $request = Request::create('/reports/month-to-date-sales', 'GET', [
            'date_from' => '2026-06-01',
            'date_to' => '2026-06-30',
        ]);

        $beforeReturn = app(ReportDataService::class)->monthToDateSalesPaginated($request);
        $this->assertTrue($beforeReturn['rows']->getCollection()->contains(
            fn (SalesInvoice $row): bool => $row->invoice_number === 'INV-MTD-RET'
        ));
        $this->assertSame(15000.0, (float) $beforeReturn['kpis']['period_payments']);

        $this->actingAs($user)->post(route('returns.resolve', $returnOrder), [
            'settlement_mode' => 'full',
            'refund_percentage' => 100,
            'pay_now_amount' => 15000,
            'wallet_id' => $wallet->id,
            'payment_date' => '2026-06-15',
        ])->assertRedirect();

        $invoice->refresh();
        $this->assertSame('returned', $invoice->status);

        $afterReturn = app(ReportDataService::class)->monthToDateSalesPaginated($request);
        $returnedRow = $afterReturn['rows']->getCollection()->firstWhere('invoice_number', 'INV-MTD-RET');
        $this->assertNotNull($returnedRow);
        $this->assertSame('returned', $returnedRow->status);
        $this->assertSame(15000.0, (float) $returnedRow->period_paid);
        $this->assertSame(15000.0, (float) $afterReturn['kpis']['period_payments']);
        $this->assertSame(1, (int) $afterReturn['kpis']['invoices']);
    }

    public function test_month_to_date_sales_includes_paid_period_invoices_without_payment_allocations(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-20'));

        $branch = Location::create([
            'code' => 'BR-MTD-PAID',
            'name' => 'MTD Paid Branch',
            'type' => 'branch',
            'is_active' => true,
        ]);

        $user = User::factory()->create([
            'role' => 'admin',
            'is_active' => true,
            'branch_id' => $branch->id,
        ]);

        $customer = Customer::create([
            'customer_code' => 'C-MTD-PAID',
            'name' => 'Paid Cash Customer',
            'phone' => '01700000666',
            'is_active' => true,
        ]);

        SalesInvoice::create([
            'invoice_number' => 'INV-MTD-PAID',
            'branch_id' => $branch->id,
            'customer_id' => $customer->id,
            'invoice_date' => '2026-06-14',
            'sale_type' => 'default',
            'status' => 'posted',
            'payment_status' => 'paid',
            'subtotal' => 25000,
            'discount_total' => 0,
            'tax_total' => 0,
            'grand_total' => 25000,
            'amount_paid' => 25000,
            'amount_due' => 0,
            'created_by' => $user->id,
        ]);

        $request = Request::create('/reports/month-to-date-sales', 'GET', [
            'date_from' => '2026-06-01',
            'date_to' => '2026-06-30',
        ]);

        $data = app(ReportDataService::class)->monthToDateSalesPaginated($request);
        $paidRow = $data['rows']->getCollection()->firstWhere('invoice_number', 'INV-MTD-PAID');

        $this->assertNotNull($paidRow);
        $this->assertSame('2026-06-14', $paidRow->period_payment_dates);
        $this->assertSame(25000.0, (float) $paidRow->period_paid);
        $this->assertSame(25000.0, (float) $paidRow->paid_at_transaction);
        $this->assertSame(0.0, (float) $paidRow->due_at_transaction);
        $this->assertSame(25000.0, (float) $data['kpis']['period_payments']);
        $this->assertSame(1, (int) $data['kpis']['invoices']);
    }

    public function test_month_to_date_sales_includes_active_status_paid_installment_without_payment_allocations(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-20'));

        $branch = Location::create([
            'code' => 'BR-MTD-ACT',
            'name' => 'MTD Active Branch',
            'type' => 'branch',
            'is_active' => true,
        ]);

        $user = User::factory()->create([
            'role' => 'admin',
            'is_active' => true,
            'branch_id' => $branch->id,
        ]);

        $customer = Customer::create([
            'customer_code' => 'C-MTD-ACT',
            'name' => 'Active Installment Customer',
            'phone' => '01700000777',
            'is_active' => true,
        ]);

        SalesInvoice::create([
            'invoice_number' => 'INV-MTD-ACT',
            'branch_id' => $branch->id,
            'customer_id' => $customer->id,
            'invoice_date' => '2026-06-11',
            'sale_type' => 'installment',
            'status' => 'active',
            'payment_status' => 'paid',
            'subtotal' => 80000,
            'discount_total' => 0,
            'tax_total' => 0,
            'grand_total' => 80000,
            'amount_paid' => 80000,
            'amount_due' => 0,
            'installment_down_payment' => 80000,
            'installment_count' => 0,
            'created_by' => $user->id,
        ]);

        $request = Request::create('/reports/month-to-date-sales', 'GET', [
            'date_from' => '2026-06-01',
            'date_to' => '2026-06-30',
        ]);

        $data = app(ReportDataService::class)->monthToDateSalesPaginated($request);
        $activeRow = $data['rows']->getCollection()->firstWhere('invoice_number', 'INV-MTD-ACT');

        $this->assertNotNull($activeRow);
        $this->assertSame('active', $activeRow->status);
        $this->assertSame('2026-06-11', $activeRow->period_payment_dates);
        $this->assertSame(80000.0, (float) $activeRow->period_paid);
    }

    public function test_month_to_date_sales_includes_active_installment_with_completed_plan_and_partial_header(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-20'));

        $branch = Location::create([
            'code' => 'BR-MTD-COMP',
            'name' => 'MTD Completed Plan Branch',
            'type' => 'branch',
            'is_active' => true,
        ]);

        $user = User::factory()->create([
            'role' => 'admin',
            'is_active' => true,
            'branch_id' => $branch->id,
        ]);

        $customer = Customer::create([
            'customer_code' => 'C-MTD-COMP',
            'name' => 'Completed Plan Customer',
            'phone' => '01700000888',
            'is_active' => true,
        ]);

        $invoice = SalesInvoice::create([
            'invoice_number' => 'INV-MTD-COMP',
            'branch_id' => $branch->id,
            'customer_id' => $customer->id,
            'invoice_date' => '2026-06-09',
            'sale_type' => 'installment',
            'status' => 'active',
            'payment_status' => 'partial',
            'subtotal' => 120000,
            'discount_total' => 0,
            'tax_total' => 0,
            'grand_total' => 120000,
            'amount_paid' => 20000,
            'amount_due' => 100000,
            'installment_down_payment' => 20000,
            'installment_count' => 4,
            'created_by' => $user->id,
        ]);

        $plan = \App\Models\InstallmentPlan::create([
            'sales_invoice_id' => $invoice->id,
            'customer_id' => $customer->id,
            'total_amount' => 120000,
            'down_payment' => 20000,
            'financed_amount' => 100000,
            'remaining_amount' => 0,
            'status' => 'completed',
            'created_by' => $user->id,
        ]);

        \App\Models\InstallmentSchedule::create([
            'installment_plan_id' => $plan->id,
            'installment_no' => 1,
            'due_date' => '2026-05-09',
            'due_amount' => 25000,
            'paid_amount' => 25000,
            'status' => 'paid',
        ]);

        $priorPayment = Payment::create([
            'payment_number' => 'PAY-MTD-COMP-PRIOR',
            'customer_id' => $customer->id,
            'payment_date' => '2026-05-20',
            'payment_method' => 'cash',
            'amount' => 20000,
            'direction' => 'inbound',
            'recorded_by' => $user->id,
        ]);

        PaymentAllocation::create([
            'payment_id' => $priorPayment->id,
            'sales_invoice_id' => $invoice->id,
            'allocated_amount' => 20000,
            'settlement_discount' => 0,
        ]);

        $request = Request::create('/reports/month-to-date-sales', 'GET', [
            'date_from' => '2026-06-01',
            'date_to' => '2026-06-30',
        ]);

        $data = app(ReportDataService::class)->monthToDateSalesPaginated($request);
        $completedRow = $data['rows']->getCollection()->firstWhere('invoice_number', 'INV-MTD-COMP');

        $this->assertNotNull($completedRow);
        $this->assertSame('active', $completedRow->status);
        $this->assertSame('2026-06-09', $completedRow->period_payment_dates);
        $this->assertSame(0.0, (float) $completedRow->period_paid);
        $this->assertSame(20000.0, (float) $completedRow->paid_at_transaction);
    }

    public function test_month_to_date_sales_separates_cash_and_settlement_discount(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-20'));

        $branch = Location::create([
            'code' => 'BR-MTD-DISC',
            'name' => 'MTD Discount Branch',
            'type' => 'branch',
            'is_active' => true,
        ]);

        $user = User::factory()->create([
            'role' => 'admin',
            'is_active' => true,
            'branch_id' => $branch->id,
        ]);

        $customer = Customer::create([
            'customer_code' => 'C-MTD-DISC',
            'name' => 'Discount Customer',
            'phone' => '01700000888',
            'is_active' => true,
        ]);

        $invoice = SalesInvoice::create([
            'invoice_number' => 'INV-MTD-DISC',
            'branch_id' => $branch->id,
            'customer_id' => $customer->id,
            'invoice_date' => '2026-06-05',
            'sale_type' => 'credit',
            'status' => 'posted',
            'payment_status' => 'partial',
            'subtotal' => 10000,
            'discount_total' => 0,
            'tax_total' => 0,
            'grand_total' => 10000,
            'amount_paid' => 5500,
            'amount_due' => 4500,
            'created_by' => $user->id,
        ]);

        $payment = Payment::create([
            'payment_number' => 'PAY-MTD-DISC',
            'customer_id' => $customer->id,
            'payment_date' => '2026-06-12',
            'payment_method' => 'cash',
            'amount' => 5000,
            'direction' => 'inbound',
            'recorded_by' => $user->id,
        ]);

        PaymentAllocation::create([
            'payment_id' => $payment->id,
            'sales_invoice_id' => $invoice->id,
            'allocated_amount' => 5000,
            'settlement_discount' => 500,
        ]);

        $request = Request::create('/reports/month-to-date-sales', 'GET', [
            'date_from' => '2026-06-01',
            'date_to' => '2026-06-30',
        ]);

        $data = app(ReportDataService::class)->monthToDateSalesPaginated($request);
        $row = $data['rows']->getCollection()->firstWhere('invoice_number', 'INV-MTD-DISC');

        $this->assertNotNull($row);
        $this->assertSame(5500.0, (float) $row->period_paid);
        $this->assertSame(5000.0, (float) $row->period_cash);
        $this->assertSame(500.0, (float) $row->period_discount);
        $this->assertSame(5000.0, (float) $row->cash_at_transaction);
        $this->assertSame(500.0, (float) $row->discount_at_transaction);
        $this->assertSame(5500.0, (float) $row->paid_at_transaction);

        $typeSummary = $data['summaryByType']->firstWhere('type', 'credit');
        $this->assertNotNull($typeSummary);
        $this->assertSame(5000.0, (float) $typeSummary['amount_paid']);
        $this->assertSame(500.0, (float) $typeSummary['settlement_discount']);
        $this->assertSame(5000.0, (float) $typeSummary['period_cash']);
        $this->assertSame(500.0, (float) $typeSummary['period_discount']);
    }

    public function test_month_to_date_sales_reconciles_imported_header_paid_with_partial_allocations(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-04'));

        $branch = Location::create([
            'code' => 'BR-MTD-S',
            'name' => 'MTD Imported Branch',
            'type' => 'branch',
            'is_active' => true,
        ]);

        $user = User::factory()->create([
            'role' => 'admin',
            'is_active' => true,
            'branch_id' => $branch->id,
        ]);

        $customer = Customer::create([
            'customer_code' => 'C-MTD-S',
            'name' => 'Imported Customer',
            'phone' => '01700000999',
            'is_active' => true,
        ]);

        $invoice = SalesInvoice::create([
            'invoice_number' => 'S-MTD-25690',
            'branch_id' => $branch->id,
            'customer_id' => $customer->id,
            'invoice_date' => '2026-06-01',
            'sale_type' => 'credit',
            'status' => 'posted',
            'payment_status' => 'partial',
            'subtotal' => 37310,
            'discount_total' => 0,
            'tax_total' => 0,
            'grand_total' => 37310,
            'amount_paid' => 26200,
            'amount_due' => 11110,
            'created_by' => $user->id,
        ]);

        $priorPayment = Payment::create([
            'payment_number' => 'PAY-MTD-S-PRIOR',
            'customer_id' => $customer->id,
            'payment_date' => '2026-06-20',
            'payment_method' => 'cash',
            'amount' => 4500,
            'direction' => 'inbound',
            'recorded_by' => $user->id,
        ]);

        PaymentAllocation::create([
            'payment_id' => $priorPayment->id,
            'sales_invoice_id' => $invoice->id,
            'allocated_amount' => 4500,
            'settlement_discount' => 0,
        ]);

        $periodPayment = Payment::create([
            'payment_number' => 'PAY-MTD-S-PERIOD',
            'customer_id' => $customer->id,
            'payment_date' => '2026-07-02',
            'payment_method' => 'cash',
            'amount' => 1000,
            'direction' => 'inbound',
            'recorded_by' => $user->id,
        ]);

        PaymentAllocation::create([
            'payment_id' => $periodPayment->id,
            'sales_invoice_id' => $invoice->id,
            'allocated_amount' => 1000,
            'settlement_discount' => 0,
        ]);

        $request = Request::create('/reports/month-to-date-sales', 'GET', [
            'date_from' => '2026-07-01',
            'date_to' => '2026-07-04',
        ]);

        $paginated = app(ReportDataService::class)->monthToDateSalesPaginated($request);
        $row = $paginated['rows']->getCollection()->firstWhere('invoice_number', 'S-MTD-25690');

        $this->assertNotNull($row);
        $this->assertSame(1000.0, (float) $row->period_cash);
        $this->assertSame(26200.0, (float) $row->cash_at_transaction);
        $this->assertSame(11110.0, (float) $row->due_at_transaction);

        $export = app(ReportDataService::class)->monthToDateSalesForExport($request);
        $printRow = $export['rows']->firstWhere('invoice_number', 'S-MTD-25690');

        $this->assertNotNull($printRow);
        $this->assertSame(26200.0, (float) $printRow->cash_at_transaction);
        $this->assertSame(11110.0, (float) $printRow->due_at_transaction);
    }

    public function test_month_to_date_sales_type_summary_reconciles_paid_discount_writeoff_and_due(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-20'));

        $branch = Location::create([
            'code' => 'BR-MTD-RECON',
            'name' => 'MTD Reconcile Branch',
            'type' => 'branch',
            'is_active' => true,
        ]);

        $user = User::factory()->create([
            'role' => 'admin',
            'is_active' => true,
            'branch_id' => $branch->id,
        ]);

        $customer = Customer::create([
            'customer_code' => 'C-MTD-RECON',
            'name' => 'Reconcile Customer',
            'phone' => '01700000777',
            'is_active' => true,
        ]);

        $invoice = SalesInvoice::create([
            'invoice_number' => 'INV-MTD-RECON',
            'branch_id' => $branch->id,
            'customer_id' => $customer->id,
            'invoice_date' => '2026-06-10',
            'sale_type' => 'installment',
            'status' => 'active',
            'payment_status' => 'partial',
            'subtotal' => 120000,
            'discount_total' => 0,
            'tax_total' => 0,
            'grand_total' => 120000,
            'amount_paid' => 50000,
            'amount_due' => 80000,
            'installment_down_payment' => 20000,
            'installment_count' => 4,
            'created_by' => $user->id,
        ]);

        Payment::create([
            'payment_number' => 'PAY-MTD-RECON',
            'customer_id' => $customer->id,
            'payment_date' => '2026-06-15',
            'payment_method' => 'cash',
            'amount' => 30000,
            'direction' => 'inbound',
            'recorded_by' => $user->id,
        ])->paymentAllocations()->create([
            'sales_invoice_id' => $invoice->id,
            'allocated_amount' => 30000,
            'settlement_discount' => 0,
        ]);

        \App\Models\InvoiceDebtWriteoff::create([
            'sales_invoice_id' => $invoice->id,
            'amount' => 10000,
            'reason' => 'Bad debt',
            'actor_user_id' => $user->id,
        ]);

        $request = Request::create('/reports/month-to-date-sales', 'GET', [
            'date_from' => '2026-06-01',
            'date_to' => '2026-06-30',
        ]);

        $data = app(ReportDataService::class)->monthToDateSalesPaginated($request);
        $typeSummary = $data['summaryByType']->firstWhere('type', 'installment');

        $this->assertNotNull($typeSummary);
        $this->assertSame(120000.0, (float) $typeSummary['grand_total']);
        $this->assertSame(10000.0, (float) $typeSummary['writeoff']);
        $this->assertSame(
            (float) $typeSummary['grand_total'],
            round(
                (float) $typeSummary['amount_paid']
                + (float) $typeSummary['settlement_discount']
                + (float) $typeSummary['writeoff']
                + (float) $typeSummary['amount_due'],
                2,
            ),
        );
    }
}
