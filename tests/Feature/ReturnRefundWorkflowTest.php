<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Customer;
use App\Models\JournalEntry;
use App\Models\Location;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\ReturnLine;
use App\Models\ReturnOrder;
use App\Models\SaleLineSerial;
use App\Models\SalesInvoice;
use App\Models\SalesInvoiceLine;
use App\Models\SerialItem;
use App\Models\StockLedger;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\SeedsReturnScenario;
use Tests\TestCase;

class ReturnRefundWorkflowTest extends TestCase
{
    use RefreshDatabase;
    use SeedsReturnScenario;

    public function test_it_resolves_return_with_full_refund_paid_now(): void
    {
        [$user, $returnOrder, $wallet, $serial] = $this->seedReturnScenario(200000);

        $response = $this->actingAs($user)->post(route('returns.resolve', $returnOrder), [
            'settlement_mode' => 'full',
            'refund_percentage' => 100,
            'pay_now_amount' => 200000,
            'wallet_id' => $wallet->id,
        ]);

        $response->assertRedirect(route('returns.show', $returnOrder));

        $returnOrder->refresh();
        $this->assertSame('refunded', $returnOrder->status);
        $this->assertSame('paid', $returnOrder->refund_status);
        $this->assertEquals(0.0, (float) $returnOrder->refund_due_amount);
        $this->assertEquals(200000.0, (float) $returnOrder->refund_paid_amount);

        $serial->refresh();
        $this->assertSame('in_stock', $serial->status);
        $this->assertNull($serial->sale_invoice_line_id);

        $this->assertDatabaseHas('stock_ledger', [
            'reference_type' => 'ReturnOrder',
            'reference_id' => $returnOrder->id,
            'movement_type' => 'return_receive',
            'quantity_delta' => 1,
        ]);
        $this->assertDatabaseHas('payments', [
            'customer_id' => $returnOrder->customer_id,
            'direction' => 'outbound',
            'amount' => 200000,
        ]);

        $this->assertSame(1, JournalEntry::query()->where('source', 'return_settlement')->where('reference_id', $returnOrder->id)->count());
        $payment = \App\Models\Payment::query()->where('direction', 'outbound')->first();
        $this->assertNotNull($payment);
        $this->assertSame(1, JournalEntry::query()->where('source', 'refund_payment')->where('reference_id', $payment->id)->count());
    }

    public function test_it_resolves_return_with_partial_deferred_refund(): void
    {
        [$user, $returnOrder, $wallet] = $this->seedReturnScenario(200000);

        $response = $this->actingAs($user)->post(route('returns.resolve', $returnOrder), [
            'settlement_mode' => 'partial_deferred',
            'refund_percentage' => 100,
            'pay_now_amount' => 100000,
            'wallet_id' => $wallet->id,
        ]);

        $response->assertRedirect(route('returns.show', $returnOrder));

        $returnOrder->refresh();
        $this->assertSame('approved', $returnOrder->status);
        $this->assertSame('partial', $returnOrder->refund_status);
        $this->assertEquals(100000.0, (float) $returnOrder->refund_due_amount);

        $this->assertDatabaseHas('return_refunds', [
            'return_order_id' => $returnOrder->id,
            'obligation_amount' => 200000,
            'paid_amount' => 100000,
            'due_amount' => 100000,
            'status' => 'partial',
        ]);

        $this->assertSame(1, JournalEntry::query()->where('source', 'return_settlement')->where('reference_id', $returnOrder->id)->count());
        $this->assertSame(1, JournalEntry::query()->where('source', 'refund_payment')->count());
    }

