<?php

namespace Tests\Feature;

use App\Models\AppSetting;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Customer;
use App\Models\InstallmentPlan;
use App\Models\InstallmentSchedule;
use App\Models\Location;
use App\Models\Product;
use App\Models\Role;
use App\Models\SalesInvoice;
use App\Models\SalesInvoiceLine;
use App\Models\SerialItem;
use App\Models\StockLedger;
use App\Models\StockTransfer;
use App\Models\User;
use App\Services\InventoryBalanceService;
use App\Services\ReportDataService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Tests\TestCase;

class ReportExportTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_is_redirected_from_reports_hub(): void
    {
        $this->get(route('reports.index'))->assertRedirect(route('login'));
    }

    public function test_admin_can_view_reports_hub(): void
    {
        $user = $this->makeAdminUser();

        $this->actingAs($user)->get(route('reports.index'))->assertOk();
    }

    public function test_admin_can_open_stock_by_location_print_and_download_xlsx(): void
    {
        $user = $this->makeAdminUser();

        $html = $this->actingAs($user)->get(route('reports.print', [
            'slug' => 'stock-by-location',
        ]));
        $this->assertPrintResponseIsHtml($html);
        $html->assertSee('Stock by location', false);

        $xlsx = $this->actingAs($user)->get(route('reports.export', [
            'slug' => 'stock-by-location',
            'format' => 'xlsx',
        ]));
        $xlsx->assertOk();
        $ct = (string) $xlsx->headers->get('content-type');
        $this->assertTrue(
            str_contains($ct, 'spreadsheet') || str_contains($ct, 'octet-stream'),
            'Unexpected content-type: '.$ct
        );
        $binary = $xlsx->baseResponse;
        $this->assertInstanceOf(BinaryFileResponse::class, $binary);
        $path = $binary->getFile()->getPathname();
        $this->assertGreaterThan(500, (int) filesize($path));
    }

    public function test_admin_can_open_stock_summary_print_and_download_xlsx(): void
    {
        $user = $this->makeAdminUser();

        $page = $this->actingAs($user)->get(route('reports.stock-summary', ['category_id' => 28]));
        $page->assertOk();
        $page->assertSee('>Print<', false);
        $page->assertSee('category_id=28', false);
        $page->assertSee(route('reports.print', ['slug' => 'stock-summary'], false), false);

        $html = $this->actingAs($user)->get(route('reports.print', [
            'slug' => 'stock-summary',
        ]));
        $this->assertPrintResponseIsHtml($html);
        $html->assertSee('Stock summary', false);

        $xlsx = $this->actingAs($user)->get(route('reports.export', [
            'slug' => 'stock-summary',
            'format' => 'xlsx',
        ]));
        $xlsx->assertOk();
        $ct = (string) $xlsx->headers->get('content-type');
        $this->assertTrue(
            str_contains($ct, 'spreadsheet') || str_contains($ct, 'octet-stream'),
            'Unexpected content-type: '.$ct
        );
        $binary = $xlsx->baseResponse;
        $this->assertInstanceOf(BinaryFileResponse::class, $binary);
        $path = $binary->getFile()->getPathname();
        $this->assertGreaterThan(400, (int) filesize($path));
    }

    public function test_admin_can_view_branch_in_stock_pricing_with_mrp_and_discount(): void
    {
        $user = $this->makeAdminUser();
        $location = Location::query()->where('code', 'LOC-ADM')->firstOrFail();
        $brand = Brand::query()->create(['name' => 'Pricing brand', 'country_of_origin' => null]);
        $category = Category::query()->create(['name' => 'Phones', 'parent_id' => null, 'sort_order' => 0]);
        $product = Product::query()->create([
            'sku' => 'PHONE-1',
            'barcode' => '9550999900001',
            'brand_id' => $brand->id,
            'category_id' => $category->id,
            'name' => 'Sample phone',
            'is_serialized' => false,
            'default_purchase_price' => 10000,
            'default_sale_price' => 15000,
            'maximum_deductible' => 10,
            'warranty_months' => 12,
            'reorder_level' => 0,
            'unit_of_measure' => 'pc',
            'is_active' => true,
        ]);

        StockLedger::query()->create([
            'event_time' => now(),
            'product_id' => $product->id,
            'serial_item_id' => null,
            'location_id' => $location->id,
            'movement_type' => 'adjust_in',
            'quantity_delta' => 3,
            'unit_cost' => 10000,
            'reference_type' => 'Seed',
            'reference_id' => 1,
            'actor_user_id' => $user->id,
        ]);
        app(InventoryBalanceService::class)->recalculate($product->id, $location->id);

        AppSetting::query()->updateOrCreate(
            ['key' => 'global_maximum_deductible'],
            ['value' => '5']
        );

        $screen = $this->actingAs($user)->get(route('reports.branch-in-stock-pricing', [
            'location_id' => $location->id,
        ]));
        $screen->assertOk();
        $screen->assertSee('Sample phone', false);
        $screen->assertSee('Phones', false);
        $screen->assertSee('15,000.00', false);
        $screen->assertSee('1,500.00', false);
        $screen->assertSee('13,500.00', false);

        $html = $this->actingAs($user)->get(route('reports.print', [
            'slug' => 'branch-in-stock-pricing',
        ]));
        $this->assertPrintResponseIsHtml($html);
        $html->assertSee('Branch in-stock pricing', false);

        $this->actingAs($user)->get(route('reports.export', [
            'slug' => 'branch-in-stock-pricing',
            'format' => 'xlsx',
        ]))->assertOk();
    }

    public function test_branch_in_stock_pricing_excludes_damaged_serials_at_branch(): void
    {
        $user = $this->makeAdminUser();
        $location = Location::query()->where('code', 'LOC-ADM')->firstOrFail();
        $brand = Brand::query()->create(['name' => 'Damage brand', 'country_of_origin' => null]);
        $category = Category::query()->create(['name' => 'Damage cat', 'parent_id' => null, 'sort_order' => 0]);
        $product = Product::query()->create([
            'sku' => 'DMG-PHONE',
            'barcode' => '9550999900002',
            'brand_id' => $brand->id,
            'category_id' => $category->id,
            'name' => 'Damage phone',
            'is_serialized' => true,
            'default_purchase_price' => 10000,
            'default_sale_price' => 15000,
            'maximum_deductible' => null,
            'warranty_months' => 12,
            'reorder_level' => 0,
            'unit_of_measure' => 'pc',
            'is_active' => true,
        ]);

        SerialItem::query()->create([
            'product_id' => $product->id,
            'serial_number' => 'DMG-IN-1',
            'current_location_id' => $location->id,
            'status' => 'in_stock',
            'purchase_cost' => 10000,
        ]);
        SerialItem::query()->create([
            'product_id' => $product->id,
            'serial_number' => 'DMG-BAD-1',
            'current_location_id' => $location->id,
            'status' => 'damaged',
            'purchase_cost' => 10000,
        ]);

        foreach (['DMG-IN-1', 'DMG-BAD-1'] as $serialNumber) {
            StockLedger::query()->create([
                'event_time' => now(),
                'product_id' => $product->id,
                'serial_item_id' => SerialItem::query()->where('serial_number', $serialNumber)->value('id'),
                'location_id' => $location->id,
                'movement_type' => 'adjust_in',
                'quantity_delta' => 1,
                'unit_cost' => 10000,
                'reference_type' => 'Seed',
                'reference_id' => 1,
                'actor_user_id' => $user->id,
            ]);
        }
        app(InventoryBalanceService::class)->recalculate($product->id, $location->id);

        $pricing = app(ReportDataService::class)->branchInStockPricingPaginated(Request::create(
            '/reports/branch-in-stock-pricing',
            'GET',
            ['location_id' => (string) $location->id, 'q' => 'Damage phone'],
        ));
        $sellableRow = $pricing['rows']->first();
        $this->assertNotNull($sellableRow);
        $this->assertSame(1, (int) $sellableRow->on_hand_qty);
        $this->assertSame(1, (int) $sellableRow->available_qty);
        $this->assertSame(1, $pricing['kpis']['on_hand']);

        $damagedOnly = Product::query()->create([
            'sku' => 'DMG-ONLY',
            'barcode' => '9550999900003',
            'brand_id' => $brand->id,
            'category_id' => $category->id,
            'name' => 'Damaged only phone',
            'is_serialized' => true,
            'default_purchase_price' => 8000,
            'default_sale_price' => 12000,
            'maximum_deductible' => null,
            'warranty_months' => 12,
            'reorder_level' => 0,
            'unit_of_measure' => 'pc',
            'is_active' => true,
        ]);
        SerialItem::query()->create([
            'product_id' => $damagedOnly->id,
            'serial_number' => 'DMG-ONLY-1',
            'current_location_id' => $location->id,
            'status' => 'damaged',
            'purchase_cost' => 8000,
        ]);
        StockLedger::query()->create([
            'event_time' => now(),
            'product_id' => $damagedOnly->id,
            'serial_item_id' => SerialItem::query()->where('serial_number', 'DMG-ONLY-1')->value('id'),
            'location_id' => $location->id,
            'movement_type' => 'adjust_in',
            'quantity_delta' => 1,
            'unit_cost' => 8000,
            'reference_type' => 'Seed',
            'reference_id' => 2,
            'actor_user_id' => $user->id,
        ]);
        app(InventoryBalanceService::class)->recalculate($damagedOnly->id, $location->id);

        $damagedOnlyPricing = app(ReportDataService::class)->branchInStockPricingPaginated(Request::create(
            '/reports/branch-in-stock-pricing',
            'GET',
            ['location_id' => (string) $location->id, 'q' => 'Damaged only phone'],
        ));
        $this->assertTrue($damagedOnlyPricing['rows']->isEmpty());
    }

    public function test_branch_in_stock_pricing_ignores_phantom_ledger_without_serials(): void
    {
        $user = $this->makeAdminUser();
        $location = Location::query()->where('code', 'LOC-ADM')->firstOrFail();
        $brand = Brand::query()->create(['name' => 'Phantom brand', 'country_of_origin' => null]);
        $category = Category::query()->create(['name' => 'Phantom cat', 'parent_id' => null, 'sort_order' => 0]);
        $product = Product::query()->create([
            'sku' => 'PHANTOM-234',
            'barcode' => '9550999900998',
            'brand_id' => $brand->id,
            'category_id' => $category->id,
            'name' => 'Phantom ledger phone',
            'is_serialized' => true,
            'default_purchase_price' => 10000,
            'default_sale_price' => 15000,
            'maximum_deductible' => null,
            'warranty_months' => 12,
            'reorder_level' => 0,
            'unit_of_measure' => 'pc',
            'is_active' => true,
        ]);

        StockLedger::query()->create([
            'event_time' => now(),
            'product_id' => $product->id,
            'serial_item_id' => null,
            'location_id' => $location->id,
            'movement_type' => 'adjust_in',
            'quantity_delta' => 3,
            'unit_cost' => 10000,
            'reference_type' => 'Seed',
            'reference_id' => 1,
            'actor_user_id' => $user->id,
        ]);
        app(InventoryBalanceService::class)->recalculate($product->id, $location->id);

        $pricing = app(ReportDataService::class)->branchInStockPricingPaginated(Request::create(
            '/reports/branch-in-stock-pricing',
            'GET',
            ['location_id' => (string) $location->id, 'q' => 'Phantom ledger phone'],
        ));

        $this->assertTrue($pricing['rows']->isEmpty());
        $this->assertSame(0, $pricing['kpis']['on_hand']);
    }

    public function test_stock_summary_in_stock_matches_branch_in_stock_pricing_for_same_location(): void
    {
        $user = $this->makeAdminUser();
        $location = Location::query()->where('code', 'LOC-ADM')->firstOrFail();
        $brand = Brand::query()->create(['name' => 'Align brand', 'country_of_origin' => null]);
        $category = Category::query()->create(['name' => 'Align cat', 'parent_id' => null, 'sort_order' => 0]);

        $serialized = Product::query()->create([
            'sku' => 'ALIGN-SER',
            'barcode' => '9550999900101',
            'brand_id' => $brand->id,
            'category_id' => $category->id,
            'name' => 'Align serialized phone',
            'is_serialized' => true,
            'default_purchase_price' => 10000,
            'default_sale_price' => 15000,
            'maximum_deductible' => null,
            'warranty_months' => 12,
            'reorder_level' => 0,
            'unit_of_measure' => 'pc',
            'is_active' => true,
        ]);
        SerialItem::query()->create([
            'product_id' => $serialized->id,
            'serial_number' => 'ALIGN-IN-1',
            'current_location_id' => $location->id,
            'status' => 'in_stock',
            'purchase_cost' => 10000,
        ]);
        SerialItem::query()->create([
            'product_id' => $serialized->id,
            'serial_number' => 'ALIGN-DMG-1',
            'current_location_id' => $location->id,
            'status' => 'damaged',
            'purchase_cost' => 10000,
        ]);
        foreach (['ALIGN-IN-1', 'ALIGN-DMG-1'] as $serialNumber) {
            StockLedger::query()->create([
                'event_time' => now(),
                'product_id' => $serialized->id,
                'serial_item_id' => SerialItem::query()->where('serial_number', $serialNumber)->value('id'),
                'location_id' => $location->id,
                'movement_type' => 'adjust_in',
                'quantity_delta' => 1,
                'unit_cost' => 10000,
                'reference_type' => 'Seed',
                'reference_id' => 1,
                'actor_user_id' => $user->id,
            ]);
        }

        $phantom = Product::query()->create([
            'sku' => 'ALIGN-PHANTOM',
            'barcode' => '9550999900102',
            'brand_id' => $brand->id,
            'category_id' => $category->id,
            'name' => 'Align phantom phone',
            'is_serialized' => true,
            'default_purchase_price' => 8000,
            'default_sale_price' => 12000,
            'maximum_deductible' => null,
            'warranty_months' => 12,
            'reorder_level' => 0,
            'unit_of_measure' => 'pc',
            'is_active' => true,
        ]);
        StockLedger::query()->create([
            'event_time' => now(),
            'product_id' => $phantom->id,
            'serial_item_id' => null,
            'location_id' => $location->id,
            'movement_type' => 'adjust_in',
            'quantity_delta' => 2,
            'unit_cost' => 8000,
            'reference_type' => 'Seed',
            'reference_id' => 2,
            'actor_user_id' => $user->id,
        ]);

        $bulk = Product::query()->create([
            'sku' => 'ALIGN-BULK',
            'barcode' => '9550999900103',
            'brand_id' => $brand->id,
            'category_id' => $category->id,
            'name' => 'Align bulk cable',
            'is_serialized' => false,
            'default_purchase_price' => 500,
            'default_sale_price' => 800,
            'maximum_deductible' => null,
            'warranty_months' => 12,
            'reorder_level' => 0,
            'unit_of_measure' => 'pc',
            'is_active' => true,
        ]);
        StockLedger::query()->create([
            'event_time' => now(),
            'product_id' => $bulk->id,
            'serial_item_id' => null,
            'location_id' => $location->id,
            'movement_type' => 'adjust_in',
            'quantity_delta' => 5,
            'unit_cost' => 500,
            'reference_type' => 'Seed',
            'reference_id' => 3,
            'actor_user_id' => $user->id,
        ]);

        app(InventoryBalanceService::class)->recalculate($serialized->id, $location->id);
        app(InventoryBalanceService::class)->recalculate($phantom->id, $location->id);
        app(InventoryBalanceService::class)->recalculate($bulk->id, $location->id);

        $requestParams = ['location_id' => (string) $location->id];
        $reportService = app(ReportDataService::class);

        $pricing = $reportService->branchInStockPricingPaginated(Request::create(
            '/reports/branch-in-stock-pricing',
            'GET',
            $requestParams,
        ));
        $stockSummary = $reportService->stockSummaryPaginated(Request::create(
            '/reports/stock-summary',
            'GET',
            array_merge($requestParams, ['stock_state' => 'in_stock']),
        ));

        $this->assertSame($pricing['kpis']['on_hand'], $stockSummary['kpis']['on_hand']);
        $this->assertSame($pricing['kpis']['products'], $stockSummary['kpis']['products']);
        $this->assertSame($pricing['kpis']['on_hand'], $stockSummary['kpis']['available']);
        $this->assertSame(6, $pricing['kpis']['on_hand']);
    }

    public function test_stock_summary_lists_every_product_under_a_category_without_category_groups(): void
    {
        $user = $this->makeAdminUser();
        $location = Location::query()->where('code', 'LOC-ADM')->firstOrFail();
        $brand = Brand::query()->create(['name' => 'Tree brand', 'country_of_origin' => null]);
        $parent = Category::query()->create(['name' => 'Electronics', 'parent_id' => null, 'sort_order' => 0]);
        $child = Category::query()->create(['name' => 'Chargers', 'parent_id' => $parent->id, 'sort_order' => 1]);
        $other = Category::query()->create(['name' => 'Furniture', 'parent_id' => null, 'sort_order' => 2]);

        $charger = Product::query()->create([
            'sku' => 'CHG-1',
            'barcode' => '9550999900201',
            'brand_id' => $brand->id,
            'category_id' => $child->id,
            'name' => 'USB charger',
            'is_serialized' => false,
            'default_purchase_price' => 200,
            'default_sale_price' => 350,
            'maximum_deductible' => null,
            'warranty_months' => 6,
            'reorder_level' => 0,
            'unit_of_measure' => 'pc',
            'is_active' => true,
        ]);
        $desk = Product::query()->create([
            'sku' => 'DESK-1',
            'barcode' => '9550999900202',
            'brand_id' => $brand->id,
            'category_id' => $other->id,
            'name' => 'Office desk',
            'is_serialized' => false,
            'default_purchase_price' => 4000,
            'default_sale_price' => 5500,
            'maximum_deductible' => null,
            'warranty_months' => 12,
            'reorder_level' => 0,
            'unit_of_measure' => 'pc',
            'is_active' => true,
        ]);

        foreach ([$charger, $desk] as $product) {
            StockLedger::query()->create([
                'event_time' => now(),
                'product_id' => $product->id,
                'serial_item_id' => null,
                'location_id' => $location->id,
                'movement_type' => 'adjust_in',
                'quantity_delta' => 2,
                'unit_cost' => 200,
                'reference_type' => 'Seed',
                'reference_id' => $product->id,
                'actor_user_id' => $user->id,
            ]);
            app(InventoryBalanceService::class)->recalculate($product->id, $location->id);
        }

        $this->actingAs($user);

        $report = app(ReportDataService::class)->stockSummaryPaginated(Request::create(
            '/reports/stock-summary',
            'GET',
            [
                'category_id' => (string) $parent->id,
                'location_id' => (string) $location->id,
            ],
        ));
        $skus = collect($report['rows']->items())->pluck('sku')->all();

        $this->assertSame(['CHG-1'], $skus);
        $this->assertSame(1, $report['kpis']['products']);

        $page = $this->get(route('reports.stock-summary', [
            'category_id' => $parent->id,
            'location_id' => $location->id,
        ]));

        $page->assertOk();
        $page->assertSee('USB charger');
        $page->assertSee('CHG-1');
        $page->assertSee('Chargers');
        $page->assertDontSee('Category subtotal');
        $page->assertDontSee('grouped by branch and category');

        $print = $this->get(route('reports.print', [
            'slug' => 'stock-summary',
            'category_id' => $parent->id,
            'location_id' => $location->id,
        ]));
        $this->assertPrintResponseIsHtml($print);
        $print->assertSee('cat-group', false);
        $print->assertSee('Chargers');
        $print->assertSee('USB charger');
        $print->assertSee('Category subtotal — Chargers', false);
        $print->assertDontSee('Office desk');
    }

    public function test_admin_can_open_new_report_pages_and_print_customer_dues_html(): void
    {
        $user = $this->makeAdminUser();

        $this->actingAs($user)->get(route('reports.stock-summary'))->assertOk();
        $this->actingAs($user)->get(route('reports.low-stock'))->assertOk();
        $this->actingAs($user)->get(route('reports.customer-dues'))->assertOk();
        $this->actingAs($user)->get(route('reports.invoice-outstanding-by-customer'))->assertOk();
        $this->actingAs($user)->get(route('reports.open-purchase-orders'))->assertOk();
        $this->actingAs($user)->get(route('reports.supplier-dues-and-payments'))->assertOk();
        $this->actingAs($user)->get(route('reports.sales-by-day-branch'))->assertOk();

        $html = $this->actingAs($user)->get(route('reports.print', [
            'slug' => 'customer-dues',
        ]));
        $this->assertPrintResponseIsHtml($html);
        $html->assertSee('Customer dues', false);
    }

    public function test_admin_can_view_invoice_outstanding_by_customer_print_and_xlsx(): void
    {
        $user = $this->makeAdminUser();
        $branch = Location::query()->where('code', 'LOC-ADM')->firstOrFail();

        $customer = Customer::create([
            'customer_code' => 'C-IOC',
            'name' => 'IOC Customer',
            'phone' => '01900001000',
            'is_active' => true,
            'outstanding_balance' => 50,
        ]);

        $brand = Brand::query()->create(['name' => 'IOC Brand', 'country_of_origin' => null]);
        $category = Category::query()->create(['name' => 'IOC Cat', 'parent_id' => null, 'sort_order' => 0]);

        $product = Product::query()->create([
            'sku' => 'IOC-WIDGET',
            'barcode' => null,
            'brand_id' => $brand->id,
            'category_id' => $category->id,
            'name' => 'Outstanding Test Item',
            'is_serialized' => false,
            'default_purchase_price' => 40,
            'default_sale_price' => 50,
            'reorder_level' => 0,
            'unit_of_measure' => 'pc',
            'is_active' => true,
            'warranty_months' => 0,
        ]);

        $invoice = SalesInvoice::create([
            'invoice_number' => 'INV-IOC-1',
            'invoice_date' => now()->toDateString(),
            'branch_id' => $branch->id,
            'customer_id' => $customer->id,
            'sale_type' => 'cash',
            'status' => 'posted',
            'subtotal' => 50,
            'payment_status' => 'unpaid',
            'discount_total' => 0,
            'tax_total' => 0,
            'grand_total' => 50,
            'amount_paid' => 0,
            'amount_due' => 50,
            'created_by' => $user->id,
        ]);

        SalesInvoiceLine::query()->create([
            'sales_invoice_id' => $invoice->id,
            'product_id' => $product->id,
            'qty' => 1,
            'unit_price' => 50,
            'discount_type' => null,
            'discount_input' => null,
            'discount_amount' => 0,
            'tax_amount' => 0,
            'line_total' => 50,
            'warranty_months_snapshot' => 12,
        ]);

        $this->actingAs($user)
            ->get(route('reports.invoice-outstanding-by-customer'))
            ->assertOk()
            ->assertSee('C-IOC', false)
            ->assertSee('INV-IOC-1', false)
            ->assertSee('IOC-WIDGET', false)
            ->assertSee('Outstanding Test Item', false)
            ->assertSee('50.00', false)
            ->assertSee('Totals by branch', false)
            ->assertSee('All (filtered)', false);

        $html = $this->actingAs($user)->get(route('reports.print', [
            'slug' => 'invoice-outstanding-by-customer',
        ]));
        $this->assertPrintResponseIsHtml($html);
        $html->assertSee('Invoice outstanding by customer', false);

        $this->actingAs($user)->get(route('reports.export', [
            'slug' => 'invoice-outstanding-by-customer',
            'format' => 'xlsx',
        ]))->assertOk();
    }

    public function test_admin_sees_overdue_receivables_report_and_print_export(): void
    {
        $user = $this->makeAdminUser();
        $branch = Location::query()->where('code', 'LOC-ADM')->firstOrFail();

        $customer = Customer::create([
            'customer_code' => 'C-OD',
            'name' => 'Late Payer',
            'phone' => '01900000001',
            'is_active' => true,
            'outstanding_balance' => 100,
        ]);

        $installmentInvoice = SalesInvoice::create([
            'invoice_number' => 'INV-INST-OD-1',
            'invoice_date' => now()->subDays(45)->toDateString(),
            'branch_id' => $branch->id,
            'customer_id' => $customer->id,
            'sale_type' => 'installment',
            'status' => 'posted',
            'subtotal' => 600,
            'payment_status' => 'partial',
            'discount_total' => 0,
            'tax_total' => 0,
            'grand_total' => 600,
            'amount_paid' => 100,
            'amount_due' => 500,
            'installment_count' => 6,
            'installment_first_due_date' => now()->subDays(40)->toDateString(),
            'created_by' => $user->id,
        ]);

        $plan = InstallmentPlan::create([
            'sales_invoice_id' => $installmentInvoice->id,
            'customer_id' => $customer->id,
            'total_amount' => 600,
            'down_payment' => 100,
            'financed_amount' => 500,
            'remaining_amount' => 500,
            'penalty_enabled' => false,
            'penalty_rate' => 0,
            'reschedule_count' => 0,
            'status' => 'active',
            'created_by' => $user->id,
        ]);

        InstallmentSchedule::create([
            'installment_plan_id' => $plan->id,
            'installment_no' => 1,
            'due_date' => now()->subDays(3)->toDateString(),
            'due_amount' => 100,
            'paid_amount' => 0,
            'late_fee_amount' => 0,
            'status' => 'overdue',
        ]);

        SalesInvoice::create([
            'invoice_number' => 'INV-CR-OD-1',
            'invoice_date' => now()->subDays(30)->toDateString(),
            'branch_id' => $branch->id,
            'customer_id' => $customer->id,
            'sale_type' => 'credit',
            'status' => 'posted',
            'subtotal' => 100,
            'payment_status' => 'unpaid',
            'discount_total' => 0,
            'tax_total' => 0,
            'grand_total' => 100,
            'amount_paid' => 0,
            'amount_due' => 100,
            'credit_due_date' => now()->subDays(5)->toDateString(),
            'created_by' => $user->id,
        ]);

        $page = $this->actingAs($user)->get(route('reports.credit-overdue'));
        $page->assertOk();
        $page->assertSee('INV-CR-OD-1', false);
        $page->assertSee('INV-INST-OD-1', false);

        $html = $this->actingAs($user)->get(route('reports.print', [
            'slug' => 'credit-overdue',
        ]));
        $this->assertPrintResponseIsHtml($html);
        $html->assertSee('Overdue receivables', false);
    }

    public function test_installment_overdue_print_includes_summary_totals(): void
    {
        $user = $this->makeAdminUser();
        $branch = Location::query()->where('code', 'LOC-ADM')->firstOrFail();

        $customer = Customer::create([
            'customer_code' => 'C-INST-OD-PR',
            'name' => 'Installment Print Customer',
            'phone' => '01900000002',
            'is_active' => true,
        ]);

        $installmentInvoice = SalesInvoice::create([
            'invoice_number' => 'INV-INST-OD-PRINT',
            'invoice_date' => now()->subDays(45)->toDateString(),
            'branch_id' => $branch->id,
            'customer_id' => $customer->id,
            'sale_type' => 'installment',
            'status' => 'posted',
            'subtotal' => 200,
            'payment_status' => 'partial',
            'discount_total' => 0,
            'tax_total' => 0,
            'grand_total' => 200,
            'amount_paid' => 50,
            'amount_due' => 150,
            'installment_count' => 2,
            'installment_first_due_date' => now()->subDays(10)->toDateString(),
            'created_by' => $user->id,
        ]);

        $plan = InstallmentPlan::create([
            'sales_invoice_id' => $installmentInvoice->id,
            'customer_id' => $customer->id,
            'total_amount' => 200,
            'down_payment' => 50,
            'financed_amount' => 150,
            'remaining_amount' => 150,
            'penalty_enabled' => false,
            'penalty_rate' => 0,
            'status' => 'active',
            'created_by' => $user->id,
        ]);

        InstallmentSchedule::create([
            'installment_plan_id' => $plan->id,
            'installment_no' => 1,
            'due_date' => now()->subDays(5)->toDateString(),
            'due_amount' => 75,
            'paid_amount' => 25,
            'late_fee_amount' => 5,
            'status' => 'partially_paid',
        ]);

        $html = $this->actingAs($user)->get(route('reports.print', [
            'slug' => 'installment-overdue',
        ]));
        $this->assertPrintResponseIsHtml($html);
        $html->assertSee('Summary', false);
        $html->assertSee('Overdue installment schedules', false);
        $html->assertSee('Outstanding (remaining)', false);
        $html->assertSee('INV-INST-OD-PRINT', false);
    }

    public function test_admin_can_view_monthly_collection_and_use_print_and_excel_buttons(): void
    {
        $user = $this->makeAdminUser();
        $branch = Location::query()->where('code', 'LOC-ADM')->firstOrFail();

        $customer = Customer::create([
            'customer_code' => 'C-MC-1',
            'name' => 'Monthly Collection Customer',
            'phone' => '01900000099',
            'is_active' => true,
        ]);

        SalesInvoice::create([
            'invoice_number' => 'INV-MC-1',
            'invoice_date' => now()->toDateString(),
            'branch_id' => $branch->id,
            'customer_id' => $customer->id,
            'sale_type' => 'credit',
            'status' => 'posted',
            'subtotal' => 500,
            'payment_status' => 'unpaid',
            'discount_total' => 0,
            'tax_total' => 0,
            'grand_total' => 500,
            'amount_paid' => 0,
            'amount_due' => 500,
            'credit_due_date' => now()->toDateString(),
            'created_by' => $user->id,
        ]);

        $page = $this->actingAs($user)->get(route('reports.monthly-collection'));
        $page->assertOk();
        $page->assertSee('INV-MC-1', false);
        $page->assertSee(route('reports.print', ['slug' => 'monthly-collection']), false);
        $page->assertSee(route('reports.export', ['slug' => 'monthly-collection', 'format' => 'xlsx']), false);

        $html = $this->actingAs($user)->get(route('reports.print', [
            'slug' => 'monthly-collection',
        ]));
        $this->assertPrintResponseIsHtml($html);

        $xlsx = $this->actingAs($user)->get(route('reports.export', [
            'slug' => 'monthly-collection',
            'format' => 'xlsx',
        ]));
        $xlsx->assertOk();
        $ct = (string) $xlsx->headers->get('content-type');
        $this->assertTrue(
            str_contains($ct, 'spreadsheet') || str_contains($ct, 'octet-stream'),
            'Unexpected content-type: '.$ct
        );
        $binary = $xlsx->baseResponse;
        $this->assertInstanceOf(BinaryFileResponse::class, $binary);
        $path = $binary->getFile()->getPathname();
        $this->assertGreaterThan(500, (int) filesize($path));
    }

    public function test_export_requires_format(): void
    {
        $user = $this->makeAdminUser();

        $this->actingAs($user)
            ->get(route('reports.export', ['slug' => 'installment-overdue']))
            ->assertSessionHasErrors('format');
    }

    public function test_export_rejects_pdf_format(): void
    {
        $user = $this->makeAdminUser();

        $this->actingAs($user)
            ->get(route('reports.export', [
                'slug' => 'installment-overdue',
                'format' => 'pdf',
            ]))
            ->assertSessionHasErrors('format');
    }

    public function test_user_without_reports_export_cannot_access_exports_or_print(): void
    {
        $location = Location::create([
            'code' => 'LOC-RPT',
            'name' => 'Report branch',
            'type' => 'branch',
            'address' => 'Test',
            'is_active' => true,
        ]);

        $role = Role::query()->create([
            'name' => 'No reports',
            'slug' => 'no-reports-test',
        ]);

        $user = User::factory()->create([
            'role' => 'sales',
            'is_active' => true,
            'branch_id' => $location->id,
        ]);
        $user->roles()->sync([$role->id]);
        $user->accessibleLocations()->sync([$location->id]);

        $this->actingAs($user)->get(route('reports.index'))->assertForbidden();
        $this->actingAs($user)->get(route('reports.export', [
            'slug' => 'stock-by-location',
            'format' => 'xlsx',
        ]))->assertForbidden();
        $this->actingAs($user)->get(route('reports.print', [
            'slug' => 'stock-by-location',
        ]))->assertForbidden();
    }

    private function assertPrintResponseIsHtml($response): void
    {
        $response->assertOk();
        $ct = strtolower((string) $response->headers->get('content-type'));
        $this->assertStringContainsString('text/html', $ct);
        $response->assertSee('report-print-chrome', false);
        $response->assertSee('report-print-page-marker', false);
        $response->assertSee('counter(page)', false);
    }

    public function test_income_expenditure_print_includes_page_counter_without_fixed_footer(): void
    {
        $user = $this->makeAdminUser();

        $html = $this->actingAs($user)->get(route('reports.print', [
            'slug' => 'income-expenditure-statement',
            'date_from' => now()->startOfMonth()->toDateString(),
            'date_to' => now()->toDateString(),
        ]));

        $html->assertOk();
        $html->assertSee('report-print-page-marker', false);
        $html->assertSee('counter(page)', false);
        $html->assertSee('Statement of Comprehensive Income', false);
        $html->assertSee('financial-data', false);
        $html->assertSee('data-row', false);
        $html->assertDontSee('position: fixed', false);
    }

    public function test_profit_loss_print_uses_breakable_table_rows(): void
    {
        $user = $this->makeAdminUser();

        $html = $this->actingAs($user)->get(route('reports.print', [
            'slug' => 'profit-loss-sheet',
            'date_from' => now()->startOfMonth()->toDateString(),
            'date_to' => now()->toDateString(),
        ]));

        $html->assertOk();
        $html->assertSee('Profit Loss Sheet', false);
        $html->assertSee('financial-data', false);
        $html->assertSee('data-row', false);
        $html->assertSee('table-header-group', false);
    }

    public function test_admin_can_view_supplier_dues_and_daily_sales_print_and_xlsx(): void
    {
        $user = $this->makeAdminUser();

        $html = $this->actingAs($user)->get(route('reports.print', [
            'slug' => 'supplier-dues-and-payments',
        ]));
        $this->assertPrintResponseIsHtml($html);
        $html->assertSee('Supplier dues', false);

        $this->actingAs($user)->get(route('reports.export', [
            'slug' => 'supplier-dues-and-payments',
            'format' => 'xlsx',
        ]))->assertOk();

        $html2 = $this->actingAs($user)->get(route('reports.print', [
            'slug' => 'sales-by-day-branch',
        ]));
        $this->assertPrintResponseIsHtml($html2);
        $html2->assertSee('Daily sales', false);

        $this->actingAs($user)->get(route('reports.export', [
            'slug' => 'sales-by-day-branch',
            'format' => 'xlsx',
        ]))->assertOk();

        $this->actingAs($user)->get(route('reports.customer-payment-by-branch'))->assertOk();

        $html3 = $this->actingAs($user)->get(route('reports.print', [
            'slug' => 'customer-payment-by-branch',
        ]));
        $this->assertPrintResponseIsHtml($html3);
        $html3->assertSee('Customer payments by branch', false);

        $this->actingAs($user)->get(route('reports.export', [
            'slug' => 'customer-payment-by-branch',
            'format' => 'xlsx',
        ]))->assertOk();

        $this->actingAs($user)->get(route('reports.daily-expenses-by-branch'))->assertOk();

        $html4 = $this->actingAs($user)->get(route('reports.print', [
            'slug' => 'daily-expenses-by-branch',
        ]));
        $this->assertPrintResponseIsHtml($html4);
        $html4->assertSee('Daily expenses by branch', false);

        $this->actingAs($user)->get(route('reports.export', [
            'slug' => 'daily-expenses-by-branch',
            'format' => 'xlsx',
        ]))->assertOk();
    }

    public function test_admin_can_view_transfer_ledger_report(): void
    {
        $user = $this->makeAdminUser();
        $source = Location::create([
            'code' => 'TR-SRC',
            'name' => 'Transfer Source',
            'type' => 'branch',
            'is_active' => true,
        ]);
        $destination = Location::create([
            'code' => 'TR-DST',
            'name' => 'Transfer Destination',
            'type' => 'branch',
            'is_active' => true,
        ]);
        $brand = Brand::query()->create(['name' => 'Transfer brand', 'country_of_origin' => null]);
        $category = Category::query()->create(['name' => 'Transfer cat', 'parent_id' => null, 'sort_order' => 0]);
        $product = Product::query()->create([
            'sku' => 'TR-PROD-1',
            'barcode' => '9550999900999',
            'brand_id' => $brand->id,
            'category_id' => $category->id,
            'name' => 'Transfer phone',
            'is_serialized' => true,
            'default_purchase_price' => 10000,
            'default_sale_price' => 15000,
            'warranty_months' => 12,
            'reorder_level' => 0,
            'unit_of_measure' => 'pc',
            'is_active' => true,
        ]);
        $serial = SerialItem::create([
            'product_id' => $product->id,
            'serial_number' => 'TR-SERIAL-001',
            'current_location_id' => $destination->id,
            'status' => 'in_stock',
            'purchase_cost' => 10000,
        ]);
        $transfer = StockTransfer::create([
            'transfer_number' => 'TRF-RPT-001',
            'source_location_id' => $source->id,
            'destination_location_id' => $destination->id,
            'status' => 'received',
            'requested_by' => $user->id,
            'requested_at' => now()->subDay(),
            'issued_at' => now()->subDay(),
            'received_at' => now(),
        ]);
        $eventTime = now()->subHours(2);
        StockLedger::query()->create([
            'event_time' => $eventTime,
            'product_id' => $product->id,
            'serial_item_id' => $serial->id,
            'location_id' => $source->id,
            'movement_type' => 'transfer_issue',
            'quantity_delta' => -1,
            'reference_type' => StockTransfer::LEDGER_REFERENCE_TYPE,
            'reference_id' => $transfer->id,
            'actor_user_id' => $user->id,
        ]);
        StockLedger::query()->create([
            'event_time' => $eventTime->copy()->addHour(),
            'product_id' => $product->id,
            'serial_item_id' => $serial->id,
            'location_id' => $destination->id,
            'movement_type' => 'transfer_receive',
            'quantity_delta' => 1,
            'reference_type' => StockTransfer::LEDGER_REFERENCE_TYPE,
            'reference_id' => $transfer->id,
            'actor_user_id' => $user->id,
        ]);

        $screen = $this->actingAs($user)->get(route('reports.transfer-ledger', [
            'q' => 'TRF-RPT-001',
            'date_from' => now()->startOfMonth()->toDateString(),
            'date_to' => now()->toDateString(),
        ]));
        $screen->assertOk();
        $screen->assertSee('TRF-RPT-001', false);
        $screen->assertSee('Transfer phone', false);
        $screen->assertSee('TR-SERIAL-001', false);
        $screen->assertSee('Issue (out)', false);
        $screen->assertSee('Receive (in)', false);

        $html = $this->actingAs($user)->get(route('reports.print', [
            'slug' => 'transfer-ledger',
            'q' => 'TRF-RPT-001',
        ]));
        $this->assertPrintResponseIsHtml($html);
        $html->assertSee('Transfer ledger', false);

        $this->actingAs($user)->get(route('reports.export', [
            'slug' => 'transfer-ledger',
            'format' => 'xlsx',
            'q' => 'TRF-RPT-001',
        ]))->assertOk();
    }

    public function test_stock_reports_support_as_of_date_from_ledger(): void
    {
        $user = $this->makeAdminUser();
        $location = Location::query()->where('code', 'LOC-ADM')->firstOrFail();
        $brand = Brand::query()->create(['name' => 'History brand', 'country_of_origin' => null]);
        $category = Category::query()->create(['name' => 'History cat', 'parent_id' => null, 'sort_order' => 0]);
        $product = Product::query()->create([
            'sku' => 'HIST-001',
            'barcode' => '9550999900777',
            'brand_id' => $brand->id,
            'category_id' => $category->id,
            'name' => 'History phone',
            'is_serialized' => false,
            'default_purchase_price' => 10000,
            'default_sale_price' => 15000,
            'maximum_deductible' => null,
            'warranty_months' => 12,
            'reorder_level' => 0,
            'unit_of_measure' => 'pc',
            'is_active' => true,
        ]);

        $morning = Carbon::parse('2025-01-10 10:00:00');
        $afternoon = Carbon::parse('2025-01-10 14:00:00');

        StockLedger::query()->create([
            'event_time' => $morning,
            'product_id' => $product->id,
            'serial_item_id' => null,
            'location_id' => $location->id,
            'movement_type' => 'adjust_in',
            'quantity_delta' => 10,
            'unit_cost' => 10000,
            'reference_type' => 'Seed',
            'reference_id' => 1,
            'actor_user_id' => $user->id,
        ]);
        StockLedger::query()->create([
            'event_time' => $afternoon,
            'product_id' => $product->id,
            'serial_item_id' => null,
            'location_id' => $location->id,
            'movement_type' => 'adjust_out',
            'quantity_delta' => -3,
            'unit_cost' => 10000,
            'reference_type' => 'Seed',
            'reference_id' => 2,
            'actor_user_id' => $user->id,
        ]);
        app(InventoryBalanceService::class)->recalculate($product->id, $location->id);

        $service = app(ReportDataService::class);
        $morningSnapshot = $service->stockSummaryInventoryKpis([
            'location_id' => (string) $location->id,
            'as_of_date' => '2025-01-10',
            'as_of_time' => '11:00',
        ]);
        $afternoonSnapshot = $service->stockSummaryInventoryKpis([
            'location_id' => (string) $location->id,
            'as_of_date' => '2025-01-10',
            'as_of_time' => '15:00',
        ]);
        $endOfDaySnapshot = $service->stockSummaryInventoryKpis([
            'location_id' => (string) $location->id,
            'as_of_date' => '2025-01-10',
        ]);
        $current = $service->stockSummaryInventoryKpis([
            'location_id' => (string) $location->id,
        ]);

        $this->assertSame(10, $morningSnapshot['on_hand']);
        $this->assertSame(7, $afternoonSnapshot['on_hand']);
        $this->assertSame(7, $endOfDaySnapshot['on_hand']);
        $this->assertSame(7, $current['on_hand']);

        $screen = $this->actingAs($user)->get(route('reports.stock-by-location', [
            'location_id' => $location->id,
            'as_of_date' => '2025-01-10',
            'as_of_time' => '11:00',
            'q' => 'History phone',
        ]));
        $screen->assertOk();
        $screen->assertSee('11:00 AM', false);
        $screen->assertSee('10', false);

        $print = $this->actingAs($user)->get(route('reports.print', [
            'slug' => 'stock-summary',
            'location_id' => $location->id,
            'as_of_date' => '2025-01-10',
            'as_of_time' => '11:00',
            'q' => 'History phone',
        ]));
        $this->assertPrintResponseIsHtml($print);
        $print->assertSee('ledger snapshot', false);
    }

    private function makeAdminUser(): User
    {
        $location = Location::create([
            'code' => 'LOC-ADM',
            'name' => 'Admin branch',
            'type' => 'branch',
            'address' => 'Test',
            'is_active' => true,
        ]);

        return User::factory()->create([
            'role' => 'admin',
            'is_active' => true,
            'branch_id' => $location->id,
        ]);
    }
}
