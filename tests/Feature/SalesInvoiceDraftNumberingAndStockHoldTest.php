<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Customer;
use App\Models\LedgerAccount;
use App\Models\Location;
use App\Models\PaymentMethod;
use App\Models\PaymentMethodWalletMap;
use App\Models\Product;
use App\Models\SaleLineSerial;
use App\Models\SalesInvoice;
use App\Models\SerialItem;
use App\Models\StockLedger;
use App\Models\User;
use App\Models\Wallet;
use App\Services\PaymentService;
use App\Support\LedgerAccountCodes;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SalesInvoiceDraftNumberingAndStockHoldTest extends TestCase
{
    use RefreshDatabase;

    public function test_draft_invoice_does_not_hold_stock_or_receive_invoice_number(): void
    {
        [$user, $branch, $customer, $product, $serial] = $this->seedInvoiceFixture();

        $response = $this->actingAs($user)->post(route('sales.invoices.store'), $this->invoicePayload(
            $branch,
            $customer,
            $product,
            $serial,
        ));

        $response->assertRedirect(route('sales.invoices.index'));

        $invoice = SalesInvoice::query()->firstOrFail();

        $this->assertNull($invoice->invoice_number);
        $this->assertSame('draft', $invoice->status);
        $this->assertSame('in_stock', $serial->fresh()->status);
        $this->assertTrue(SaleLineSerial::query()->where('serial_item_id', $serial->id)->exists());
    }

    public function test_invoice_number_is_assigned_only_after_partial_or_full_payment(): void
    {
        [$user, $branch, $customer, $product, $serial] = $this->seedInvoiceFixture();
        $this->seedCoreLedgerAccounts();
        $paymentMethod = $this->seedPaymentMethod($branch, $user);

        $this->actingAs($user)->post(route('sales.invoices.store'), $this->invoicePayload(
            $branch,
            $customer,
            $product,
            $serial,
        ))->assertRedirect(route('sales.invoices.index'));

        $invoice = SalesInvoice::query()->firstOrFail();

        $this->actingAs($user)->post(route('sales.invoices.post', $invoice))->assertRedirect();

        $invoice->refresh();
        $this->assertSame('posted', $invoice->status);
        $this->assertSame('unpaid', $invoice->payment_status);
        $this->assertNull($invoice->invoice_number);
        $this->assertSame('sold', $serial->fresh()->status);

        app(PaymentService::class)->recordAndAllocate($invoice, [
            'amount' => 500,
            'payment_method_id' => $paymentMethod->id,
            'date' => now()->toDateString(),
        ], $user->id);

        $invoice->refresh();
        $this->assertSame('partial', $invoice->payment_status);
        $this->assertNotNull($invoice->invoice_number);
        $this->assertMatchesRegularExpression('/^INV-\d{5}$/', $invoice->invoice_number);
    }

    public function test_full_discount_invoice_receives_number_and_paid_status_on_post(): void
    {
        [$user, $branch, $customer, $product, $serial] = $this->seedInvoiceFixture();
        $this->seedCoreLedgerAccounts();

        $this->actingAs($user)->post(route('sales.invoices.store'), $this->invoicePayload(
            $branch,
            $customer,
            $product,
            $serial,
        ))->assertRedirect(route('sales.invoices.index'));

        $invoice = SalesInvoice::query()->firstOrFail();
        $invoice->update([
            'discount_total' => 1000,
            'grand_total' => 0,
            'amount_due' => 0,
            'payment_status' => 'paid',
        ]);

        $this->actingAs($user)->post(route('sales.invoices.post', $invoice))->assertRedirect();

        $invoice->refresh();
        $this->assertSame('posted', $invoice->status);
        $this->assertSame('paid', $invoice->payment_status);
        $this->assertSame(0.0, (float) $invoice->amount_due);
        $this->assertNotNull($invoice->invoice_number);
        $this->assertMatchesRegularExpression('/^INV-\d{5}$/', $invoice->invoice_number);
    }

    public function test_installment_sale_strips_line_and_invoice_discounts(): void
    {
        [$user, $branch, $customer, $product, $serial] = $this->seedInvoiceFixture();

        $payload = $this->invoicePayload($branch, $customer, $product, $serial);
        $payload['sale_type'] = 'installment';
        unset($payload['credit_due_date']);
        $payload['installment_down_payment'] = 0;
        $payload['installment_count'] = 3;
        $payload['installment_first_due_date'] = now()->addMonth()->toDateString();
        $payload['installment_frequency'] = 'monthly';
        $payload['discount_total'] = 200;
        $payload['lines'][0]['discount_type'] = 'percent';
        $payload['lines'][0]['discount_input'] = 10;
        $payload['guarantor_source'] = 'none';

        $this->actingAs($user)
            ->post(route('sales.invoices.store'), $payload)
            ->assertRedirect(route('sales.invoices.index'));

        $invoice = SalesInvoice::query()->with('lines')->firstOrFail();
        $this->assertSame('installment', $invoice->sale_type);
        $this->assertSame(0.0, (float) $invoice->discount_total);
        $this->assertSame(1000.0, (float) $invoice->subtotal);
        $line = $invoice->lines->first();
        $this->assertNotNull($line);
        $this->assertSame(0.0, (float) $line->discount_amount);
        $this->assertNull($line->discount_type);
        $this->assertNull($line->discount_input);
        $this->assertSame(1000.0, (float) $line->line_total);
    }

    /**
     * @return array{0: User, 1: Location, 2: Customer, 3: Product, 4: SerialItem}
     */
    private function seedInvoiceFixture(): array
    {
        $branch = Location::create([
            'code' => 'INV-DRAFT-BR',
            'name' => 'Invoice Draft Branch',
            'type' => 'branch',
            'is_active' => true,
        ]);

        $user = User::factory()->create([
            'role' => 'admin',
            'is_active' => true,
            'branch_id' => $branch->id,
        ]);

        $customer = Customer::create([
            'customer_code' => 'C-INV-DRAFT',
            'name' => 'Draft Customer',
            'phone' => '01700009999',
            'is_active' => true,
        ]);

        $brand = Brand::create(['name' => 'Draft Brand']);
        $category = Category::create(['name' => 'Draft Category']);

        $product = Product::create([
            'sku' => 'DRAFT-SERIAL-1',
            'brand_id' => $brand->id,
            'category_id' => $category->id,
            'name' => 'Draft Serialized Product',
            'warranty_months' => 12,
            'default_purchase_price' => 300,
            'default_sale_price' => 1000,
            'reorder_level' => 0,
            'unit_of_measure' => 'unit',
            'is_serialized' => true,
            'is_active' => true,
        ]);

        $serial = SerialItem::create([
            'product_id' => $product->id,
            'serial_number' => 'DRAFT-SN-1',
            'serial_source' => 'internal',
            'current_location_id' => $branch->id,
            'status' => 'in_stock',
            'purchase_cost' => 300,
        ]);

        StockLedger::create([
            'event_time' => now(),
            'product_id' => $product->id,
            'serial_item_id' => $serial->id,
            'location_id' => $branch->id,
            'movement_type' => 'purchase_receive',
            'quantity_delta' => 1,
            'unit_cost' => 300,
            'reference_type' => 'Test',
            'reference_id' => 1,
            'actor_user_id' => $user->id,
        ]);

        return [$user, $branch, $customer, $product, $serial];
    }

    private function invoicePayload(Location $branch, Customer $customer, Product $product, SerialItem $serial): array
    {
        return [
            'invoice_date' => now()->toDateString(),
            'branch_id' => $branch->id,
            'customer_id' => $customer->id,
            'sale_type' => 'credit',
            'credit_due_date' => now()->addDays(10)->toDateString(),
            'lines' => [
                [
                    'product_id' => $product->id,
                    'qty' => 1,
                    'unit_price' => 1000,
                    'serial_ids' => [(string) $serial->id],
                    'service_charge' => 0,
                ],
            ],
        ];
    }

    private function seedCoreLedgerAccounts(): void
    {
        foreach ([
            [LedgerAccountCodes::ACCOUNTS_RECEIVABLE, 'Accounts Receivable', 'asset'],
            [LedgerAccountCodes::SALES_REVENUE, 'Sales Revenue', 'revenue'],
        ] as [$code, $name, $type]) {
            LedgerAccount::query()->firstOrCreate(
                ['code' => $code],
                ['name' => $name, 'type' => $type, 'is_system' => true, 'is_active' => true],
            );
        }
    }

    private function seedPaymentMethod(Location $branch, User $user): PaymentMethod
    {
        $walletLedger = LedgerAccount::create([
            'code' => 'W-DRAFT-'.uniqid(),
            'name' => 'Draft Cash Wallet',
            'type' => 'asset',
            'is_system' => false,
            'is_active' => true,
        ]);

        $wallet = Wallet::create([
            'ledger_account_id' => $walletLedger->id,
            'code' => 'DRAFT-CASH-'.uniqid(),
            'name' => 'Draft Cash',
            'kind' => 'cash',
            'is_active' => true,
        ]);
        $wallet->locations()->sync([$branch->id]);

        $method = PaymentMethod::create([
            'location_id' => $branch->id,
            'code' => 'DRAFT-CASH',
            'name' => 'Draft Cash',
            'type' => 'cash',
            'is_active' => true,
            'created_by' => $user->id,
        ]);

        PaymentMethodWalletMap::create([
            'location_id' => $branch->id,
            'payment_method_id' => $method->id,
            'wallet_id' => $wallet->id,
        ]);

        return $method;
    }
}
