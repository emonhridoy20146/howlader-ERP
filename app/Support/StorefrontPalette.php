<?php

namespace App\Support;

/**
 * Deterministic color assignment for storefront category tiles/chips, keyed by
 * category id so the same category always gets the same color everywhere.
 *
 * Tailwind v4 scans blade/js source text for class names, so every class string
 * below must appear literally somewhere it scans — no `"bg-{$x}-500"` interpolation.
 */
final class StorefrontPalette
{
    /**
     * @return list<array{tile: string, chip: string, text: string}>
     */
    public static function all(): array
    {
        return [
            ['tile' => 'bg-indigo-600', 'chip' => 'bg-indigo-50 text-indigo-700', 'text' => 'text-indigo-600'],
            ['tile' => 'bg-emerald-600', 'chip' => 'bg-emerald-50 text-emerald-700', 'text' => 'text-emerald-600'],
            ['tile' => 'bg-amber-500', 'chip' => 'bg-amber-50 text-amber-700', 'text' => 'text-amber-600'],
            ['tile' => 'bg-rose-600', 'chip' => 'bg-rose-50 text-rose-700', 'text' => 'text-rose-600'],
            ['tile' => 'bg-sky-600', 'chip' => 'bg-sky-50 text-sky-700', 'text' => 'text-sky-600'],
            ['tile' => 'bg-violet-600', 'chip' => 'bg-violet-50 text-violet-700', 'text' => 'text-violet-600'],
            ['tile' => 'bg-teal-600', 'chip' => 'bg-teal-50 text-teal-700', 'text' => 'text-teal-600'],
            ['tile' => 'bg-orange-600', 'chip' => 'bg-orange-50 text-orange-700', 'text' => 'text-orange-600'],
            ['tile' => 'bg-fuchsia-600', 'chip' => 'bg-fuchsia-50 text-fuchsia-700', 'text' => 'text-fuchsia-600'],
            ['tile' => 'bg-lime-600', 'chip' => 'bg-lime-50 text-lime-700', 'text' => 'text-lime-600'],
        ];
    }

    /**
     * @return array{tile: string, chip: string, text: string}
     */
    public static function colorFor(?int $categoryId): array
    {
        $palette = self::all();

        if ($categoryId === null) {
            return $palette[0];
        }

        return $palette[$categoryId % count($palette)];
    }
}
