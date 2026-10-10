<?php

namespace App\Imports\Parsing;

use Illuminate\Support\Str;

/** A fingerprint of a header row, used to recognise a statement layout seen before. */
final class HeaderSignature
{
    /** @return list<string> */
    public static function normalise(array $headers): array
    {
        $normalised = array_map(fn ($header) => Str::lower(Str::squish((string) $header)), array_values($headers));

        while ($normalised !== [] && end($normalised) === '') {
            array_pop($normalised);
        }

        return $normalised;
    }

    public static function for(array $headers): string
    {
        return sha1(implode("\x1F", self::normalise($headers)));
    }
}
