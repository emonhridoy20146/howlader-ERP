<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Employee;
use App\Models\Location;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\SalesInvoice;
use App\Models\User;
use App\Services\Reports\SalesReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

class InvoiceReferrerAndReportTest extends TestCase
{
    use RefreshDatabase;

    public function test_draft_invoice_can_store_employee_referrer(): void
    {
        $branch = Location::create([
            'code' => 'BR-REF',
            'name' => 'Referrer Branch',
            'type' => 'branch',
            'is_active' => true,
        ]);

        $admin = User::factory()->create([
            'role' => 'admin',
            'is_active' => true,
            'branch_id' => $branch->id,
        ]);

        $referrer = Employee::create([
            'employee_code' => 'EMP-9001',
            'first_name' => 'Referrer',
            'last_name' => 'Staff',
            'joining_date' => now()->toDateString(),
            'employment_status' => 'active',
            'is_active' => true,
        ]);

        $customer = Customer::create([
            'customer_code' => 'C-REF',
            'name' => 'Referrer Customer',
            'phone' => '01700001111',
            'is_active' => true,
        ]);

        $invoice = SalesInvoice::create([
            'branch_id' => $branch->id,
            'customer_id' => $customer->id,
            'referrer_id' => $referrer->id,
            'invoice_date' => now()->toDateString(),
            'sale_type' => 'default',
            'status' => 'draft',
            'payment_status' => 'unpaid',
            'subtotal' => 1000,
            'discount_total' => 0,
            'tax_total' => 0,
            'grand_total' => 1000,
            'amount_paid' => 0,
            'amount_due' => 1000,
            'created_by' => $admin->id,
        ]);

        $this->assertSame($referrer->id, $invoice->fresh()->referrer_id);
        $this->assertSame('EMP-9001 — Referrer Staff', $invoice->fresh()->referrer?->displayLabel());
    }

    public function test_referrer_wise_sales_report_groups_by_employee(): void
    {
        $branch = Location::create([
            'code' => 'BR-RPT',
            'name' => 'Report Branch',
            'type' => 'branch',
            'is_active' => true,
        ]);

        $admin = User::factory()->create([
            'role' => 'admin',
            'is_active' => true,
            'branch_id' => $branch->id,
        ]);

        $referrerA = Employee::create([
            'employee_code' => 'EMP-A001',
            'first_name' => 'Staff',
            'last_name' => 'Alpha',
            'joining_date' => now()->toDateString(),
            'employment_status' => 'active',
            'is_active' => true,
        ]);

        $referrerB = Employee::create([
            'employee_code' => 'EMP-B001',
            'first_name' => 'Staff',
            'last_name' => 'Beta',
            'joining_date' => now()->toDateString(),
            'employment_status' => 'active',
            'is_active' => true,
        ]);

        $customer = Customer::create([
            'customer_code' => 'C-RPT',
            'name' => 'Report Customer',
            'phone' => '01700002222',
            'is_active' => true,
        ]);

        SalesInvoice::create([
            'invoice_number' => 'S-REF-A1',
            'branch_id' => $branch->id,
            'customer_id' => $customer->id,
            'referrer_id' => $referrerA->id,
            'invoice_date' => now()->toDateString(),
            'sale_type' => 'default',
            'status' => 'posted',
            'payment_status' => 'paid',
            'subtotal' => 1000,
            'discount_total' => 0,
            'tax_total' => 0,
            'grand_total' => 1000,
            'amount_paid' => 1000,
            'amount_due' => 0,
            'created_by' => $admin->id,
        ]);

        SalesInvoice::create([
            'invoice_number' => 'S-REF-A2',
            'branch_id' => $branch->id,
            'customer_id' => $customer->id,
            'referrer_id' => $referrerA->id,
            'invoice_date' => now()->toDateString(),
            'sale_type' => 'default',
            'status' => 'posted',
            'payment_status' => 'unpaid',
            'subtotal' => 500,
            'discount_total' => 0,
            'tax_total' => 0,
            'grand_total' => 500,
            'amount_paid' => 0,
            'amount_due' => 500,
            'created_by' => $admin->id,
        ]);

        SalesInvoice::create([
            'invoice_number' => 'S-REF-B1',
            'branch_id' => $branch->id,
            'customer_id' => $customer->id,
            'referrer_id' => $referrerB->id,
            'invoice_date' => now()->toDateString(),
            'sale_type' => 'default',
            'status' => 'posted',
            'payment_status' => 'paid',
            'subtotal' => 2000,
            'discount_total' => 0,
            'tax_total' => 0,
            'grand_total' => 2000,
            'amount_paid' => 2000,
            'amount_due' => 0,
            'created_by' => $admin->id,
        ]);

        $this->actingAs($admin);

        $data = app(SalesReportService::class)->representativeWiseSalesPaginated(Request::create('/', 'GET'));
        $rows = collect($data['rows']->items())->keyBy(fn ($row) => Employee::labelFromParts(
            $row->representative_code,
            $row->representative_first_name,
            $row->representative_last_name,
        ));

        $this->assertSame(2, (int) $rows['EMP-A001 — Staff Alpha']->total_invoices);
        $this->assertSame(1500.0, (float) $rows['EMP-A001 — Staff Alpha']->total_revenue);
        $this->assertSame(1, (int) $rows['EMP-B001 — Staff Beta']->total_invoices);
        $this->assertSame(2000.0, (float) $rows['EMP-B001 — Staff Beta']->total_revenue);

        $details = $data['invoiceDetailsByReferrer'];
        $this->assertCount(2, $details->get((string) $referrerA->id));
        $this->assertSame(['S-REF-A1', 'S-REF-A2'], $details->get((string) $referrerA->id)->pluck('invoice_number')->sort()->values()->all());
    }

