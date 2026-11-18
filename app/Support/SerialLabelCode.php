<?php

namespace App\Support;

use App\Models\SerialItem;
use Illuminate\Support\Facades\DB;

/**
 * Compact 5-digit codes for thermal labels (QR / Code 128) and wedge scanners.
 */
final class SerialLabelCode
{
    public static function digitLength(): int
    {
        return max(4, min(8, (int) config('inventory.serial_label_code_digits', 5)));
    }

    public static function maxValue(): int
    {
        return (10 ** self::digitLength()) - 1;
    }

    public static function format(int $code): string
    {
        if ($code < 1 || $code > self::maxValue()) {
            throw new \InvalidArgumentException('Label code out of range.');
        }

        return str_pad((string) $code, self::digitLength(), '0', STR_PAD_LEFT);
    }

    public static function parseScanCode(string $raw): ?int
    {
        $trimmed = trim($raw);
        if ($trimmed === '' || ! preg_match('/^\d{1,'.self::digitLength().'}$/', $trimmed)) {
            return null;
        }

        $value = (int) $trimmed;

        return $value >= 1 && $value <= self::maxValue() ? $value : null;
    }

    public static function generateUnique(): int
    {
        return DB::transaction(function (): int {
            $max = self::maxAssignedCode();

            for ($i = 1; $i <= 50; $i++) {
                $candidate = $max + $i;
                if ($candidate > self::maxValue()) {
                    throw new \RuntimeException('Serial label code range exhausted.');
                }

                if (! SerialItem::query()->where('label_code', $candidate)->exists()) {
                    return $candidate;
                }
            }

            throw new \RuntimeException('Could not allocate a unique serial label code.');
        });
    }

    public static function maxAssignedCode(): int
    {
        return (int) SerialItem::query()->whereNotNull('label_code')->max('label_code');
    }
}
