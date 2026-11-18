<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Location;
use App\Models\SalesInvoice;
use App\Models\User;
use App\Services\ReportDataService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

class MonthlyCollectionDateRangeTest extends TestCase
{
    use RefreshDatabase;

    public function test_collection_report_respects_custom_date_range(): void
    {
        $branch = Location::create([
            'code' => 'BR-MCR',
            'name' => 'Collection Range Branch',
            'type' => 'branch',
            'is_active' => true,
        ]);

        $user = User::factory()->create([
            'role' => 'admin',
            'is_active' => true,
            'branch_id' => $branch->id,
        ]);

        $customer = Customer::create([
            'customer_code' => 'C-MCR',
            'name' => 'Collection Range Customer',
            'phone' => '01700003333',
            'is_active' => true,
        ]);

        $inRange = SalesInvoice::create([
            'invoice_number' => 'S-RANGE-IN',
            'branch_id' => $branch->id,
            'customer_id' => $customer->id,
            'invoice_date' => '2026-03-10',
            'sale_type' => 'default',
            'status' => 'posted',
            'payment_status' => 'unpaid',
            'subtotal' => 800,
            'discount_total' => 0,
            'tax_total' => 0,
            'grand_total' => 800,
            'amount_paid' => 0,
            'amount_due' => 800,
            'created_by' => $user->id,
        ]);

        $outOfRange = SalesInvoice::create([
            'invoice_number' => 'S-RANGE-OUT',
            'branch_id' => $branch->id,
            'customer_id' => $customer->id,
            'invoice_date' => '2026-05-15',
            'sale_type' => 'default',
            'status' => 'posted',
            'payment_status' => 'unpaid',
            'subtotal' => 900,
            'discount_total' => 0,
            'tax_total' => 0,
            'grand_total' => 900,
            'amount_paid' => 0,
            'amount_due' => 900,
            'created_by' => $user->id,
        ]);

        $this->actingAs($user);

        $request = Request::create('/', 'GET', [
            'date_from' => '2026-03-01',
            'date_to' => '2026-03-31',
        ]);

        $data = app(ReportDataService::class)->monthlyCollectionPaginated($request);

        $creditIds = $data['thisMonthCredits']->pluck('id')->all();

        $this->assertContains($inRange->id, $creditIds);
        $this->assertNotContains($outOfRange->id, $creditIds);
    }
}
