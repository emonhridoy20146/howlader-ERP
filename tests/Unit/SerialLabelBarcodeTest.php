<?php

namespace Tests\Unit;

use App\Models\Product;
use App\Models\SerialItem;
use App\Support\SerialLabelBarcode;
use Picqer\Barcode\BarcodeGenerator;
use Tests\TestCase;

class SerialLabelBarcodeTest extends TestCase
{
    public function test_product_barcode_payload_uses_product_not_serial(): void
    {
        $product = new Product(['barcode' => '5901234123457']);
        $serial = new SerialItem([
            'serial_number' => 'AST-20260527-000073',
            'manufacturer_serial' => 'MFG-999',
        ]);
        $serial->setRelation('product', $product);

        $this->assertSame('5901234123457', SerialLabelBarcode::productBarcodePayload($serial));
    }

    public function test_resolve_ean13_for_thirteen_digit_product_barcode(): void
    {
        $this->assertSame(
            BarcodeGenerator::TYPE_EAN_13,
            SerialLabelBarcode::resolveLinearBarcodeType('5901234123457'),
        );
    }

    public function test_resolve_upc_a_for_twelve_digit_product_barcode(): void
    {
        $this->assertSame(
            BarcodeGenerator::TYPE_UPC_A,
            SerialLabelBarcode::resolveLinearBarcodeType('012345678905'),
        );
    }

    public function test_resolve_code128_for_internal_product_barcode(): void
    {
        $this->assertSame(
            BarcodeGenerator::TYPE_CODE_128,
            SerialLabelBarcode::resolveLinearBarcodeType('91234567'),
        );
    }

    public function test_qr_still_uses_serial_first(): void
    {
        $product = new Product(['barcode' => '91234567']);
        $serial = new SerialItem(['serial_number' => 'AST-001']);
        $serial->setRelation('product', $product);

        $this->assertSame('AST-001', SerialLabelBarcode::qrScanPayload($serial));
    }
}
