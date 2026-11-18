<?php

namespace App\Support;

use App\Models\Location;
use Illuminate\Support\Str;

/**
 * Location codes from the first letter of each word in the name (max 20 chars, unique).
 */
final class LocationCode
{
    /**
     * Build a unique location code: initials of each word (e.g. "North Main Store" → "NMS").
     *
     * @param  int|null  $ignoreLocationId  When updating, exclude this location from the uniqueness check.
     */
    public static function suggestFromName(string $name, ?int $ignoreLocationId = null): string
    {
        $ascii = Str::ascii(trim($name));
        $words = preg_split('/[\s\-_.,;:\/\\\\|]+/u', $ascii, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $initials = '';
        foreach ($words as $word) {
            $word = trim($word, " \t\n\r\0\x0B\"'()[]{}");
            if ($word === '') {
                continue;
            }
            $first = mb_substr($word, 0, 1, 'UTF-8');
            if (preg_match('/^[a-zA-Z0-9]$/u', $first)) {
                $initials .= strtoupper($first);
            }
        }
        $base = $initials !== '' ? Str::limit($initials, 20, '') : 'LOC';

        return self::ensureUnique($base, $ignoreLocationId);
    }

    private static function ensureUnique(string $base, ?int $ignoreLocationId): string
    {
        $candidate = Str::limit($base, 20, '');
        $n = 0;

        while (self::codeTaken($candidate, $ignoreLocationId)) {
            $n++;
            $suffix = (string) ($n + 1);
            $maxBaseLen = max(1, 20 - strlen($suffix));
            $candidate = Str::limit($base, $maxBaseLen, '').$suffix;
            if ($n > 1000) {
                throw new \RuntimeException('Could not allocate a unique location code for base: '.$base);
            }
        }

        return $candidate;
    }

    private static function codeTaken(string $code, ?int $ignoreLocationId): bool
    {
        return Location::query()
            ->where('code', $code)
            ->when($ignoreLocationId !== null, fn ($q) => $q->where('id', '!=', $ignoreLocationId))
            ->exists();
    }
}