    public function test_it_resolves_return_with_partial_final_settlement(): void
    {
        [$user, $returnOrder, $wallet] = $this->seedReturnScenario(200000);

        $response = $this->actingAs($user)->post(route('returns.resolve', $returnOrder), [
            'settlement_mode' => 'partial_final',
            'refund_amount_mode' => 'percent',
            'refund_percentage' => 90,
            'pay_now_amount' => 0,
            'wallet_id' => $wallet->id,
            'resolution_notes' => 'Customer damaged packaging; final settlement at 90%.',
        ]);

        $response->assertRedirect(route('returns.show', $returnOrder));

        $returnOrder->refresh();
        $this->assertSame('refunded', $returnOrder->status);
        $this->assertSame('paid', $returnOrder->refund_status);
        $this->assertEquals(180000.0, (float) $returnOrder->refund_settlement_amount);
        $this->assertEquals(180000.0, (float) $returnOrder->refund_paid_amount);
        $this->assertEquals(0.0, (float) $returnOrder->refund_due_amount);

        $this->assertDatabaseHas('return_refunds', [
            'return_order_id' => $returnOrder->id,
            'obligation_amount' => 180000,
            'paid_amount' => 180000,
            'due_amount' => 0,
            'status' => 'paid',
        ]);

        $this->assertDatabaseHas('payments', [
            'customer_id' => $returnOrder->customer_id,
            'direction' => 'outbound',
            'amount' => 180000,
            'wallet_id' => $wallet->id,
        ]);

        $this->assertSame(1, JournalEntry::query()->where('source', 'return_settlement')->where('reference_id', $returnOrder->id)->count());
        $payment = \App\Models\Payment::query()->where('direction', 'outbound')->first();
        $this->assertSame(1, JournalEntry::query()->where('source', 'refund_payment')->where('reference_id', $payment->id)->count());
    }

    /**
     * @return array<string, array{0: array<string, mixed>}>
     */
    public static function zeroRefundPayloads(): array
    {
        return [
            'full, percent 0' => [['settlement_mode' => 'full', 'refund_amount_mode' => 'percent', 'refund_percentage' => 0, 'pay_now_amount' => 0]],
            'full, flat 0' => [['settlement_mode' => 'full', 'refund_amount_mode' => 'flat', 'refund_flat_amount' => 0, 'pay_now_amount' => 0]],
            'partial deferred, 0' => [['settlement_mode' => 'partial_deferred', 'refund_amount_mode' => 'percent', 'refund_percentage' => 0, 'pay_now_amount' => 0]],
            'partial final, percent 0, no notes' => [['settlement_mode' => 'partial_final', 'refund_amount_mode' => 'percent', 'refund_percentage' => 0, 'pay_now_amount' => 0]],
            'partial final, flat 0, no notes' => [['settlement_mode' => 'partial_final', 'refund_amount_mode' => 'flat', 'refund_flat_amount' => 0, 'pay_now_amount' => 0]],
        ];
    }

    #[DataProvider('zeroRefundPayloads')]
    public function test_return_can_be_resolved_with_zero_refund_and_nothing_is_paid(array $payload): void
    {
        [$user, $returnOrder, $wallet, $serial] = $this->seedReturnScenario(200000);

        $response = $this->actingAs($user)->post(route('returns.resolve', $returnOrder), $payload);

        $response->assertSessionHasNoErrors();
        $response->assertRedirect(route('returns.show', $returnOrder));

        $returnOrder->refresh();
        $this->assertSame('refunded', $returnOrder->status);
        $this->assertEquals(0.0, (float) $returnOrder->refund_settlement_amount);
        $this->assertEquals(0.0, (float) $returnOrder->refund_paid_amount);
        $this->assertEquals(0.0, (float) $returnOrder->refund_due_amount);
        $this->assertSame(0, \App\Models\Payment::query()->where('direction', 'outbound')->count());
        $this->assertSame(0, JournalEntry::query()->where('source', 'return_settlement')->where('reference_id', $returnOrder->id)->count());
    }

    public function test_resolving_a_return_without_lines_shows_an_error_instead_of_a_server_error(): void
    {
        [$user, $returnOrder, $wallet] = $this->seedReturnScenario(200000);
        $returnOrder->returnLines()->delete();

        $this->actingAs($user)->get(route('returns.show', $returnOrder))
            ->assertOk()
            ->assertSee('No invoice lines are selected on this return yet');

        $response = $this->actingAs($user)->post(route('returns.resolve', $returnOrder), [
            'settlement_mode' => 'full',
            'refund_amount_mode' => 'percent',
            'refund_percentage' => 100,
            'pay_now_amount' => 0,
        ]);

        $response->assertRedirect(route('returns.show', $returnOrder));
        $response->assertSessionHasErrors('resolve');
        $this->assertSame('draft', $returnOrder->fresh()->status);
    }

