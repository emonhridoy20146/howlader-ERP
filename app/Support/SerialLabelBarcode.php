<?php

namespace App\Support;

use App\Models\SerialItem;
use App\Support\SerialLabelCode;
use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\ErrorCorrectionLevel;
use Endroid\QrCode\Writer\SvgWriter;
use Illuminate\Support\Collection;
use Picqer\Barcode\Barcode;
use Picqer\Barcode\BarcodeGenerator;
use Picqer\Barcode\BarcodeGeneratorPNG;
use Throwable;

/**
 * Printed labels: QR encodes unit serial; linear barcode encodes product barcode (EAN/UPC/Code128).
 */
final class SerialLabelBarcode
{
    public const FORMAT_QR = 'qr';

    public const FORMAT_BARCODE = 'barcode';

    /** 203 DPI thermal printers (~8 px/mm). */
    private const PX_PER_MM = 8;

    /** Bar height target for 25 mm-tall landscape labels (text rows use the rest). */
    private const BAR_HEIGHT_MM = 12.5;

    private const QUIET_ZONE_MM = 0.8;

    private const MAX_BAR_WIDTH_MM = 36;

    /**
     * Product barcode for linear labels (what retail scanners expect).
     */
    public static function productBarcodePayload(SerialItem $serial): string
    {
        return trim((string) ($serial->product?->barcode ?? ''));
    }

    /**
     * Linear label payload: compact unit label code, else product barcode.
     */
    public static function linearScanPayload(SerialItem $serial): string
    {
        if ($serial->label_code !== null && (int) $serial->label_code > 0) {
            return SerialLabelCode::format((int) $serial->label_code);
        }

        return self::productBarcodePayload($serial);
    }

    /**
     * QR payload: unit serial when available (asset tracking).
     */
    public static function qrScanPayload(SerialItem $serial): string
    {
        $candidates = [
            trim((string) $serial->serial_number),
            trim((string) ($serial->manufacturer_serial ?? '')),
            self::productBarcodePayload($serial),
        ];

        foreach ($candidates as $candidate) {
            if ($candidate !== '') {
                return $candidate;
            }
        }

        return '';
    }

    public static function normalizeFormat(?string $format): string
    {
        return $format === self::FORMAT_BARCODE ? self::FORMAT_BARCODE : self::FORMAT_QR;
    }

    /**
     * Pick Picqer symbology from product barcode shape (internal numeric → Code 128).
     */
    public static function resolveLinearBarcodeType(string $payload): string
    {
        $trimmed = trim($payload);
        if ($trimmed === '') {
            return BarcodeGenerator::TYPE_CODE_128;
        }

        $digits = preg_replace('/\D/', '', $trimmed);

        if ($digits !== '' && preg_match(ProductBarcode::pattern(), $digits)) {
            return BarcodeGenerator::TYPE_CODE_128;
        }

        return match (strlen($digits)) {
            13 => BarcodeGenerator::TYPE_EAN_13,
            12 => BarcodeGenerator::TYPE_UPC_A,
            8 => BarcodeGenerator::TYPE_EAN_8,
            6, 7 => BarcodeGenerator::TYPE_UPC_E,
            default => BarcodeGenerator::TYPE_CODE_128,
        };
    }

    /**
     * @param  Collection<int, SerialItem>  $serials
     * @return array<int, string|array{image_url: string, width_mm: float, height_mm: float, width_px: int, height_px: int}>
     */
    public static function codeSvgsForSerials(Collection $serials, string $format): array
    {
        $format = self::normalizeFormat($format);
        $codes = [];

        foreach ($serials as $serial) {
            if ($format === self::FORMAT_BARCODE) {
                $payload = self::linearScanPayload($serial);
                if ($payload === '') {
                    continue;
                }

                $meta = self::labelBarcodeMeta($payload);
                if ($meta === null) {
                    continue;
                }

                $codes[$serial->id] = [
                    'image_url' => route('serial-items.label-barcode', ['serial_item' => $serial->id]),
                    'width_mm' => $meta['width_mm'],
                    'height_mm' => $meta['height_mm'],
                    'width_px' => $meta['width_px'],
                    'height_px' => $meta['height_px'],
                ];
            } else {
                $payload = self::qrScanPayload($serial);
                if ($payload === '') {
                    continue;
                }

                $codes[$serial->id] = self::qrCodeSvg($payload);
            }
        }

        return $codes;
    }