    public function test_referrer_wise_sales_report_shows_payment_register_references_on_invoice_details(): void
    {
        $branch = Location::create([
            'code' => 'BR-PAY-RPT',
            'name' => 'Payment Report Branch',
            'type' => 'branch',
            'is_active' => true,
        ]);

        $admin = User::factory()->create([
            'role' => 'admin',
            'is_active' => true,
            'branch_id' => $branch->id,
        ]);

        $invoiceReferrer = Employee::create([
            'employee_code' => 'EMP-INV-1',
            'first_name' => 'Invoice',
            'last_name' => 'Referrer',
            'joining_date' => now()->toDateString(),
            'employment_status' => 'active',
            'is_active' => true,
        ]);

        $paymentReferrer = Employee::create([
            'employee_code' => 'EMP-PAY-1',
            'first_name' => 'Payment',
            'last_name' => 'Collector',
            'joining_date' => now()->toDateString(),
            'employment_status' => 'active',
            'is_active' => true,
        ]);

        $customer = Customer::create([
            'customer_code' => 'C-PAY-RPT',
            'name' => 'Payment Report Customer',
            'phone' => '01700003333',
            'is_active' => true,
        ]);

        $invoice = SalesInvoice::create([
            'invoice_number' => 'S-PAY-RPT-1',
            'branch_id' => $branch->id,
            'customer_id' => $customer->id,
            'referrer_id' => $invoiceReferrer->id,
            'invoice_date' => now()->toDateString(),
            'sale_type' => 'credit',
            'status' => 'posted',
            'payment_status' => 'partial',
            'subtotal' => 2000,
            'discount_total' => 0,
            'tax_total' => 0,
            'grand_total' => 2000,
            'amount_paid' => 800,
            'amount_due' => 1200,
            'created_by' => $admin->id,
        ]);

        $payment = Payment::create([
            'payment_number' => 'PAY-RPT-1',
            'customer_id' => $customer->id,
            'payment_date' => now()->toDateString(),
            'payment_method' => 'cash',
            'amount' => 800,
            'direction' => 'inbound',
            'reference_no' => 'CHQ-778899',
            'referrer_employee_id' => $paymentReferrer->id,
            'recorded_by' => $admin->id,
        ]);

        PaymentAllocation::create([
            'payment_id' => $payment->id,
            'sales_invoice_id' => $invoice->id,
            'allocated_amount' => 800,
            'settlement_discount' => 0,
        ]);

        $this->actingAs($admin);

        $data = app(SalesReportService::class)->representativeWiseSalesPaginated(Request::create('/', 'GET'));
        $invoices = $data['invoiceDetailsByReferrer']->get((string) $invoiceReferrer->id);

        $this->assertNotNull($invoices);
        $this->assertCount(1, $invoices);

        $detailInvoice = $invoices->first();
        $this->assertTrue($detailInvoice->relationLoaded('payments'));
        $this->assertCount(1, $detailInvoice->payments);
        $this->assertSame('CHQ-778899', $detailInvoice->payments->first()->reference_no);
        $this->assertSame($paymentReferrer->id, $detailInvoice->payments->first()->referrer_employee_id);
        $this->assertSame('EMP-PAY-1 — Payment Collector', $detailInvoice->payments->first()->referrerEmployee?->displayLabel());
    }

