<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\SerialItem;
use App\Support\SerialLabelBarcode;
use Illuminate\Http\Response;

class SerialItemLabelBarcodeController extends Controller
{
    public function __invoke(SerialItem $serial_item): Response
    {
        $this->authorize('viewAny', Product::class);

        $payload = SerialLabelBarcode::linearScanPayload($serial_item);
        $png = $payload !== '' ? SerialLabelBarcode::labelBarcodePng($payload) : null;

        if ($png === null) {
            abort(404);
        }

        return response($png, 200, [
            'Content-Type' => 'image/png',
            'Cache-Control' => 'private, max-age=3600',
        ]);
    }
}
