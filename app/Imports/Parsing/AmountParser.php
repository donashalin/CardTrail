<?php

namespace App\Imports\Parsing;

/** Turns statement amounts into integer pence. Credits come back negative. */
final class AmountParser
{
    public static function parse(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value * 100;
        }

        if (is_float($value)) {
            return (int) round($value * 100);
        }

        if (! is_string($value)) {
            return null;
        }

        $text = strtoupper(trim($value));
        $negative = false;

        if (str_ends_with($text, 'CR')) {
            $negative = true;
            $text = trim(substr($text, 0, -2));
        } elseif (str_ends_with($text, 'DR')) {
            $text = trim(substr($text, 0, -2));
        }

        if (preg_match('/^\((.*)\)$/', $text, $m)) {
            $negative = true;
            $text = $m[1];
        }

        $text = str_replace(['£', 'GBP', ' '], '', $text);

        if (str_starts_with($text, '-')) {
            $negative = true;
            $text = substr($text, 1);
        } elseif (str_ends_with($text, '-')) {
            $negative = true;
            $text = substr($text, 0, -1);
        } elseif (str_starts_with($text, '+')) {
            $text = substr($text, 1);
        }

        // Commas are only thousands separators. "1,00" (a decimal comma) is refused rather than read as £100.
        if (str_contains($text, ',')) {
            if (! preg_match('/^\d{1,3}(?:,\d{3})+(?:\.\d{1,2})?$/', $text)) {
                return null;
            }

            $text = str_replace(',', '', $text);
        }

        // String arithmetic, never floats, so 0.29 stays exactly 29p.
        if (! preg_match('/^(\d+)(?:\.(\d{1,2}))?$/', $text, $m)) {
            return null;
        }

        $pence = (int) $m[1] * 100 + (int) str_pad($m[2] ?? '0', 2, '0');

        return $negative ? -$pence : $pence;
    }
}
