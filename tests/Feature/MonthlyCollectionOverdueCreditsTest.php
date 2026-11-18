<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Location;
use App\Models\SalesInvoice;
use App\Models\User;
use App\Services\ReportDataService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MonthlyCollectionOverdueCreditsTest extends TestCase
{
    use RefreshDatabase;

    public function test_overdue_credits_include_past_due_dates_before_current_month(): void
    {
        $branch = Location::create([
            'code' => 'BR-MCOC',
            'name' => 'Collection Branch',
            'type' => 'branch',
            'is_active' => true,
        ]);

        $user = User::factory()->create([
            'role' => 'admin',
            'is_active' => true,
            'branch_id' => $branch->id,
        ]);

        $customer = Customer::create([
            'customer_code' => 'C-MCOC',
            'name' => 'Old Credit Customer',
            'phone' => '01700008888',
            'is_active' => true,
        ]);

        $oldOverdue = SalesInvoice::create([
            'invoice_number' => 'S-CRED-OLD',
            'branch_id' => $branch->id,
            'customer_id' => $customer->id,
            'invoice_date' => now()->subMonths(8)->toDateString(),
            'sale_type' => 'credit',
            'status' => 'posted',
            'payment_status' => 'partial',
            'subtotal' => 5000,
            'discount_total' => 0,
            'tax_total' => 0,
            'grand_total' => 5000,
            'amount_paid' => 1000,
            'amount_due' => 4000,
            'credit_due_date' => now()->subMonths(3)->toDateString(),
            'created_by' => $user->id,
        ]);

        $thisMonthCredit = SalesInvoice::create([
            'invoice_number' => 'S-CRED-THIS',
            'branch_id' => $branch->id,
            'customer_id' => $customer->id,
            'invoice_date' => now()->subMonth()->toDateString(),
            'sale_type' => 'credit',
            'status' => 'posted',
            'payment_status' => 'partial',
            'subtotal' => 2000,
            'discount_total' => 0,
            'tax_total' => 0,
            'grand_total' => 2000,
            'amount_paid' => 0,
            'amount_due' => 2000,
            'credit_due_date' => now()->addDays(5)->toDateString(),
            'created_by' => $user->id,
        ]);

        $paidOld = SalesInvoice::create([
            'invoice_number' => 'S-CRED-PAID',
            'branch_id' => $branch->id,
            'customer_id' => $customer->id,
            'invoice_date' => now()->subYear()->toDateString(),
            'sale_type' => 'credit',
            'status' => 'posted',
            'payment_status' => 'paid',
            'subtotal' => 1000,
            'discount_total' => 0,
            'tax_total' => 0,
            'grand_total' => 1000,
            'amount_paid' => 1000,
            'amount_due' => 0,
            'credit_due_date' => now()->subMonths(6)->toDateString(),
            'created_by' => $user->id,
        ]);

        $service = app(ReportDataService::class);
        $request = request()->duplicate(['branch_id' => (string) $branch->id]);
        $data = $service->monthlyCollectionPaginated($request);

        $overdueNumbers = $data['overdueCredits']->pluck('invoice_number')->all();
        $thisMonthNumbers = $data['thisMonthCredits']->pluck('invoice_number')->all();

        $this->assertContains('S-CRED-OLD', $overdueNumbers);
        $this->assertNotContains('S-CRED-PAID', $overdueNumbers);
        $this->assertContains('S-CRED-THIS', $thisMonthNumbers);
        $this->assertNotContains('S-CRED-OLD', $thisMonthNumbers);

        $totals = $service->monthlyCollectionTotals($request);
        $this->assertSame(4000.0, $totals['overdue_credit_due']);
        $this->assertSame(2000.0, $totals['credit_due']);
        $this->assertGreaterThanOrEqual(3, $oldOverdue->creditMonthsLate());
        $this->assertSame(0, $thisMonthCredit->creditMonthsLate());
    }

}
