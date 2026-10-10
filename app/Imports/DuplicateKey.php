<?php

namespace App\Imports;

use Carbon\CarbonInterface;
use Illuminate\Support\Str;

/** Two lines with the same date, amount, description and card are treated as the same transaction. */
final class DuplicateKey
{
    public static function make(CarbonInterface $date, int $amount, string $description, ?string $cardRef): string
    {
        return implode('|', [
            $date->format('Y-m-d'),
            $amount,
            Str::lower(Str::squish($description)),
            Str::lower(Str::squish($cardRef ?? '')),
        ]);
    }
}