    public function test_old_invoice_line_without_serials_is_returned_by_quantity_even_for_a_serialized_product(): void
    {
        [$user, $returnOrder, $wallet] = $this->seedReturnScenario(200000);

        // Old/imported invoice: product is serialized but the sale line has no serial records.
        $line = $returnOrder->salesInvoice->lines()->firstOrFail();
        $this->assertTrue((bool) $line->product->is_serialized);
        SaleLineSerial::query()->where('sales_invoice_line_id', $line->id)->delete();
        $returnOrder->returnLines()->delete();

        // The page offers a quantity box instead of an empty serial list.
        $this->actingAs($user)->get(route('returns.show', $returnOrder))
            ->assertOk()
            ->assertSee('Quantity to return');

        $this->actingAs($user)->put(route('returns.items', $returnOrder), [
            'lines' => [$line->id => ['qty' => 1]],
        ])->assertSessionHasNoErrors();

        $this->assertSame(1, $returnOrder->returnLines()->count());
        $this->assertNull($returnOrder->returnLines()->first()->serial_item_id);

        $response = $this->actingAs($user)->post(route('returns.resolve', $returnOrder), [
            'settlement_mode' => 'full',
            'refund_amount_mode' => 'percent',
            'refund_percentage' => 100,
            'pay_now_amount' => 200000,
            'wallet_id' => $wallet->id,
        ]);

        $response->assertSessionHasNoErrors();
        $returnOrder->refresh();
        $this->assertSame('refunded', $returnOrder->status);
        $this->assertEquals(200000.0, (float) $returnOrder->refund_paid_amount);
        $this->assertSame(1, StockLedger::query()->where('movement_type', 'return_receive')->where('reference_id', $returnOrder->id)->where('quantity_delta', 1)->count());

        // The returned unit gets a generated internal serial, in stock at the branch, tied to its ledger entry.
        $generated = SerialItem::query()
            ->where('product_id', $line->product_id)
            ->where('status', 'in_stock')
            ->where('current_location_id', $returnOrder->branch_id)
            ->get();
        $this->assertCount(1, $generated);
        $this->assertSame('internal', $generated->first()->serial_source);
        $this->assertStringStartsWith('AST', $generated->first()->serial_number);
        $this->assertSame(1, StockLedger::query()->where('reference_id', $returnOrder->id)->where('serial_item_id', $generated->first()->id)->count());

        // ...so it shows as real stock (not phantom) on a serial-counting report.
        $stock = app(\App\Services\ReportDataService::class)->branchInStockPricingPaginated(\Illuminate\Http\Request::create(
            '/reports/branch-in-stock-pricing', 'GET', ['location_id' => (string) $returnOrder->branch_id],
        ));
        $this->assertTrue($stock['rows']->getCollection()->contains(fn ($r) => (int) $r->product_id === (int) $line->product_id));

        // Rolling the return back retires the generated serial and removes the stock again.
        $this->actingAs($user)->post(route('returns.rollback', $returnOrder), ['rollback_notes' => 'test'])
            ->assertSessionHasNoErrors();
        $this->assertSame(0, SerialItem::query()->where('product_id', $line->product_id)->where('status', 'in_stock')->where('current_location_id', $returnOrder->branch_id)->count());
        $this->assertSame('sold', $generated->first()->fresh()->status);
        $this->assertSame(0, (int) StockLedger::query()->where('product_id', $line->product_id)->where('location_id', $returnOrder->branch_id)->whereIn('reference_type', ['ReturnOrder', \App\Models\ReturnOrder::class])->where('reference_id', $returnOrder->id)->sum('quantity_delta'));
    }

    public function test_partial_final_with_a_refund_still_requires_a_reason(): void
    {
        [$user, $returnOrder, $wallet] = $this->seedReturnScenario(200000);

        $response = $this->actingAs($user)->post(route('returns.resolve', $returnOrder), [
            'settlement_mode' => 'partial_final',
            'refund_amount_mode' => 'percent',
            'refund_percentage' => 90,
            'pay_now_amount' => 180000,
            'wallet_id' => $wallet->id,
        ]);

        $response->assertSessionHasErrors('resolution_notes');
    }

