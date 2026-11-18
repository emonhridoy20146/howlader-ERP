<?php

namespace App\Support;

use App\Models\Product;
use Illuminate\Support\Facades\DB;

final class ProductBarcode
{
    /**
     * Allocate the next unique internal numeric barcode (Code 128–friendly).
     * Format: leading digit 9 + zero-padded sequence (8 digits total by default).
     */
    public static function generateUnique(): string
    {
        return DB::transaction(function (): string {
            $max = self::maxAssignedSequence();

            for ($i = 1; $i <= 50; $i++) {
                $candidate = self::format($max + $i);
                if (! Product::query()->where('barcode', $candidate)->exists()) {
                    return $candidate;
                }
            }

            throw new \RuntimeException('Could not allocate a unique product barcode.');
        });
    }

    public static function formatLength(): int
    {
        return max(2, (int) config('inventory.barcode_length', 8));
    }

    public static function prefix(): string
    {
        return (string) config('inventory.barcode_leading_digit', '9');
    }

    public static function pattern(): string
    {
        $suffixLength = self::suffixLength();

        return '/^'.preg_quote(self::prefix(), '/').'\d{'.$suffixLength.'}$/';
    }

    public static function format(int $sequence): string
    {
        if ($sequence < 0) {
            throw new \InvalidArgumentException('Barcode sequence must be non-negative.');
        }

        $suffixLength = self::suffixLength();
        $maxSequence = (10 ** $suffixLength) - 1;
        if ($sequence > $maxSequence) {
            throw new \RuntimeException('Product barcode sequence exhausted.');
        }

        return self::prefix().str_pad((string) $sequence, $suffixLength, '0', STR_PAD_LEFT);
    }

    public static function maxAssignedSequence(): int
    {
        $pattern = self::pattern();
        $prefix = self::prefix();
        $max = 0;

        Product::query()
            ->whereNotNull('barcode')
            ->where('barcode', '!=', '')
            ->where('barcode', 'like', $prefix.'%')
            ->select('barcode')
            ->orderBy('id')
            ->chunk(500, function ($rows) use ($pattern, $prefix, &$max): void {
                foreach ($rows as $row) {
                    $barcode = (string) $row->barcode;
                    if (! preg_match($pattern, $barcode)) {
                        continue;
                    }

                    $sequence = (int) substr($barcode, strlen($prefix));
                    if ($sequence > $max) {
                        $max = $sequence;
                    }
                }
            });

        return $max;
    }

    private static function suffixLength(): int
    {
        return self::formatLength() - strlen(self::prefix());
    }
}
