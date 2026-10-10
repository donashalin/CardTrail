<?php

namespace App\Imports\Parsing;

/** Keeps full card numbers out of the database: only the last four digits ever survive. */
final class CardNumberMasker
{
    /** 13–19 digits, optionally split by spaces (incl. non-breaking), dots or dashes, not touching other digits. */
    private const CARD_NUMBER = '/(?<!\d)(?:\d[\s\x{00A0}.\-]{0,2}){12,18}\d(?!\d)/u';

    public static function mask(string $text): string
    {
        return preg_replace_callback(
            self::CARD_NUMBER,
            fn (array $m) => '**** '.substr(preg_replace('/\D/', '', $m[0]), -4),
            $text,
        );
    }

    public static function last4(mixed $value): ?string
    {
        if ($value === null || is_array($value)) {
            return null;
        }

        $digits = preg_replace('/\D/', '', (string) $value);

        return strlen($digits) >= 4 ? substr($digits, -4) : null;
    }
}
