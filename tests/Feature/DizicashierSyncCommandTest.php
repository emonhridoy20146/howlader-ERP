<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Location;
use App\Models\Product;
use App\Models\SerialItem;
use App\Models\StockLedger;
use App\Models\StockTransfer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class DizicashierSyncCommandTest extends TestCase
{
    use RefreshDatabase;

    private function configureDizicashierAuth(): void
    {
        $path = sys_get_temp_dir().'/dizicashier-feature-token-'.uniqid('', true).'.json';
        if (File::isFile($path)) {
            File::delete($path);
        }
        Config::set('dizicashier.token_storage_path', $path);
        Config::set('dizicashier.username', 'testuser');
        Config::set('dizicashier.password', 'testpass');
        Config::set('dizicashier.base_url', 'https://api.dizicashier.com');
    }

    private function loginResponse(): array
    {
        return [
            'access_token' => 'test-access-token',
            'expires_in' => 7200,
            'token_type' => 'bearer',
        ];
    }

    public function test_sync_locations_from_api(): void
    {
        $this->configureDizicashierAuth();
        $user = User::factory()->create(['is_active' => true]);
        Config::set('dizicashier.import_user_id', $user->id);

        Http::fake([
            'https://api.dizicashier.com/api/account/login' => Http::response($this->loginResponse(), 200),
            'https://api.dizicashier.com/api/WireHouse*' => Http::response([
                'Success' => true,
                'Data' => [
                    'ModelList' => [
                        [
                            'Id' => '11111111-1111-1111-1111-111111111111',
                            'Name' => 'Test Branch WH',
                            'IsDeleted' => false,
                            'IsDefault' => true,
                        ],
                    ],
                    'TotalItem' => 1,
                ],
            ], 200),
        ]);

        $exit = Artisan::call('dizicash:sync', ['--only' => 'locations']);

        $this->assertSame(0, $exit, Artisan::output());
        $this->assertDatabaseHas('locations', [
            'dizicashier_uuid' => '11111111-1111-1111-1111-111111111111',
            'name' => 'Test Branch WH',
            'type' => 'branch',
        ]);

        $code = Location::query()->where('dizicashier_uuid', '11111111-1111-1111-1111-111111111111')->value('code');
        $this->assertSame('TBW', $code);

        Artisan::call('dizicash:sync', ['--only' => 'locations']);
        $this->assertSame($code, Location::query()->where('dizicashier_uuid', '11111111-1111-1111-1111-111111111111')->value('code'));
    }

    public function test_stock_reconcile_sets_on_hand_and_serials_for_serialized_product(): void
    {
        $this->configureDizicashierAuth();
        $user = User::factory()->create(['is_active' => true]);
        Config::set('dizicashier.import_user_id', $user->id);

        $brand = Brand::query()->create(['name' => 'Dizi test brand', 'country_of_origin' => null]);
        $category = Category::query()->create(['name' => 'Dizi test cat', 'parent_id' => null, 'sort_order' => 0]);
        $whUuid = 'bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb';
        $itemUuid = 'cccccccc-cccc-cccc-cccc-cccccccccccc';
        Location::query()->create([
            'code' => 'DZ-T',
            'name' => 'Dizi test WH',
            'type' => 'branch',
            'address' => null,
            'phone' => null,
            'manager_name' => null,
            'is_active' => true,
            'dizicashier_uuid' => $whUuid,
        ]);
        $product = Product::query()->create([
            'sku' => 'DZ-TEST-SKU-1',
            'barcode' => null,
            'brand_id' => $brand->id,
            'category_id' => $category->id,
            'name' => 'Dizi test item',
            'dizicashier_uuid' => $itemUuid,
            'is_serialized' => true,
            'default_purchase_price' => 100,
            'default_sale_price' => 120,
        ]);

        Http::fake([
            'https://api.dizicashier.com/api/account/login' => Http::response($this->loginResponse(), 200),
            'https://api.dizicashier.com/api/stock*' => Http::response([
                'Success' => true,
                'Data' => [
                    'ModelList' => [
                        [
                            'Quantity' => 2,
                            'ItemId' => $itemUuid,
                            'WireHouseId' => $whUuid,
                            'WeightedAvgCost' => 100,
                            'IsSerialItem' => true,
                        ],
                    ],
                    'TotalItem' => 1,
                ],
            ], 200),
        ]);

        $exit = Artisan::call('dizicash:sync', ['--only' => 'stock']);
        $this->assertSame(0, $exit, Artisan::output());

        $locationId = (int) Location::query()->where('dizicashier_uuid', $whUuid)->value('id');
        $this->assertSame(2, (int) StockLedger::query()
            ->where('product_id', $product->id)
            ->where('location_id', $locationId)
            ->sum('quantity_delta'));
        $this->assertDatabaseHas('inventory_balances', [
            'product_id' => $product->id,
            'location_id' => $locationId,
            'on_hand_qty' => 2,
        ]);
        $this->assertSame(2, SerialItem::query()
            ->where('product_id', $product->id)
            ->where('current_location_id', $locationId)
            ->where('status', 'in_stock')
            ->count());
    }

    public function test_stock_reconcile_non_serialized_zero_from_api_adjusts_ledger(): void
    {
        $this->configureDizicashierAuth();
        $user = User::factory()->create(['is_active' => true]);
        Config::set('dizicashier.import_user_id', $user->id);

        $brand = Brand::query()->create(['name' => 'Dizi ns0 brand', 'country_of_origin' => null]);
        $category = Category::query()->create(['name' => 'Dizi ns0 cat', 'parent_id' => null, 'sort_order' => 0]);
        $whUuid = 'ffffffff-ffff-ffff-ffff-ffffffffffff';
        $itemUuid = '11111111-1111-1111-1111-111111111112';
        $location = Location::query()->create([
            'code' => 'DZ-NS0',
            'name' => 'Dizi test WH ns0',
            'type' => 'branch',
            'address' => null,
            'phone' => null,
            'manager_name' => null,
            'is_active' => true,
            'dizicashier_uuid' => $whUuid,
        ]);
        $product = Product::query()->create([
            'sku' => 'DZ-TEST-NS0',
            'barcode' => null,
            'brand_id' => $brand->id,
            'category_id' => $category->id,
            'name' => 'Dizi test bulk',
            'dizicashier_uuid' => $itemUuid,
            'is_serialized' => false,
            'default_purchase_price' => 10,
            'default_sale_price' => 12,
        ]);

        StockLedger::query()->create([
            'event_time' => now(),
            'product_id' => $product->id,
            'serial_item_id' => null,
            'location_id' => $location->id,
            'movement_type' => 'adjust_in',
            'quantity_delta' => 5,
            'unit_cost' => 10,
            'reference_type' => 'TestSeed',
            'reference_id' => 1,
            'actor_user_id' => $user->id,
        ]);

        Http::fake([
            'https://api.dizicashier.com/api/account/login' => Http::response($this->loginResponse(), 200),
            'https://api.dizicashier.com/api/stock*' => Http::response([
                'Success' => true,
                'Data' => [
                    'ModelList' => [
                        [
                            'Quantity' => 0,
                            'ItemId' => $itemUuid,
                            'WireHouseId' => $whUuid,
                            'WeightedAvgCost' => 10,
                            'IsSerialItem' => false,
                        ],
                    ],
                    'TotalItem' => 1,
                ],
            ], 200),
        ]);

        $exit = Artisan::call('dizicash:sync', ['--only' => 'stock']);
        $this->assertSame(0, $exit, Artisan::output());

        $this->assertSame(0, (int) StockLedger::query()
            ->where('product_id', $product->id)
            ->where('location_id', $location->id)
            ->sum('quantity_delta'));
        $this->assertDatabaseHas('inventory_balances', [
            'product_id' => $product->id,
            'location_id' => $location->id,
            'on_hand_qty' => 0,
        ]);
    }

    public function test_stock_reconcile_sums_multiple_api_rows_for_same_product_and_location(): void
    {
        $this->configureDizicashierAuth();
        $user = User::factory()->create(['is_active' => true]);
        Config::set('dizicashier.import_user_id', $user->id);

        $brand = Brand::query()->create(['name' => 'Dizi agg brand', 'country_of_origin' => null]);
        $category = Category::query()->create(['name' => 'Dizi agg cat', 'parent_id' => null, 'sort_order' => 0]);
        $whUuid = 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa';
        $itemUuid = 'bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb';
        $location = Location::query()->create([
            'code' => 'DZ-AGG',
            'name' => 'Mapped WH',
            'type' => 'branch',
            'address' => null,
            'phone' => null,
            'manager_name' => null,
            'is_active' => true,
            'dizicashier_uuid' => $whUuid,
        ]);
        $product = Product::query()->create([
            'sku' => 'DZ-AGG-1',
            'barcode' => null,
            'brand_id' => $brand->id,
            'category_id' => $category->id,
            'name' => 'Agg bulk',
            'dizicashier_uuid' => $itemUuid,
            'is_serialized' => false,
            'default_purchase_price' => 10,
            'default_sale_price' => 12,
        ]);

        Http::fake([
            'https://api.dizicashier.com/api/account/login' => Http::response($this->loginResponse(), 200),
            'https://api.dizicashier.com/api/stock*' => Http::response([
                'Success' => true,
                'Data' => [
                    'ModelList' => [
                        [
                            'Quantity' => 2,
                            'ItemId' => $itemUuid,
                            'WireHouseId' => $whUuid,
                            'WeightedAvgCost' => 10,
                            'IsSerialItem' => false,
                        ],
                        [
                            'Quantity' => 3,
                            'ItemId' => $itemUuid,
                            'WireHouseId' => $whUuid,
                            'WeightedAvgCost' => 10,
                            'IsSerialItem' => false,
                        ],
                    ],
                    'TotalItem' => 2,
                ],
            ], 200),
        ]);

        $exit = Artisan::call('dizicash:sync', ['--only' => 'stock']);
        $this->assertSame(0, $exit, Artisan::output());

        $this->assertSame(5, (int) StockLedger::query()
            ->where('product_id', $product->id)
            ->where('location_id', $location->id)
            ->sum('quantity_delta'));
        $this->assertSame(1, (int) StockLedger::query()
            ->where('product_id', $product->id)
            ->where('location_id', $location->id)
            ->where('reference_type', 'DizicashierStockSync')
            ->count());
    }

    public function test_stock_transfer_import_moves_non_serialized_qty_between_branches(): void
    {
        $this->configureDizicashierAuth();
        $user = User::factory()->create(['is_active' => true]);
        Config::set('dizicashier.import_user_id', $user->id);

        $brand = Brand::query()->create(['name' => 'Tr brand', 'country_of_origin' => null]);
        $category = Category::query()->create(['name' => 'Tr cat', 'parent_id' => null, 'sort_order' => 0]);
        $from = Location::query()->create([
            'code' => 'TR-F',
            'name' => 'Loc From',
            'type' => 'branch',
            'address' => null,
            'phone' => null,
            'manager_name' => null,
            'is_active' => true,
            'dizicashier_uuid' => null,
        ]);
        $to = Location::query()->create([
            'code' => 'TR-T',
            'name' => 'Loc To',
            'type' => 'branch',
            'address' => null,
            'phone' => null,
            'manager_name' => null,
            'is_active' => true,
            'dizicashier_uuid' => null,
        ]);
        $itemUuid = 'dddddddd-dddd-dddd-dddd-dddddddddddd';
        $product = Product::query()->create([
            'sku' => 'TR-SKU-1',
            'barcode' => null,
            'brand_id' => $brand->id,
            'category_id' => $category->id,
            'name' => 'Bulk widget',
            'dizicashier_uuid' => $itemUuid,
            'is_serialized' => false,
            'default_purchase_price' => 50,
            'default_sale_price' => 60,
        ]);

        $transferId = 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa';

        Http::fake([
            'https://api.dizicashier.com/api/account/login' => Http::response($this->loginResponse(), 200),
            'https://api.dizicashier.com/api/stock/gettransfer*' => Http::response([
                'Success' => true,
                'Data' => [
                    'ModelList' => [
                        [
                            'Id' => $transferId,
                            'Date' => '2026-01-15T10:00:00',
                            'From' => 'Loc From',
                            'To' => 'Loc To',
                            'Quantity' => 0,
                        ],
                    ],
                    'TotalItem' => 1,
                ],
            ], 200),
            'https://api.dizicashier.com/api/Stock/getstocktransferbyid*' => Http::response([
                'Success' => true,
                'Data' => [
                    'FromWireHouseId' => '00000000-0000-0000-0000-000000000000',
                    'ToWireHouseId' => '00000000-0000-0000-0000-000000000000',
                    'Date' => '2026-01-15T10:00:00',
                    'StockTransferItems' => [
                        [
                            'ItemId' => $itemUuid,
                            'Quantity' => 3,
                            'IsDeleted' => false,
                        ],
                    ],
                ],
            ], 200),
        ]);

        $exit = Artisan::call('dizicash:sync', ['--only' => 'transfers']);
        $this->assertSame(0, $exit, Artisan::output());

        $transfer = StockTransfer::query()->where('dizicashier_uuid', $transferId)->first();
        $this->assertNotNull($transfer);
        $this->assertSame('received', $transfer->status);
        $this->assertSame($from->id, $transfer->source_location_id);
        $this->assertSame($to->id, $transfer->destination_location_id);

        $this->assertSame(-3, (int) StockLedger::query()
            ->where('product_id', $product->id)
            ->where('location_id', $from->id)
            ->where('movement_type', 'transfer_issue')
            ->where('reference_type', 'StockTransfer')
            ->where('reference_id', $transfer->id)
            ->sum('quantity_delta'));
        $this->assertSame(3, (int) StockLedger::query()
            ->where('product_id', $product->id)
            ->where('location_id', $to->id)
            ->where('movement_type', 'transfer_receive')
            ->where('reference_type', 'StockTransfer')
            ->where('reference_id', $transfer->id)
            ->sum('quantity_delta'));
    }
}
