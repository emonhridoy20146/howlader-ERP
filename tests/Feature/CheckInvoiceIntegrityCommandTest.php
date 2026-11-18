<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\InvoiceDebtWriteoff;
use App\Models\Location;
use App\Models\SalesInvoice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CheckInvoiceIntegrityCommandTest extends TestCase
{
    use RefreshDatabase;

    private function makeInvoice(array $overrides = []): SalesInvoice
    {
        $branch = Location::create(['code' => 'BR-CI', 'name' => 'Integrity Branch', 'type' => 'branch', 'is_active' => true]);
        $user = User::factory()->create(['role' => 'admin', 'is_active' => true, 'branch_id' => $branch->id]);
        $customer = Customer::create(['customer_code' => 'C-CI', 'name' => 'Integrity Customer', 'phone' => '01700000321', 'is_active' => true]);

        return SalesInvoice::create(array_merge([
            'invoice_number' => 'INV-CI-1',
            'branch_id' => $branch->id,
            'customer_id' => $customer->id,
            'invoice_date' => '2026-06-04',
            'sale_type' => 'credit',
            'status' => 'posted',
            'payment_status' => 'paid',
            'subtotal' => 1000,
            'discount_total' => 0,
            'tax_total' => 0,
            'grand_total' => 1000,
            'amount_paid' => 1000,
            'amount_due' => 0,
            'created_by' => $user->id,
        ], $overrides));
    }

    public function test_consistent_invoice_reports_no_problems(): void
    {
        $this->makeInvoice();

        $this->artisan('invoices:check-integrity', ['--fail' => true])
            ->expectsOutputToContain('No integrity problems found.')
            ->assertSuccessful();
    }

    public function test_header_that_does_not_add_up_is_flagged_and_not_modified(): void
    {
        $invoice = $this->makeInvoice(['amount_paid' => 700, 'amount_due' => 0]);

        $this->artisan('invoices:check-integrity', ['--fail' => true])
            ->expectsOutputToContain('!= grand total')
            ->assertFailed();

        $invoice->refresh();
        $this->assertSame(700.0, (float) $invoice->amount_paid);
    }

    public function test_written_off_invoice_with_matching_header_is_clean(): void
    {
        $invoice = $this->makeInvoice();
        InvoiceDebtWriteoff::create([
            'sales_invoice_id' => $invoice->id,
            'amount' => 1000,
            'actor_user_id' => $invoice->created_by,
        ]);

        $this->artisan('invoices:check-integrity', ['--fail' => true])->assertSuccessful();
        $this->assertSame(1, DB::table('invoice_debt_writeoffs')->count());
    }
}