    public function test_referrer_wise_sales_report_includes_payment_referred_invoices_without_invoice_referrer(): void
    {
        $branch = Location::create([
            'code' => 'BR-PAY-ONLY',
            'name' => 'Payment Only Branch',
            'type' => 'branch',
            'is_active' => true,
        ]);

        $admin = User::factory()->create([
            'role' => 'admin',
            'is_active' => true,
            'branch_id' => $branch->id,
        ]);

        $paymentReferrer = Employee::create([
            'employee_code' => 'EMP-PAY-ONLY',
            'first_name' => 'Only',
            'last_name' => 'PaymentRef',
            'joining_date' => now()->toDateString(),
            'employment_status' => 'active',
            'is_active' => true,
        ]);

        $customer = Customer::create([
            'customer_code' => 'C-PAY-ONLY',
            'name' => 'Payment Only Customer',
            'phone' => '01700005555',
            'is_active' => true,
        ]);

        $invoice = SalesInvoice::create([
            'invoice_number' => 'S-NO-INV-REF',
            'branch_id' => $branch->id,
            'customer_id' => $customer->id,
            'referrer_id' => null,
            'invoice_date' => now()->toDateString(),
            'sale_type' => 'credit',
            'status' => 'posted',
            'payment_status' => 'partial',
            'subtotal' => 1500,
            'discount_total' => 0,
            'tax_total' => 0,
            'grand_total' => 1500,
            'amount_paid' => 600,
            'amount_due' => 900,
            'created_by' => $admin->id,
        ]);

        $payment = Payment::create([
            'payment_number' => 'PAY-ONLY-REF',
            'customer_id' => $customer->id,
            'payment_date' => now()->toDateString(),
            'payment_method' => 'cash',
            'amount' => 600,
            'direction' => 'inbound',
            'reference_no' => 'TXN-445566',
            'referrer_employee_id' => $paymentReferrer->id,
            'recorded_by' => $admin->id,
        ]);

        PaymentAllocation::create([
            'payment_id' => $payment->id,
            'sales_invoice_id' => $invoice->id,
            'allocated_amount' => 600,
            'settlement_discount' => 0,
        ]);

        $this->actingAs($admin);

        $data = app(SalesReportService::class)->representativeWiseSalesPaginated(Request::create('/', 'GET'));

        $paymentReferrerInvoices = $data['invoiceDetailsByReferrer']->get((string) $paymentReferrer->id);
        $this->assertNotNull($paymentReferrerInvoices);
        $this->assertSame(['S-NO-INV-REF'], $paymentReferrerInvoices->pluck('invoice_number')->all());

        $noneReferrerInvoices = $data['invoiceDetailsByReferrer']->get('none', collect());
        $this->assertFalse($noneReferrerInvoices->contains(fn ($row) => $row->invoice_number === 'S-NO-INV-REF'));
    }

    public function test_referrer_wise_sales_report_shows_payment_collection_under_payment_referrer_when_invoice_has_different_referrer(): void
    {
        $branch = Location::create([
            'code' => 'BR-PAY-DIFF',
            'name' => 'Different Referrer Branch',
            'type' => 'branch',
            'is_active' => true,
        ]);

        $admin = User::factory()->create([
            'role' => 'admin',
            'is_active' => true,
            'branch_id' => $branch->id,
        ]);

        $invoiceReferrer = Employee::create([
            'employee_code' => 'EMP-INV-DIFF',
            'first_name' => 'Invoice',
            'last_name' => 'Owner',
            'joining_date' => now()->toDateString(),
            'employment_status' => 'active',
            'is_active' => true,
        ]);

        $paymentReferrer = Employee::create([
            'employee_code' => 'EMP-PAY-DIFF',
            'first_name' => 'Payment',
            'last_name' => 'Collector',
            'joining_date' => now()->toDateString(),
            'employment_status' => 'active',
            'is_active' => true,
        ]);

        $customer = Customer::create([
            'customer_code' => 'C-PAY-DIFF',
            'name' => 'Different Referrer Customer',
            'phone' => '01700006666',
            'is_active' => true,
        ]);

        $invoice = SalesInvoice::create([
            'invoice_number' => 'S-PAY-DIFF-1',
            'branch_id' => $branch->id,
            'customer_id' => $customer->id,
            'referrer_id' => $invoiceReferrer->id,
            'invoice_date' => now()->subMonths(2)->toDateString(),
            'sale_type' => 'credit',
            'status' => 'posted',
            'payment_status' => 'partial',
            'subtotal' => 3000,
            'discount_total' => 0,
            'tax_total' => 0,
            'grand_total' => 3000,
            'amount_paid' => 1000,
            'amount_due' => 2000,
            'created_by' => $admin->id,
        ]);

        $payment = Payment::create([
            'payment_number' => 'PAY-DIFF-1',
            'customer_id' => $customer->id,
            'payment_date' => now()->toDateString(),
            'payment_method' => 'cash',
            'amount' => 1000,
            'direction' => 'inbound',
            'reference_no' => 'RCPT-001',
            'referrer_employee_id' => $paymentReferrer->id,
            'recorded_by' => $admin->id,
        ]);

        PaymentAllocation::create([
            'payment_id' => $payment->id,
            'sales_invoice_id' => $invoice->id,
            'allocated_amount' => 1000,
            'settlement_discount' => 0,
        ]);

        $this->actingAs($admin);

        $request = Request::create('/', 'GET', [
            'representative_id' => (string) $paymentReferrer->id,
            'date_from' => now()->toDateString(),
            'date_to' => now()->toDateString(),
        ]);

        $data = app(SalesReportService::class)->representativeWiseSalesPaginated($request);
        $paymentReferrerInvoices = $data['invoiceDetailsByReferrer']->get((string) $paymentReferrer->id);

        $this->assertNotNull($paymentReferrerInvoices);
        $this->assertSame(['S-PAY-DIFF-1'], $paymentReferrerInvoices->pluck('invoice_number')->all());

        $detailInvoice = $paymentReferrerInvoices->first();
        $this->assertCount(1, $detailInvoice->payments);
        $this->assertSame('RCPT-001', $detailInvoice->payments->first()->reference_no);
        $this->assertSame($paymentReferrer->id, $detailInvoice->payments->first()->referrer_employee_id);
    }
}
