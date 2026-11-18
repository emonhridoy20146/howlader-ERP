<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Location;
use App\Models\SalesInvoice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PurgeOrphanPaymentAllocationsCommandTest extends TestCase
{
    use RefreshDatabase;

    private function orphan(): void
    {
        $branch = Location::create(['code' => 'BR-PO', 'name' => 'Purge Branch', 'type' => 'branch', 'is_active' => true]);
        $user = User::factory()->create(['role' => 'admin', 'is_active' => true, 'branch_id' => $branch->id]);
        $customer = Customer::create(['customer_code' => 'C-PO', 'name' => 'Purge Customer', 'phone' => '01700000777', 'is_active' => true]);
        $invoice = SalesInvoice::create([
            'invoice_number' => 'S-PO-1', 'branch_id' => $branch->id, 'customer_id' => $customer->id,
            'invoice_date' => '2026-06-04', 'sale_type' => 'credit', 'status' => 'posted', 'payment_status' => 'paid',
            'subtotal' => 1000, 'discount_total' => 0, 'tax_total' => 0, 'grand_total' => 1000,
            'amount_paid' => 1000, 'amount_due' => 0, 'created_by' => $user->id,
        ]);

        // Production lacks the payment_id foreign key, so orphans can exist there; simulate that.
        DB::statement('PRAGMA defer_foreign_keys = ON');
        DB::table('payment_allocations')->insert([
            'payment_id' => 999999, 'sales_invoice_id' => $invoice->id, 'allocated_amount' => 500,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_preview_keeps_rows(): void
    {
        $this->orphan();

        $this->artisan('payment-allocations:purge-orphans')->expectsOutputToContain('Preview only')->assertSuccessful();

        $this->assertSame(1, DB::table('payment_allocations')->count());
    }

    public function test_apply_exports_csv_then_deletes(): void
    {
        Storage::fake('local');
        $this->orphan();

        $this->artisan('payment-allocations:purge-orphans', ['--apply' => true])->assertSuccessful();

        $this->assertSame(0, DB::table('payment_allocations')->count());
        $files = Storage::disk('local')->files();
        $this->assertCount(1, $files);
        $this->assertStringContainsString('999999', Storage::disk('local')->get($files[0]));
    }
}
