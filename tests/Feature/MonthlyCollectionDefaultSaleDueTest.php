<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Location;
use App\Models\SalesInvoice;
use App\Models\User;
use App\Services\ReportDataService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MonthlyCollectionDefaultSaleDueTest extends TestCase
{
    use RefreshDatabase;

    public function test_unpaid_default_sales_appear_in_collection_until_paid(): void
    {
        $branch = Location::create([
            'code' => 'BR-MCD',
            'name' => 'Default Due Branch',
            'type' => 'branch',
            'is_active' => true,
        ]);

        $user = User::factory()->create([
            'role' => 'admin',
            'is_active' => true,
            'branch_id' => $branch->id,
        ]);

        $customer = Customer::create([
            'customer_code' => 'C-MCD',
            'name' => 'Default Due Customer',
            'phone' => '01700009999',
            'is_active' => true,
        ]);

        $oldDefault = SalesInvoice::create([
            'invoice_number' => 'S-DEF-OLD',
            'branch_id' => $branch->id,
            'customer_id' => $customer->id,
            'invoice_date' => now()->subMonths(4)->toDateString(),
            'sale_type' => 'default',
            'status' => 'posted',
            'payment_status' => 'partial',
            'subtotal' => 3000,
            'discount_total' => 0,
            'tax_total' => 0,
            'grand_total' => 3000,
            'amount_paid' => 500,
            'amount_due' => 2500,
            'created_by' => $user->id,
        ]);

        $thisMonthDefault = SalesInvoice::create([
            'invoice_number' => 'S-DEF-THIS',
            'branch_id' => $branch->id,
            'customer_id' => $customer->id,
            'invoice_date' => now()->startOfMonth()->addDays(2)->toDateString(),
            'sale_type' => 'default',
            'status' => 'posted',
            'payment_status' => 'unpaid',
            'subtotal' => 1200,
            'discount_total' => 0,
            'tax_total' => 0,
            'grand_total' => 1200,
            'amount_paid' => 0,
            'amount_due' => 1200,
            'created_by' => $user->id,
        ]);

        $paidDefault = SalesInvoice::create([
            'invoice_number' => 'S-DEF-PAID',
            'branch_id' => $branch->id,
            'customer_id' => $customer->id,
            'invoice_date' => now()->subMonths(2)->toDateString(),
            'sale_type' => 'default',
            'status' => 'posted',
            'payment_status' => 'paid',
            'subtotal' => 800,
            'discount_total' => 0,
            'tax_total' => 0,
            'grand_total' => 800,
            'amount_paid' => 800,
            'amount_due' => 0,
            'created_by' => $user->id,
        ]);

        $service = app(ReportDataService::class);
        $request = request()->duplicate(['branch_id' => (string) $branch->id]);
        $data = $service->monthlyCollectionPaginated($request);

        $overdueNumbers = $data['overdueCredits']->pluck('invoice_number')->all();
        $thisMonthNumbers = $data['thisMonthCredits']->pluck('invoice_number')->all();

        $this->assertContains('S-DEF-OLD', $overdueNumbers);
        $this->assertContains('S-DEF-THIS', $thisMonthNumbers);
        $this->assertNotContains('S-DEF-PAID', $overdueNumbers);
        $this->assertNotContains('S-DEF-PAID', $thisMonthNumbers);

        $totals = $service->monthlyCollectionTotals($request);
        $this->assertSame(2500.0, $totals['overdue_credit_due']);
        $this->assertSame(1200.0, $totals['credit_due']);

        $this->assertGreaterThanOrEqual(3, $oldDefault->creditMonthsLate());
        $this->assertSame(0, $thisMonthDefault->creditMonthsLate());
        $this->assertSame(
            $oldDefault->invoice_date?->toDateString(),
            $oldDefault->collectionDueDate()?->toDateString(),
        );
    }
}
