<?php

use App\Imports\Parsing\AmountParser;

it('reads amounts into pence', function (mixed $input, int $expected) {
    expect(AmountParser::parse($input))->toBe($expected);
})->with([
    ['12.50', 1250],
    ['£1,234.56', 123456],
    ['12.5', 1250],
    ['0.1', 10],
    ['7', 700],
    ['-12.00', -1200],
    ['(12.00)', -1200],
    ['12.00 CR', -1200],
    ['12.00cr', -1200],
    ['12.00 DR', 1200],
    ['-£3.50', -350],
    ['£-3.50', -350],
    ['3.50-', -350],
    ['+3.50', 350],
    ['GBP 9.99', 999],
    [12.34, 1234],
    [0.29, 29],
    [-5.1, -510],
    [7, 700],
]);

it('returns null for anything that is not an amount', function (mixed $input) {
    expect(AmountParser::parse($input))->toBeNull();
})->with(['12.345', 'abc', '', '1.2.3', 'CR', null, true]);
