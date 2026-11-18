<?php

namespace Tests\Support;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Location;
use App\Models\Product;
use App\Models\ReturnLine;
use App\Models\ReturnOrder;
use App\Models\SaleLineSerial;
use App\Models\SalesInvoice;
use App\Models\SalesInvoiceLine;
use App\Models\SerialItem;
use App\Models\StockLedger;
use App\Models\User;

trait SeedsReturnScenario
{
    /**
     * @return array{0: User, 1: ReturnOrder, 2: \App\Models\Wallet, 3: SerialItem}
     */
    protected function seedReturnScenario(float $lineTotal): array
    {
        $location = Location::create([
            'code' => 'LOC-1',
            'name' => 'Main Branch',
            'type' => 'branch',
            'address' => 'Dhaka',
            'is_active' => true,
        ]);

        $user = User::factory()->create([
            'role' => 'admin',
            'is_active' => true,
            'branch_id' => $location->id,
        ]);

        $customer = Customer::create([
            'customer_code' => 'C-100',
            'name' => 'Test Customer',
            'phone' => '01700000000',
            'is_active' => true,
            'outstanding_balance' => 0,
        ]);

        $brand = Brand::create(['name' => 'Brand A']);
        $category = Category::create(['name' => 'Category A']);
        $product = Product::create([
            'sku' => 'SKU-100',
            'brand_id' => $brand->id,
            'category_id' => $category->id,
            'name' => 'Phone X',
            'warranty_months' => 12,
            'default_purchase_price' => 120000,
            'default_sale_price' => 200000,
            'reorder_level' => 1,
            'unit_of_measure' => 'unit',
            'is_serialized' => true,
            'is_active' => true,
        ]);

        $invoice = SalesInvoice::create([
            'invoice_number' => 'INV-1001',
            'invoice_date' => now()->toDateString(),
            'branch_id' => $location->id,
            'customer_id' => $customer->id,
            'sale_type' => 'cash',
            'status' => 'posted',
            'subtotal' => $lineTotal,
            'payment_status' => 'paid',
            'discount_total' => 0,
            'tax_total' => 0,
            'grand_total' => $lineTotal,
            'amount_paid' => $lineTotal,
            'amount_due' => 0,
            'created_by' => $user->id,
        ]);

        $line = SalesInvoiceLine::create([
            'sales_invoice_id' => $invoice->id,
            'product_id' => $product->id,
            'qty' => 1,
            'unit_price' => $lineTotal,
            'discount_amount' => 0,
            'tax_amount' => 0,
            'line_total' => $lineTotal,
            'warranty_months_snapshot' => 12,
        ]);

        $serial = SerialItem::create([
            'product_id' => $product->id,
            'serial_number' => 'SN-001',
            'serial_source' => 'internal',
            'current_location_id' => $location->id,
            'status' => 'sold',
            'sale_invoice_line_id' => $line->id,
            'purchase_cost' => 120000,
        ]);

        SaleLineSerial::create([
            'sales_invoice_line_id' => $line->id,
            'serial_item_id' => $serial->id,
        ]);

        StockLedger::create([
            'event_time' => now(),
            'product_id' => $product->id,
            'serial_item_id' => $serial->id,
            'location_id' => $location->id,
            'movement_type' => 'sale_issue',
            'quantity_delta' => -1,
            'unit_cost' => 120000,
            'reference_type' => 'SalesInvoice',
            'reference_id' => $invoice->id,
            'actor_user_id' => $user->id,
        ]);

        $returnOrder = ReturnOrder::create([
            'return_number' => 'RET-001',
            'sales_invoice_id' => $invoice->id,
            'customer_id' => $customer->id,
            'branch_id' => $location->id,
            'status' => 'draft',
            'return_date' => now()->toDateString(),
            'reason' => 'Returned item',
            'created_by' => $user->id,
        ]);

        ReturnLine::create([
            'return_order_id' => $returnOrder->id,
            'sales_invoice_line_id' => $line->id,
            'serial_item_id' => $serial->id,
            'qty' => null,
            'resolution' => 'restock',
            'condition_notes' => null,
        ]);

        $ledger = \App\Models\LedgerAccount::create([
            'code' => 'W-LEDGER-'.$invoice->id,
            'name' => 'Wallet Ledger '.$invoice->id,
            'type' => 'asset',
            'is_system' => false,
            'is_active' => true,
        ]);

        $wallet = \App\Models\Wallet::create([
            'ledger_account_id' => $ledger->id,
            'code' => 'W-CODE-'.$invoice->id,
            'name' => 'Wallet '.$invoice->id,
            'kind' => 'cash',
            'is_active' => true,
        ]);
        $wallet->locations()->sync([$location->id]);

        return [$user, $returnOrder, $wallet, $serial];
    }
}
