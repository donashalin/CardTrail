<?php

namespace App\Imports\Parsing;

use Carbon\CarbonImmutable;
use DateTimeInterface;

/** Reads the date formats card statements use. Ambiguous dates are UK day-first. */
final class DateParser
{
    /** Excel serials between these cover 1954–2119; smaller numbers are not treated as dates. */
    private const MIN_SERIAL = 20000;

    private const MAX_SERIAL = 80000;

    private const MONTHS = ['jan' => 1, 'feb' => 2, 'mar' => 3, 'apr' => 4, 'may' => 5, 'jun' => 6, 'jul' => 7, 'aug' => 8, 'sep' => 9, 'oct' => 10, 'nov' => 11, 'dec' => 12];

    public static function parse(mixed $value): ?CarbonImmutable
    {
        if ($value instanceof DateTimeInterface) {
            return CarbonImmutable::instance($value)->startOfDay();
        }

        if (is_int($value) || is_float($value)) {
            return self::fromSerial((float) $value);
        }

        if (! is_string($value)) {
            return null;
        }

        $text = trim($value);

        if (is_numeric($text)) {
            return self::fromSerial((float) $text);
        }

        // Drop any time part: "2026-10-03 00:00:00", "2026-10-03T14:30:00".
        $text = preg_replace('/[ T]\d{1,2}:\d{2}(:\d{2})?.*$/', '', $text);

        if (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})$/', $text, $m)) {
            return self::make((int) $m[1], (int) $m[2], (int) $m[3]);
        }

        if (preg_match('/^(\d{1,2})[\/.\-](\d{1,2})[\/.\-](\d{2}|\d{4})$/', $text, $m)) {
            return self::make(self::year($m[3]), (int) $m[2], (int) $m[1]);
        }

        if (preg_match('/^(\d{1,2})[\s\-]+([A-Za-z]{3,9})[\s\-,]+(\d{2}|\d{4})$/', $text, $m)) {
            $month = self::MONTHS[strtolower(substr($m[2], 0, 3))] ?? null;

            return $month ? self::make(self::year($m[3]), $month, (int) $m[1]) : null;
        }

        return null;
    }

    private static function fromSerial(float $serial): ?CarbonImmutable
    {
        if ($serial < self::MIN_SERIAL || $serial >= self::MAX_SERIAL) {
            return null;
        }

        // Excel counts days from 30 December 1899 (this absorbs its 1900 leap-year bug for modern dates).
        return CarbonImmutable::create(1899, 12, 30)->addDays((int) floor($serial));
    }

    private static function year(string $digits): int
    {
        return strlen($digits) === 2 ? 2000 + (int) $digits : (int) $digits;
    }

    private static function make(int $year, int $month, int $day): ?CarbonImmutable
    {
        return checkdate($month, $day, $year) ? CarbonImmutable::create($year, $month, $day) : null;
    }
}
