<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Customer;
use App\Models\InstallmentPlan;
use App\Models\InstallmentSchedule;
use App\Models\Location;
use App\Models\Product;
use App\Models\ReturnLine;
use App\Models\ReturnOrder;
use App\Models\SaleLineSerial;
use App\Models\SalesInvoice;
use App\Models\SalesInvoiceLine;
use App\Models\SerialItem;
use App\Models\User;
use App\Services\ReportDataService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MonthlyCollectionReturnedInvoiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_monthly_collection_excludes_fully_returned_credit_invoices(): void
    {
        [$branch, $user, $customer] = $this->seedBranchUserCustomer();

        $openCredit = SalesInvoice::create([
            'invoice_number' => 'S-CRED-OPEN',
            'branch_id' => $branch->id,
            'customer_id' => $customer->id,
            'invoice_date' => now()->subMonth()->toDateString(),
            'sale_type' => 'credit',
            'status' => 'posted',
            'payment_status' => 'partial',
            'subtotal' => 5000,
            'discount_total' => 0,
            'tax_total' => 0,
            'grand_total' => 5000,
            'amount_paid' => 1000,
            'amount_due' => 4000,
            'credit_due_date' => now()->addDays(3)->toDateString(),
            'created_by' => $user->id,
        ]);

        $returnedCredit = $this->seedFullyReturnedCreditInvoice($branch, $user, $customer, 'S-CRED-RET');

        $service = app(ReportDataService::class);
        $request = request()->duplicate(['branch_id' => (string) $branch->id]);
        $data = $service->monthlyCollectionPaginated($request);

        $listedNumbers = collect()
            ->merge($data['thisMonthCredits']->pluck('invoice_number'))
            ->merge($data['overdueCredits']->pluck('invoice_number'))
            ->all();

        $this->assertContains('S-CRED-OPEN', $listedNumbers);
        $this->assertNotContains('S-CRED-RET', $listedNumbers);

        $totals = $service->monthlyCollectionTotals($request);
        $this->assertSame(4000.0, $totals['credit_due']);
    }

    public function test_monthly_collection_excludes_returned_installment_invoices(): void
    {
        [$branch, $user, $customer] = $this->seedBranchUserCustomer();

        $activeInstallment = $this->seedInstallmentWithSchedule(
            $branch,
            $user,
            $customer,
            'S-INST-OPEN',
            now()->addDays(4)->toDateString(),
        );

        $returnedInstallment = $this->seedInstallmentWithSchedule(
            $branch,
            $user,
            $customer,
            'S-INST-RET',
            now()->addDays(6)->toDateString(),
        );
        $returnedInstallment->update(['status' => 'returned']);

        $service = app(ReportDataService::class);
        $request = request()->duplicate(['branch_id' => (string) $branch->id]);
        $data = $service->monthlyCollectionPaginated($request);

        $listedNumbers = collect()
            ->merge($data['thisMonthInstallments']->pluck('invoice')->pluck('invoice_number'))
            ->merge($data['overdueInstallments']->pluck('invoice')->pluck('invoice_number'))
            ->filter()
            ->all();

        $this->assertContains('S-INST-OPEN', $listedNumbers);
        $this->assertNotContains('S-INST-RET', $listedNumbers);

        $totals = $service->monthlyCollectionTotals($request);
        $this->assertSame(1500.0, $totals['installment_remaining']);
    }

    /**
     * @return array{0: Location, 1: User, 2: Customer}
     */
    private function seedBranchUserCustomer(): array
    {
        $branch = Location::create([
            'code' => 'BR-MCRET',
            'name' => 'Returned filter branch',
            'type' => 'branch',
            'is_active' => true,
        ]);

        $user = User::factory()->create([
            'role' => 'admin',
            'is_active' => true,
            'branch_id' => $branch->id,
        ]);

        $customer = Customer::create([
            'customer_code' => 'C-MCRET',
            'name' => 'Returned filter customer',
            'phone' => '01700007777',
            'is_active' => true,
        ]);

        return [$branch, $user, $customer];
    }

    private function seedFullyReturnedCreditInvoice(Location $branch, User $user, Customer $customer, string $invoiceNumber): SalesInvoice
    {
        $brand = Brand::create(['name' => 'Return Brand '.$invoiceNumber]);
        $category = Category::create(['name' => 'Return Category '.$invoiceNumber]);
        $product = Product::create([
            'sku' => 'SKU-RET-'.$invoiceNumber,
            'brand_id' => $brand->id,
            'category_id' => $category->id,
            'name' => 'Returned product',
            'warranty_months' => 12,
            'default_purchase_price' => 1000,
            'default_sale_price' => 1500,
            'reorder_level' => 0,
            'unit_of_measure' => 'unit',
            'is_serialized' => false,
            'is_active' => true,
        ]);

        $invoice = SalesInvoice::create([
            'invoice_number' => $invoiceNumber,
            'branch_id' => $branch->id,
            'customer_id' => $customer->id,
            'invoice_date' => now()->subMonths(2)->toDateString(),
            'sale_type' => 'credit',
            'status' => 'posted',
            'payment_status' => 'partial',
            'subtotal' => 3000,
            'discount_total' => 0,
            'tax_total' => 0,
            'grand_total' => 3000,
            'amount_paid' => 500,
            'amount_due' => 2500,
            'credit_due_date' => now()->addDays(2)->toDateString(),
            'created_by' => $user->id,
        ]);

        $line = SalesInvoiceLine::create([
            'sales_invoice_id' => $invoice->id,
            'product_id' => $product->id,
            'qty' => 2,
            'unit_price' => 1500,
            'discount_amount' => 0,
            'tax_amount' => 0,
            'line_total' => 3000,
            'warranty_months_snapshot' => 12,
        ]);

        $returnOrder = ReturnOrder::create([
            'return_number' => 'RET-'.$invoiceNumber,
            'sales_invoice_id' => $invoice->id,
            'customer_id' => $customer->id,
            'branch_id' => $branch->id,
            'status' => 'refunded',
            'return_date' => now()->toDateString(),
            'reason' => 'Full return',
            'created_by' => $user->id,
        ]);

        ReturnLine::create([
            'return_order_id' => $returnOrder->id,
            'sales_invoice_line_id' => $line->id,
            'serial_item_id' => null,
            'qty' => 2,
            'resolution' => 'restock',
        ]);

        return $invoice;
    }

    private function seedInstallmentWithSchedule(
        Location $branch,
        User $user,
        Customer $customer,
        string $invoiceNumber,
        string $dueDate,
    ): SalesInvoice {
        $invoice = SalesInvoice::create([
            'invoice_number' => $invoiceNumber,
            'branch_id' => $branch->id,
            'customer_id' => $customer->id,
            'invoice_date' => now()->subMonths(3)->toDateString(),
            'sale_type' => 'installment',
            'status' => 'active',
            'payment_status' => 'partial',
            'subtotal' => 6000,
            'discount_total' => 0,
            'tax_total' => 0,
            'grand_total' => 6000,
            'amount_paid' => 1500,
            'amount_due' => 4500,
            'created_by' => $user->id,
        ]);

        $plan = InstallmentPlan::create([
            'sales_invoice_id' => $invoice->id,
            'customer_id' => $customer->id,
            'total_amount' => 6000,
            'down_payment' => 1500,
            'financed_amount' => 4500,
            'remaining_amount' => 4500,
            'status' => 'active',
            'created_by' => $user->id,
        ]);

        InstallmentSchedule::create([
            'installment_plan_id' => $plan->id,
            'installment_no' => 1,
            'due_date' => $dueDate,
            'due_amount' => 1500,
            'paid_amount' => 0,
            'status' => 'pending',
        ]);

        return $invoice;
    }
}