    public function test_partial_final_flat_settlement_pays_refund_immediately(): void
    {
        [$user, $returnOrder, $wallet] = $this->seedReturnScenario(200000);

        $response = $this->actingAs($user)->post(route('returns.resolve', $returnOrder), [
            'settlement_mode' => 'partial_final',
            'refund_amount_mode' => 'flat',
            'refund_flat_amount' => 6000,
            'pay_now_amount' => 0,
            'wallet_id' => $wallet->id,
            'resolution_notes' => 'Agreed partial refund of 6000.',
        ]);

        $response->assertRedirect(route('returns.show', $returnOrder));

        $returnOrder->refresh();
        $this->assertSame('refunded', $returnOrder->status);
        $this->assertSame('paid', $returnOrder->refund_status);
        $this->assertEquals(6000.0, (float) $returnOrder->refund_settlement_amount);
        $this->assertEquals(6000.0, (float) $returnOrder->refund_paid_amount);
        $this->assertEquals(0.0, (float) $returnOrder->refund_due_amount);

        $this->assertDatabaseHas('payments', [
            'customer_id' => $returnOrder->customer_id,
            'direction' => 'outbound',
            'amount' => 6000,
            'wallet_id' => $wallet->id,
        ]);

        $this->assertSame(1, JournalEntry::query()->where('source', 'return_settlement')->where('reference_id', $returnOrder->id)->count());
        $payment = \App\Models\Payment::query()->where('direction', 'outbound')->first();
        $this->assertSame(1, JournalEntry::query()->where('source', 'refund_payment')->where('reference_id', $payment->id)->count());
    }

    public function test_legacy_partial_final_without_payment_can_be_paid_and_posts_journals(): void
    {
        [$user, $returnOrder, $wallet] = $this->seedReturnScenario(200000);

        $returnOrder->update([
            'status' => 'refunded',
            'refund_settlement_amount' => 6000,
            'refund_paid_amount' => 0,
            'refund_due_amount' => 0,
            'refund_status' => 'unpaid',
            'restocked_at' => now(),
        ]);

        \App\Models\ReturnRefund::create([
            'return_order_id' => $returnOrder->id,
            'customer_id' => $returnOrder->customer_id,
            'sales_invoice_id' => $returnOrder->sales_invoice_id,
            'obligation_amount' => 6000,
            'paid_amount' => 0,
            'due_amount' => 0,
            'status' => 'unpaid',
        ]);

        $this->assertEquals(6000.0, $returnOrder->fresh()->refundAmountPayable());

        $response = $this->actingAs($user)->post(route('returns.pay-refund', $returnOrder), [
            'pay_amount' => 6000,
            'wallet_id' => $wallet->id,
        ]);

        $response->assertRedirect(route('returns.show', $returnOrder));

        $returnOrder->refresh();
        $this->assertSame('paid', $returnOrder->refund_status);
        $this->assertEquals(6000.0, (float) $returnOrder->refund_paid_amount);

        $this->assertSame(1, JournalEntry::query()->where('source', 'return_settlement')->where('reference_id', $returnOrder->id)->count());
        $payment = \App\Models\Payment::query()->where('direction', 'outbound')->first();
        $this->assertSame(1, JournalEntry::query()->where('source', 'refund_payment')->where('reference_id', $payment->id)->count());
    }

    public function test_it_resolves_return_with_flat_refund_amount(): void
    {
        [$user, $returnOrder, $wallet] = $this->seedReturnScenario(200000);

        $response = $this->actingAs($user)->post(route('returns.resolve', $returnOrder), [
            'settlement_mode' => 'partial_deferred',
            'refund_amount_mode' => 'flat',
            'refund_flat_amount' => 150000,
            'pay_now_amount' => 50000,
            'wallet_id' => $wallet->id,
        ]);

        $response->assertRedirect(route('returns.show', $returnOrder));

        $returnOrder->refresh();
        $this->assertSame('approved', $returnOrder->status);
        $this->assertEquals(150000.0, (float) $returnOrder->refund_settlement_amount);
        $this->assertEquals(75.0, (float) $returnOrder->refund_percentage);
        $this->assertEquals(50000.0, (float) $returnOrder->refund_paid_amount);
        $this->assertEquals(100000.0, (float) $returnOrder->refund_due_amount);
    }