    public static function qrCodeSvg(string $payload): ?string
    {
        if ($payload === '') {
            return null;
        }

        try {
            $builder = new Builder(
                writer: new SvgWriter,
                writerOptions: [
                    SvgWriter::WRITER_OPTION_EXCLUDE_XML_DECLARATION => true,
                ],
                data: $payload,
                errorCorrectionLevel: ErrorCorrectionLevel::Low,
                size: 280,
                margin: 0,
            );

            return $builder->build()->getString();
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @return array{width_mm: float, height_mm: float, width_px: int, height_px: int}|null
     */
    public static function labelBarcodeMeta(string $payload): ?array
    {
        if ($payload === '') {
            return null;
        }

        try {
            $layout = self::layoutForPayload($payload);

            return [
                'width_mm' => round($layout['total_width_px'] / self::PX_PER_MM, 2),
                'height_mm' => round($layout['bar_height_px'] / self::PX_PER_MM, 2),
                'width_px' => $layout['total_width_px'],
                'height_px' => $layout['bar_height_px'],
            ];
        } catch (Throwable) {
            return null;
        }
    }

    public static function labelBarcodePng(string $payload): ?string
    {
        if ($payload === '' || ! extension_loaded('gd')) {
            return null;
        }

        try {
            $type = self::resolveLinearBarcodeType($payload);
            $normalized = self::normalizePayloadForType($payload, $type);
            $layout = self::layoutForPayload($normalized, $type);
            $generator = new BarcodeGeneratorPNG;
            $png = $generator->getBarcode(
                $normalized,
                $type,
                $layout['width_factor'],
                $layout['bar_height_px'],
                [0, 0, 0],
            );

            return self::padPngWithQuietZone($png, $layout['quiet_px']);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @return array{
     *     width_factor: int,
     *     bar_height_px: int,
     *     quiet_px: int,
     *     total_width_px: int
     * }
     */
    private static function layoutForPayload(string $payload, ?string $type = null): array
    {
        $type ??= self::resolveLinearBarcodeType($payload);
        $normalized = self::normalizePayloadForType($payload, $type);
        $modules = self::barcodeData($normalized, $type)->getWidth();
        $quietPx = (int) round(self::QUIET_ZONE_MM * self::PX_PER_MM);
        $barHeightPx = (int) round(self::BAR_HEIGHT_MM * self::PX_PER_MM);
        $barAreaPx = (int) round(self::MAX_BAR_WIDTH_MM * self::PX_PER_MM);

        $widthFactor = 1;
        $maxFactor = min(5, max(1, (int) floor($barAreaPx / max(1, $modules))));
        for ($candidate = $maxFactor; $candidate >= 1; $candidate--) {
            if ($modules * $candidate <= $barAreaPx) {
                $widthFactor = $candidate;
                break;
            }
        }

        return [
            'width_factor' => $widthFactor,
            'bar_height_px' => $barHeightPx,
            'quiet_px' => $quietPx,
            'total_width_px' => ($modules * $widthFactor) + ($quietPx * 2),
        ];
    }

    private static function barcodeData(string $payload, string $type): Barcode
    {
        $generator = new BarcodeGeneratorPNG;
        $method = (new \ReflectionClass($generator))->getMethod('getBarcodeData');
        $method->setAccessible(true);

        /** @var Barcode $data */
        $data = $method->invoke($generator, $payload, $type);

        return $data;
    }

    private static function normalizePayloadForType(string $payload, string $type): string
    {
        $digits = preg_replace('/\D/', '', $payload);

        return match ($type) {
            BarcodeGenerator::TYPE_EAN_13 => substr(str_pad($digits, 12, '0', STR_PAD_LEFT), 0, 12),
            BarcodeGenerator::TYPE_UPC_A => substr(str_pad($digits, 11, '0', STR_PAD_LEFT), 0, 11),
            BarcodeGenerator::TYPE_EAN_8 => substr(str_pad($digits, 7, '0', STR_PAD_LEFT), 0, 7),
            BarcodeGenerator::TYPE_UPC_E => substr(str_pad($digits, 6, '0', STR_PAD_LEFT), 0, 6),
            default => trim($payload),
        };
    }

    private static function padPngWithQuietZone(string $png, int $paddingPx): string
    {
        $src = imagecreatefromstring($png);
        if ($src === false) {
            return $png;
        }

        $srcW = imagesx($src);
        $srcH = imagesy($src);
        $destW = $srcW + ($paddingPx * 2);
        $dest = imagecreatetruecolor($destW, $srcH);
        $white = imagecolorallocate($dest, 255, 255, 255);
        imagefill($dest, 0, 0, $white);
        imagecopy($dest, $src, $paddingPx, 0, 0, 0, $srcW, $srcH);
        imagedestroy($src);

        ob_start();
        imagepng($dest, null, 0);
        $out = ob_get_clean();
        imagedestroy($dest);

        return $out !== false ? $out : $png;
    }
}
