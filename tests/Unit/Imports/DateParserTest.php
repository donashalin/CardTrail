<?php

use App\Imports\Parsing\DateParser;

it('reads UK and ISO date formats', function (mixed $input) {
    expect(DateParser::parse($input)?->format('Y-m-d'))->toBe('2026-10-03');
})->with([
    'day first' => '03/10/2026',
    'no leading zeros, two-digit year' => '3/10/26',
    'dashes' => '03-10-2026',
    'dots' => '03.10.2026',
    'ISO' => '2026-10-03',
    'ISO with time' => '2026-10-03 00:00:00',
    'ISO with T time' => '2026-10-03T14:30:00',
    'short month name' => '3 Oct 2026',
    'long month name' => '03 October 2026',
    'Excel-style text' => '3-Oct-26',
    'Excel serial number' => 46298,
    'Excel serial with time' => 46298.5,
    'Excel serial as text' => '46298',
    'padded' => '  03/10/2026  ',
]);

it('reads DateTime objects', function () {
    expect(DateParser::parse(new DateTimeImmutable('2026-10-03 15:00'))->format('Y-m-d H:i'))->toBe('2026-10-03 00:00');
});

it('treats two-digit years as 2000s', function () {
    expect(DateParser::parse('01/01/99')->format('Y-m-d'))->toBe('2099-01-01');
});

it('returns null for anything that is not a real date', function (mixed $input) {
    expect(DateParser::parse($input))->toBeNull();
})->with([
    'impossible day' => '31/02/2026',
    'month 13' => '13/13/2026',
    'words' => 'yesterday',
    'totals row' => 'Total',
    'empty' => '',
    'null' => null,
    'tiny number' => 123,
    'bad month name' => '3 Foo 2026',
]);