    public function test_it_restocks_and_refunds_only_selected_invoice_lines(): void
    {
        $location = Location::create([
            'code' => 'LOC-2',
            'name' => 'Branch 2',
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
            'customer_code' => 'C-200',
            'name' => 'Customer Two',
            'phone' => '01700000001',
            'is_active' => true,
            'outstanding_balance' => 0,
        ]);

        $brand = Brand::create(['name' => 'Brand B']);
        $category = Category::create(['name' => 'Category B']);
        $productA = Product::create([
            'sku' => 'SKU-A',
            'brand_id' => $brand->id,
            'category_id' => $category->id,
            'name' => 'Product A',
            'warranty_months' => 12,
            'default_purchase_price' => 50000,
            'default_sale_price' => 100000,
            'reorder_level' => 0,
            'unit_of_measure' => 'unit',
            'is_serialized' => true,
            'is_active' => true,
        ]);
        $productB = Product::create([
            'sku' => 'SKU-B',
            'brand_id' => $brand->id,
            'category_id' => $category->id,
            'name' => 'Product B',
            'warranty_months' => 12,
            'default_purchase_price' => 25000,
            'default_sale_price' => 50000,
            'reorder_level' => 0,
            'unit_of_measure' => 'unit',
            'is_serialized' => true,
            'is_active' => true,
        ]);

        $invoice = SalesInvoice::create([
            'invoice_number' => 'INV-2001',
            'invoice_date' => now()->toDateString(),
            'branch_id' => $location->id,
            'customer_id' => $customer->id,
            'sale_type' => 'cash',
            'status' => 'posted',
            'subtotal' => 150000,
            'payment_status' => 'paid',
            'discount_total' => 0,
            'tax_total' => 0,
            'grand_total' => 150000,
            'amount_paid' => 150000,
            'amount_due' => 0,
            'created_by' => $user->id,
        ]);

        $lineA = SalesInvoiceLine::create([
            'sales_invoice_id' => $invoice->id,
            'product_id' => $productA->id,
            'qty' => 1,
            'unit_price' => 100000,
            'discount_amount' => 0,
            'tax_amount' => 0,
            'line_total' => 100000,
            'warranty_months_snapshot' => 12,
        ]);
        $lineB = SalesInvoiceLine::create([
            'sales_invoice_id' => $invoice->id,
            'product_id' => $productB->id,
            'qty' => 1,
            'unit_price' => 50000,
            'discount_amount' => 0,
            'tax_amount' => 0,
            'line_total' => 50000,
            'warranty_months_snapshot' => 12,
        ]);

        $serialA = SerialItem::create([
            'product_id' => $productA->id,
            'serial_number' => 'SN-A',
            'serial_source' => 'internal',
            'current_location_id' => $location->id,
            'status' => 'sold',
            'sale_invoice_line_id' => $lineA->id,
            'purchase_cost' => 50000,
        ]);
        $serialB = SerialItem::create([
            'product_id' => $productB->id,
            'serial_number' => 'SN-B',
            'serial_source' => 'internal',
            'current_location_id' => $location->id,
            'status' => 'sold',
            'sale_invoice_line_id' => $lineB->id,
            'purchase_cost' => 25000,
        ]);

        SaleLineSerial::create(['sales_invoice_line_id' => $lineA->id, 'serial_item_id' => $serialA->id]);
        SaleLineSerial::create(['sales_invoice_line_id' => $lineB->id, 'serial_item_id' => $serialB->id]);

        foreach ([$serialA, $serialB] as $serial) {
            StockLedger::create([
                'event_time' => now(),
                'product_id' => $serial->product_id,
                'serial_item_id' => $serial->id,
                'location_id' => $location->id,
                'movement_type' => 'sale_issue',
                'quantity_delta' => -1,
                'unit_cost' => $serial->purchase_cost,
                'reference_type' => 'SalesInvoice',
                'reference_id' => $invoice->id,
                'actor_user_id' => $user->id,
            ]);
        }

        $returnOrder = ReturnOrder::create([
            'return_number' => 'RET-200',
            'sales_invoice_id' => $invoice->id,
            'customer_id' => $customer->id,
            'branch_id' => $location->id,
            'status' => 'draft',
            'return_date' => now()->toDateString(),
            'reason' => 'Return line A only',
            'created_by' => $user->id,
        ]);

        ReturnLine::create([
            'return_order_id' => $returnOrder->id,
            'sales_invoice_line_id' => $lineA->id,
            'serial_item_id' => $serialA->id,
            'qty' => null,
            'resolution' => 'restock',
            'condition_notes' => null,
        ]);

        $walletLedger = \App\Models\LedgerAccount::create([
            'code' => 'W-LEDGER-2001',
            'name' => 'Wallet Ledger 2001',
            'type' => 'asset',
            'is_system' => false,
            'is_active' => true,
        ]);

        $wallet = \App\Models\Wallet::create([
            'ledger_account_id' => $walletLedger->id,
            'code' => 'W-CODE-2001',
            'name' => 'Wallet 2001',
            'kind' => 'cash',
            'is_active' => true,
        ]);
        $wallet->locations()->sync([$location->id]);

        $response = $this->actingAs($user)->post(route('returns.resolve', $returnOrder), [
            'settlement_mode' => 'full',
            'refund_percentage' => 100,
            'pay_now_amount' => 100000,
            'wallet_id' => $wallet->id,
        ]);

        $response->assertRedirect(route('returns.show', $returnOrder));

        $serialA->refresh();
        $serialB->refresh();
        $this->assertSame('in_stock', $serialA->status);
        $this->assertSame('sold', $serialB->status);
        $this->assertNotNull($serialB->sale_invoice_line_id);

        $this->assertNotNull(SaleLineSerial::query()->where('serial_item_id', $serialA->id)->value('released_at'));
        $this->assertNull(SaleLineSerial::query()->where('serial_item_id', $serialB->id)->value('released_at'));

        $returnOrder->refresh();
        $this->assertEquals(100000.0, (float) $returnOrder->refund_settlement_amount);
    }

