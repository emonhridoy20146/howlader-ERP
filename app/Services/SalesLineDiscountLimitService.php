<?php

namespace App\Services;

use App\Models\AppSetting;
use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

final class SalesLineDiscountLimitService
{
    private const EPS = 0.005;

    public const DISCOUNT_PERCENT = 'percent';

    public const DISCOUNT_FIXED = 'fixed';

    /**
     * Effective max discount percentage for the line (0–100), or null when uncapped.
     */
    public function effectiveMaxDiscountPercent(Product $product): ?float
    {
        if ($product->maximum_deductible !== null) {
            return $this->clampPercent((float) $product->maximum_deductible);
        }

        $raw = AppSetting::query()->where('key', 'global_maximum_deductible')->value('value');
        if ($raw === null || trim((string) $raw) === '') {
            return null;
        }

        return $this->clampPercent((float) $raw);
    }

    /**
     * @return array{max_percent: float|null, max_amount: float, unlimited: bool}
     */
    public function getMaxDiscount(Product $product, float $lineGross): array
    {
        $lineGross = max(0, round($lineGross, 2));
        $pct = $this->effectiveMaxDiscountPercent($product);
        if ($pct === null) {
            return ['max_percent' => null, 'max_amount' => null, 'unlimited' => true];
        }

        return [
            'max_percent' => $pct,
            'max_amount' => round($lineGross * ($pct / 100), 2),
            'unlimited' => false,
        ];
    }

    public function clampPercent(float $value): float
    {
        if ($value <= 0) {
            return 0.0;
        }
        if ($value >= 100) {
            return 100.0;
        }

        return round($value, 2);
    }

    /**
     * @return array{type: string, input: float}
     */
    public function normalizeDiscountTypeInput(?string $type, mixed $input): array
    {
        $type = strtolower(trim((string) $type));
        if (! in_array($type, [self::DISCOUNT_PERCENT, self::DISCOUNT_FIXED], true)) {
            $type = self::DISCOUNT_PERCENT;
        }
        $in = is_numeric($input) ? (float) $input : 0.0;

        return ['type' => $type, 'input' => round(max(0, $in), 2)];
    }

    public function appliedDiscountAmount(string $discountType, float $discountInput, float $lineGross): float
    {
        $lineGross = max(0, round($lineGross, 2));
        $discountInput = max(0, round($discountInput, 4));

        if ($discountType === self::DISCOUNT_FIXED) {
            return min(round($discountInput, 2), $lineGross);
        }

        $pct = min(100, $discountInput);

        return min(round($lineGross * ($pct / 100), 2), $lineGross);
    }

    public function exceedsNonOverrideLimit(Product $product, float $discountAmount, float $lineGross): bool
    {
        $meta = $this->getMaxDiscount($product, $lineGross);
        if ($meta['unlimited']) {
            return false;
        }

        return $discountAmount > (float) $meta['max_amount'] + self::EPS;
    }

    public function userMayOverrideMaximum(?User $user): bool
    {
        return $user && Gate::forUser($user)->allows('override-maximum-discount');
    }
}
