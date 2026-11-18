<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Customer;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\LedgerAccount;
use App\Models\Location;
use App\Models\Product;
use App\Models\SaleLineSerial;
use App\Models\SalesInvoice;
use App\Models\SalesInvoiceLine;
use App\Models\SerialItem;
use App\Models\StockLedger;
use App\Models\User;
use App\Services\InventoryBalanceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SalesInvoiceBranchChangeTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_change_branch_on_draft_invoice(): void
    {
        [$user, $fromBranch, $toBranch, $customer] = $this->seedBranchesAndCustomer();

        $invoice = SalesInvoice::create([
            'invoice_number' => 'INV-DR-1',
            'invoice_date' => now()->toDateString(),
            'branch_id' => $fromBranch->id,
            'customer_id' => $customer->id,
            'sale_type' => 'default',
            'status' => 'draft',
            'subtotal' => 1000,
            'payment_status' => 'unpaid',
            'discount_total' => 0,
            'tax_total' => 0,
            'grand_total' => 1000,
            'amount_paid' => 0,
            'amount_due' => 1000,
            'created_by' => $user->id,
        ]);

        $response = $this->actingAs($user)->post(route('sales.invoices.branch', $invoice), [
            '_form' => 'branch',
            'branch_id' => $toBranch->id,
        ]);

        $response->assertRedirect(route('sales.invoices.show', $invoice));

        $invoice->refresh();
        $this->assertSame((int) $toBranch->id, (int) $invoice->branch_id);
    }

    public function test_admin_can_change_branch_on_posted_invoice_and_moves_stock_and_gl(): void
    {
        [$user, $fromBranch, $toBranch, $customer] = $this->seedBranchesAndCustomer();

        $brand = Brand::create(['name' => 'BC Brand']);
        $category = Category::create(['name' => 'BC Category']);

        $product = Product::create([
            'sku' => 'SKU-BC-1',
            'brand_id' => $brand->id,
            'category_id' => $category->id,
            'name' => 'Branch change product',
            'warranty_months' => 12,
            'default_purchase_price' => 3000,
            'default_sale_price' => 5000,
            'reorder_level' => 0,
            'unit_of_measure' => 'unit',
            'is_serialized' => true,
            'is_active' => true,
        ]);

        $serial = SerialItem::create([
            'product_id' => $product->id,
            'serial_number' => 'SN-BC-1',
            'serial_source' => 'internal',
            'current_location_id' => $fromBranch->id,
            'status' => 'sold',
            'purchase_cost' => 3000,
        ]);

        $invoice = SalesInvoice::create([
            'invoice_number' => 'INV-PO-1',
            'invoice_date' => now()->toDateString(),
            'branch_id' => $fromBranch->id,
            'customer_id' => $customer->id,
            'sale_type' => 'default',
            'status' => 'posted',
            'subtotal' => 5000,
            'payment_status' => 'unpaid',
            'discount_total' => 0,
            'tax_total' => 0,
            'grand_total' => 5000,
            'amount_paid' => 0,
            'amount_due' => 5000,
            'created_by' => $user->id,
        ]);

        $line = SalesInvoiceLine::create([
            'sales_invoice_id' => $invoice->id,
            'product_id' => $product->id,
            'qty' => 1,
            'unit_price' => 5000,
            'discount_amount' => 0,
            'tax_amount' => 0,
            'line_total' => 5000,
            'warranty_months_snapshot' => 12,
        ]);

        $serial->update(['sale_invoice_line_id' => $line->id]);

        SaleLineSerial::create([
            'sales_invoice_line_id' => $line->id,
            'serial_item_id' => $serial->id,
        ]);

        StockLedger::create([
            'event_time' => now(),
            'product_id' => $product->id,
            'serial_item_id' => $serial->id,
            'location_id' => $fromBranch->id,
            'movement_type' => 'sale_issue',
            'quantity_delta' => -1,
            'unit_cost' => 3000,
            'reference_type' => SalesInvoice::class,
            'reference_id' => $invoice->id,
            'actor_user_id' => $user->id,
        ]);

        $arAccount = LedgerAccount::create([
            'code' => '1100',
            'name' => 'Accounts Receivable',
            'type' => 'asset',
            'is_active' => true,
        ]);
        $revenueAccount = LedgerAccount::create([
            'code' => '4000',
            'name' => 'Sales Revenue',
            'type' => 'revenue',
            'is_active' => true,
        ]);

        $journalEntry = JournalEntry::create([
            'branch_id' => $fromBranch->id,
            'entry_date' => now()->toDateString(),
            'description' => 'Invoice posted',
            'source' => 'sales_invoice',
            'status' => 'posted',
            'reference_type' => SalesInvoice::class,
            'reference_id' => $invoice->id,
            'created_by' => $user->id,
        ]);

        JournalLine::create([
            'journal_entry_id' => $journalEntry->id,
            'ledger_account_id' => $arAccount->id,
            'debit' => 5000,
            'credit' => 0,
            'line_memo' => 'AR',
        ]);
        JournalLine::create([
            'journal_entry_id' => $journalEntry->id,
            'ledger_account_id' => $revenueAccount->id,
            'debit' => 0,
            'credit' => 5000,
            'line_memo' => 'Revenue',
        ]);

        app(InventoryBalanceService::class)->recalculate($product->id, $fromBranch->id);
        app(InventoryBalanceService::class)->recalculate($product->id, $toBranch->id);

        $response = $this->actingAs($user)->post(route('sales.invoices.branch', $invoice), [
            '_form' => 'branch',
            'branch_id' => $toBranch->id,
        ]);

        $response->assertRedirect(route('sales.invoices.show', $invoice));

        $invoice->refresh();
        $serial->refresh();
        $journalEntry->refresh();

        $this->assertSame((int) $toBranch->id, (int) $invoice->branch_id);
        $this->assertSame((int) $toBranch->id, (int) $serial->current_location_id);

        $this->assertDatabaseHas('stock_ledger', [
            'reference_id' => $invoice->id,
            'reference_type' => SalesInvoice::class,
            'location_id' => $toBranch->id,
            'movement_type' => 'sale_issue',
            'quantity_delta' => -1,
        ]);

        $this->assertSame((int) $toBranch->id, (int) $journalEntry->branch_id);
    }

    /**
     * @return array{0: User, 1: Location, 2: Location, 3: Customer}
     */
    private function seedBranchesAndCustomer(): array
    {
        $fromBranch = Location::create([
            'code' => 'BC-FROM',
            'name' => 'From Branch',
            'type' => 'branch',
            'address' => 'Test',
            'is_active' => true,
        ]);

        $toBranch = Location::create([
            'code' => 'BC-TO',
            'name' => 'To Branch',
            'type' => 'branch',
            'address' => 'Test',
            'is_active' => true,
        ]);

        $user = User::factory()->create([
            'role' => 'admin',
            'is_active' => true,
            'branch_id' => $fromBranch->id,
        ]);

        $customer = Customer::create([
            'customer_code' => 'C-BC',
            'name' => 'Branch Change Customer',
            'phone' => '01701000000',
            'is_active' => true,
        ]);

        return [$user, $fromBranch, $toBranch, $customer];
    }
}