    public function test_it_creates_journal_entry_and_affects_wallet_on_refund(): void
    {
        [$user, $returnOrder, $wallet, $serial] = $this->seedReturnScenario(200000);
        $location = $returnOrder->branch;

        // Seed an opening balance of 50,000 for the wallet
        $journalService = app(\App\Services\JournalService::class);
        $entry = $journalService->postWalletOpeningBalance(
            $location->id,
            now()->toDateString(),
            $wallet->id,
            50000.0,
            'Opening balance',
            $user->id
        );
        $entry->update(['status' => 'posted']);

        $wallet->refresh();
        $this->assertEquals(50000.0, $wallet->currentBalance());

        $initialJournalEntriesCount = \App\Models\JournalEntry::count();
        $initialJournalLinesCount = \App\Models\JournalLine::count();

        // 2. Resolve return with a partial deferred refund, immediate pay_now of 10,000
        $response = $this->actingAs($user)->post(route('returns.resolve', $returnOrder), [
            'settlement_mode' => 'partial_deferred',
            'refund_percentage' => 100,
            'pay_now_amount' => 10000,
            'wallet_id' => $wallet->id,
        ]);

        $response->assertRedirect(route('returns.show', $returnOrder));

        $returnOrder->refresh();
        $this->assertSame('approved', $returnOrder->status);
        $this->assertSame('partial', $returnOrder->refund_status);
        $this->assertEquals(190000.0, (float) $returnOrder->refund_due_amount);

        // Assert new journal entries and lines were created
        $this->assertTrue(\App\Models\JournalEntry::count() > $initialJournalEntriesCount);
        $this->assertTrue(\App\Models\JournalLine::count() > $initialJournalLinesCount);

        // Assert wallet balance is correctly decremented by 10,000
        $wallet->refresh();
        $this->assertEquals(40000.0, $wallet->currentBalance());

        // 3. Pay remaining refund of 10,000
        $response2 = $this->actingAs($user)->post(route('returns.pay-refund', $returnOrder), [
            'pay_amount' => 10000,
            'wallet_id' => $wallet->id,
            'payment_date' => now()->toDateString(),
        ]);

        $response2->assertRedirect(route('returns.show', $returnOrder));

        // Assert wallet balance is decremented again to 30,000
        $wallet->refresh();
        $this->assertEquals(30000.0, $wallet->currentBalance());
    }

