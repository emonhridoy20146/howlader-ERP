<?php

declare(strict_types=1);

namespace App\Services\Sms;

/**
 * Phone normalisation, SMS part counting and placeholder rendering.
 */
final class SmsText
{
    private const GSM_BASIC = "@£\$¥èéùìòÇ\nØø\rÅåΔ_ΦΓΛΩΠΨΣΘΞÆæßÉ !\"#¤%&'()*+,-./0123456789:;<=>?¡ABCDEFGHIJKLMNOPQRSTUVWXYZÄÖÑÜ§¿abcdefghijklmnopqrstuvwxyzäöñüà";

    private const GSM_EXTENDED = "^{}\\[~]|€\f";

    private const BANGLA_DIGITS = ['০', '১', '২', '৩', '৪', '৫', '৬', '৭', '৮', '৯'];

    /**
     * Convert any Bangladeshi mobile format to 8801XXXXXXXXX, or null if invalid.
     */
    public static function normalizePhone(?string $phone): ?string
    {
        if ($phone === null) {
            return null;
        }

        // Accept Bangla digits typed into phone fields.
        $phone = str_replace(self::BANGLA_DIGITS, range(0, 9), $phone);
        $digits = preg_replace('/\D+/', '', $phone) ?? '';

        if (str_starts_with($digits, '00880')) {
            $digits = substr($digits, 2);
        }

        if (strlen($digits) === 11 && str_starts_with($digits, '01')) {
            $digits = '88'.$digits;
        } elseif (strlen($digits) === 10 && str_starts_with($digits, '1')) {
            $digits = '880'.$digits;
        }

        return preg_match('/^8801[3-9]\d{8}$/', $digits) === 1 ? $digits : null;
    }

    /**
     * Split free text (commas, spaces, new lines) into unique valid numbers.
     *
     * @return array{valid: list<string>, invalid: list<string>}
     */
    public static function parsePhoneList(string $raw): array
    {
        $valid = [];
        $invalid = [];

        foreach (preg_split('/[\s,;]+/u', $raw, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $token) {
            $normalized = self::normalizePhone($token);
            if ($normalized === null) {
                $invalid[] = $token;
            } else {
                $valid[$normalized] = $normalized;
            }
        }

        return ['valid' => array_values($valid), 'invalid' => array_values(array_unique($invalid))];
    }

    public static function isUnicode(string $text): bool
    {
        $length = mb_strlen($text);
        for ($i = 0; $i < $length; $i++) {
            $char = mb_substr($text, $i, 1);
            if (! str_contains(self::GSM_BASIC, $char) && ! str_contains(self::GSM_EXTENDED, $char)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{unicode: bool, characters: int, parts: int, per_part: int}
     */
    public static function analyze(string $text): array
    {
        if ($text === '') {
            return ['unicode' => false, 'characters' => 0, 'parts' => 0, 'per_part' => 160];
        }

        if (self::isUnicode($text)) {
            // Bangla and other non-GSM text: 70 per single SMS, 67 per part when joined.
            $length = mb_strlen($text);
            $parts = $length <= 70 ? 1 : (int) ceil($length / 67);

            return ['unicode' => true, 'characters' => $length, 'parts' => $parts, 'per_part' => $length <= 70 ? 70 : 67];
        }

        $length = 0;
        $chars = mb_str_split($text);
        foreach ($chars as $char) {
            $length += str_contains(self::GSM_EXTENDED, $char) ? 2 : 1;
        }
        $parts = $length <= 160 ? 1 : (int) ceil($length / 153);

        return ['unicode' => false, 'characters' => $length, 'parts' => $parts, 'per_part' => $length <= 160 ? 160 : 153];
    }

    /**
     * Replace {placeholder} tokens. Unknown placeholders are left as they are
     * so the sender notices them in the preview.
     *
     * @param  array<string, scalar|null>  $vars
     */
    public static function render(string $template, array $vars, bool $banglaDigits = false): string
    {
        $replace = [];
        foreach ($vars as $key => $value) {
            $value = (string) ($value ?? '');
            if ($banglaDigits && $key !== 'phone') {
                $value = self::toBanglaDigits($value);
            }
            $replace['{'.$key.'}'] = $value;
        }

        return trim(strtr($template, $replace));
    }

    public static function toBanglaDigits(string $value): string
    {
        return str_replace(range(0, 9), self::BANGLA_DIGITS, $value);
    }

    public static function money(float|int|string|null $amount): string
    {
        $amount = round((float) $amount, 2);

        return fmod($amount, 1.0) === 0.0 ? number_format($amount, 0) : number_format($amount, 2);
    }
}
