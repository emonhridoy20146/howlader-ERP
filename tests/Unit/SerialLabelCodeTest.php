<?php

namespace Tests\Unit;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\SerialItem;
use App\Support\SerialLabelBarcode;
use App\Support\SerialLabelCode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SerialLabelCodeTest extends TestCase
{
    use RefreshDatabase;

    public function test_format_zero_pads_to_five_digits(): void
    {
        $this->assertSame('00001', SerialLabelCode::format(1));
        $this->assertSame('12345', SerialLabelCode::format(12345));
    }

    public function test_parse_scan_code_accepts_one_to_five_digit_input(): void
    {
        $this->assertSame(42, SerialLabelCode::parseScanCode('42'));
        $this->assertSame(42, SerialLabelCode::parseScanCode('00042'));
        $this->assertNull(SerialLabelCode::parseScanCode('AST-1'));
        $this->assertNull(SerialLabelCode::parseScanCode('0'));
    }

    public function test_new_serial_gets_unique_label_code(): void
    {
        $serial = $this->createSerial();

        $this->assertNotNull($serial->label_code);
        $this->assertGreaterThanOrEqual(1, (int) $serial->label_code);
    }

    public function test_label_barcode_payload_uses_label_code_not_long_serial(): void
    {
        $serial = $this->createSerial('AST-20260527-000061');
        $serial->refresh();

        $this->assertSame(
            SerialLabelCode::format((int) $serial->label_code),
            SerialLabelBarcode::linearScanPayload($serial)
        );
        $this->assertNotSame($serial->serial_number, SerialLabelBarcode::linearScanPayload($serial));
    }

    private function createSerial(string $serialNumber = 'SN-TEST-1'): SerialItem
    {
        $brand = Brand::create(['name' => 'B', 'country_of_origin' => 'BD']);
        $category = Category::create(['name' => 'C', 'parent_id' => null, 'sort_order' => 0]);
        $product = Product::create([
            'sku' => 'SKU-LBL',
            'brand_id' => $brand->id,
            'category_id' => $category->id,
            'name' => 'Label product',
            'warranty_months' => 12,
            'default_purchase_price' => 1,
            'default_sale_price' => 2,
            'reorder_level' => 0,
            'unit_of_measure' => 'unit',
            'is_serialized' => true,
            'is_active' => true,
        ]);

        return SerialItem::create([
            'product_id' => $product->id,
            'serial_number' => $serialNumber,
            'serial_source' => 'internal',
            'current_location_id' => null,
            'status' => 'in_stock',
            'purchase_cost' => 1,
        ]);
    }
}