    public function test_full_settlement_uses_paid_share_not_invoice_line_total(): void
    {
        [$user, $returnOrder, $wallet] = $this->seedReturnScenario(56900);

        $invoice = $returnOrder->salesInvoice;
        $invoice->update([
            'grand_total' => 56900,
            'amount_paid' => 6000,
            'amount_due' => 50900,
            'payment_status' => 'partial',
        ]);

        $response = $this->actingAs($user)->post(route('returns.resolve', $returnOrder), [
            'settlement_mode' => 'full',
            'refund_amount_mode' => 'percent',
            'refund_percentage' => 100,
            'pay_now_amount' => 0,
            'wallet_id' => $wallet->id,
        ]);

        $response->assertRedirect(route('returns.show', $returnOrder));

        $returnOrder->refresh();
        $this->assertSame('refunded', $returnOrder->status);
        $this->assertSame('paid', $returnOrder->refund_status);
        $this->assertEquals(56900.0, (float) $returnOrder->refund_base_amount);
        $this->assertEquals(6000.0, (float) $returnOrder->refund_settlement_amount);
        $this->assertEquals(6000.0, (float) $returnOrder->refund_paid_amount);

        $this->assertDatabaseHas('payments', [
            'direction' => 'outbound',
            'amount' => 6000,
            'wallet_id' => $wallet->id,
        ]);
    }

    public function test_refund_cannot_exceed_remaining_paid_on_invoice(): void
    {
        [$user, $returnOrder, $wallet] = $this->seedReturnScenario(56900);

        $invoice = $returnOrder->salesInvoice;
        $invoice->update([
            'grand_total' => 56900,
            'amount_paid' => 6000,
            'amount_due' => 50900,
            'payment_status' => 'partial',
        ]);

        ReturnOrder::create([
            'return_number' => 'RET-OTHER',
            'sales_invoice_id' => $invoice->id,
            'customer_id' => $returnOrder->customer_id,
            'branch_id' => $returnOrder->branch_id,
            'status' => 'refunded',
            'return_date' => now()->toDateString(),
            'created_by' => $user->id,
            'refund_settlement_amount' => 4000,
            'refund_paid_amount' => 4000,
            'refund_due_amount' => 0,
            'refund_status' => 'paid',
            'restocked_at' => now(),
        ]);

        $response = $this->actingAs($user)->post(route('returns.resolve', $returnOrder), [
            'settlement_mode' => 'full',
            'refund_amount_mode' => 'percent',
            'refund_percentage' => 100,
            'pay_now_amount' => 6000,
            'wallet_id' => $wallet->id,
        ]);

        $response->assertSessionHasErrors('pay_now_amount');

        $responseOk = $this->actingAs($user)->post(route('returns.resolve', $returnOrder), [
            'settlement_mode' => 'full',
            'refund_amount_mode' => 'percent',
            'refund_percentage' => 100,
            'pay_now_amount' => 2000,
            'wallet_id' => $wallet->id,
        ]);

        $responseOk->assertRedirect(route('returns.show', $returnOrder));

        $returnOrder->refresh();
        $this->assertEquals(2000.0, (float) $returnOrder->refund_settlement_amount);
        $this->assertEquals(2000.0, (float) $returnOrder->refund_paid_amount);
    }

    public function test_it_only_shows_wallets_of_the_invoice_branch(): void
    {
        [$user, $returnOrder, $walletBranch1] = $this->seedReturnScenario(100000);

        $otherLocation = Location::create([
            'code' => 'LOC-OTHER',
            'name' => 'Other Branch',
            'type' => 'branch',
            'address' => 'Chittagong',
            'is_active' => true,
        ]);

        $otherLedger = \App\Models\LedgerAccount::create([
            'code' => 'W-OTHER-LEDGER',
            'name' => 'Other Wallet Ledger',
            'type' => 'asset',
            'is_system' => false,
            'is_active' => true,
        ]);

        $walletBranchOther = \App\Models\Wallet::create([
            'ledger_account_id' => $otherLedger->id,
            'code' => 'OTHER-WALLET',
            'name' => 'Other Wallet',
            'kind' => 'cash',
            'is_active' => true,
        ]);
        $walletBranchOther->locations()->sync([$otherLocation->id]);

        $response = $this->actingAs($user)->get(route('returns.show', $returnOrder));

        $response->assertOk();
        $wallets = $response->viewData('wallets');

        $this->assertCount(1, $wallets);
        $this->assertEquals($walletBranch1->id, $wallets->first()->id);
        $this->assertFalse($wallets->pluck('id')->contains($walletBranchOther->id));
    }
}
