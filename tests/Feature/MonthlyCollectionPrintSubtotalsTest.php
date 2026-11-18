<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\InstallmentPlan;
use App\Models\InstallmentSchedule;
use App\Models\Location;
use App\Models\SalesInvoice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MonthlyCollectionPrintSubtotalsTest extends TestCase
{
    use RefreshDatabase;

    public function test_print_shows_a_subtotal_after_each_table_and_a_grand_total_summary(): void
    {
        $branch = Location::create([
            'code' => 'BR-MCPS',
            'name' => 'Subtotal Branch',
            'type' => 'branch',
            'is_active' => true,
        ]);

        $user = User::factory()->create([
            'role' => 'admin',
            'is_active' => true,
            'branch_id' => $branch->id,
        ]);

        $customer = Customer::create([
            'customer_code' => 'C-MCPS',
            'name' => 'Subtotal Customer',
            'phone' => '01700006666',
            'is_active' => true,
        ]);

        // This-month credit invoice: due today, well inside the current month range.
        SalesInvoice::create([
            'invoice_number' => 'S-MCPS-CRED-THIS',
            'branch_id' => $branch->id,
            'customer_id' => $customer->id,
            'invoice_date' => now()->toDateString(),
            'sale_type' => 'credit',
            'status' => 'posted',
            'payment_status' => 'partial',
            'subtotal' => 800,
            'discount_total' => 0,
            'tax_total' => 0,
            'grand_total' => 800,
            'amount_paid' => 300,
            'amount_due' => 500,
            'credit_due_date' => now()->toDateString(),
            'created_by' => $user->id,
        ]);

        // Overdue credit invoice: due well before this month.
        SalesInvoice::create([
            'invoice_number' => 'S-MCPS-CRED-OD',
            'branch_id' => $branch->id,
            'customer_id' => $customer->id,
            'invoice_date' => now()->subMonths(6)->toDateString(),
            'sale_type' => 'credit',
            'status' => 'posted',
            'payment_status' => 'partial',
            'subtotal' => 1200,
            'discount_total' => 0,
            'tax_total' => 0,
            'grand_total' => 1200,
            'amount_paid' => 200,
            'amount_due' => 1000,
            'credit_due_date' => now()->subMonths(3)->toDateString(),
            'created_by' => $user->id,
        ]);

        // Installment invoice with one schedule due today (this month) and one
        // schedule due well in the past (overdue).
        $instInvoice = SalesInvoice::create([
            'invoice_number' => 'S-MCPS-INST',
            'branch_id' => $branch->id,
            'customer_id' => $customer->id,
            'invoice_date' => now()->subMonths(2)->toDateString(),
            'sale_type' => 'installment',
            'status' => 'active',
            'payment_status' => 'partial',
            'subtotal' => 1000,
            'discount_total' => 0,
            'tax_total' => 0,
            'grand_total' => 1000,
            'amount_paid' => 0,
            'amount_due' => 1000,
            'installment_count' => 2,
            'installment_first_due_date' => now()->subMonths(2)->toDateString(),
            'installment_frequency' => 'monthly',
            'created_by' => $user->id,
        ]);

        $plan = InstallmentPlan::create([
            'sales_invoice_id' => $instInvoice->id,
            'customer_id' => $customer->id,
            'total_amount' => 1000,
            'down_payment' => 0,
            'financed_amount' => 1000,
            'remaining_amount' => 1000,
            'penalty_enabled' => false,
            'penalty_rate' => 0,
            'status' => 'active',
            'created_by' => $user->id,
        ]);

        InstallmentSchedule::create([
            'installment_plan_id' => $plan->id,
            'installment_no' => 1,
            'due_date' => now()->subMonths(2)->toDateString(),
            'due_amount' => 500,
            'paid_amount' => 0,
            'status' => 'overdue',
        ]);

        InstallmentSchedule::create([
            'installment_plan_id' => $plan->id,
            'installment_no' => 2,
            'due_date' => now()->toDateString(),
            'due_amount' => 500,
            'paid_amount' => 0,
            'status' => 'pending',
        ]);

        $response = $this->actingAs($user)->get(route('reports.print', [
            'slug' => 'monthly-collection',
            'branch_id' => $branch->id,
        ]));

        $response->assertOk();

        // A subtotal line right after each of the four per-branch tables.
        $response->assertSee('Installments subtotal', false);
        $response->assertSee('Credit &amp; default subtotal', false);
        $response->assertSee('Overdue installments subtotal', false);
        $response->assertSee('Overdue credit &amp; default subtotal', false);

        // A grand total summary at the very end of the report.
        $response->assertSee('Report totals (all branches)', false);
        $response->assertSee('Grand total', false);

        $html = $response->getContent();

        // Installments subtotal: only the schedule due this month (500) counts here.
        $this->assertMatchesRegularExpression('/Installments subtotal[^<]*<\/strong><\/td>\s*<td class="num">Due 500/s', $html);
        // Overdue installments subtotal: only the overdue schedule (500) counts here.
        $this->assertMatchesRegularExpression('/Overdue installments subtotal[^<]*<\/strong><\/td>\s*<td class="num">Due 500/s', $html);
        // Credit & default subtotal: grand 800, paid 300, due 500.
        $this->assertMatchesRegularExpression('/Credit &amp; default subtotal[^<]*<\/strong><\/td>\s*<td class="num">Grand 800.*?Paid 300.*?Due 500/s', $html);
        // Overdue credit & default subtotal: grand 1,200, paid 200, due 1,000.
        $this->assertMatchesRegularExpression('/Overdue credit &amp; default subtotal[^<]*<\/strong><\/td>\s*<td class="num">Grand 1,200.*?Paid 200.*?Due 1,000/s', $html);

        // Grand total row: paid 500 (300+200), due 2,500 (500+500+500+1,000).
        $this->assertMatchesRegularExpression('/Grand total<\/td>\s*<td class="num">—<\/td>\s*<td class="num">500<\/td>\s*<td class="num">0<\/td>\s*<td class="num">2,500<\/td>/s', $html);
    }
}
