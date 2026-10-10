<?php

use App\Imports\ColumnMap;
use App\Imports\RowParser;

function demoParser(): RowParser
{
    return new RowParser(
        ColumnMap::fromArray(['date' => 0, 'description' => 1, 'cardholder' => 2, 'card' => 3, 'amount' => 4]),
        ['Date', 'Description', 'Card Member', 'Account #', 'Amount', 'Category', '', 'Reference'],
    );
}

it('parses a valid statement row', function () {
    $row = demoParser()->parse(7, ['03/10/2026', ' TRAINLINE ', 'S CARDHOLDER', 'XXXX-XXXXXX-01001', '45.20', 'Travel']);

    expect($row->isValid())->toBeTrue()
        ->and($row->rowNumber)->toBe(7)
        ->and($row->date->format('Y-m-d'))->toBe('2026-10-03')
        ->and($row->description)->toBe('TRAINLINE')
        ->and($row->amount)->toBe(4520)
        ->and($row->last4)->toBe('1001')
        ->and($row->cardholderName)->toBe('S CARDHOLDER')
        ->and($row->cardRef())->toBe('1001')
        ->and($row->extra)->toBe(['Category' => 'Travel']);
});

it('reports every problem on a bad row', function () {
    $row = demoParser()->parse(9, ['31/02/2026', '', 'S CARDHOLDER', '1001', 'twelve']);

    expect($row->isValid())->toBeFalse()
        ->and(array_keys($row->errors))->toBe(['date', 'amount', 'description'])
        ->and($row->rawDate)->toBe('31/02/2026')
        ->and($row->rawAmount)->toBe('twelve')
        ->and($row->duplicateKey())->toBeNull();
});

it('applies the admin\'s fixes over the file\'s values', function () {
    $row = demoParser()->parse(9, ['31/02/2026', 'SHOP', 'S CARDHOLDER', '1001', 'twelve'], ['date' => '28/02/2026', 'amount' => '12.00']);

    expect($row->isValid())->toBeTrue()
        ->and($row->date->format('Y-m-d'))->toBe('2026-02-28')
        ->and($row->amount)->toBe(1200);
});

it('masks full card numbers in the description and unmapped columns', function () {
    $row = demoParser()->parse(3, ['03/10/2026', 'REFUND 378282246310005', 'S CARDHOLDER', '1001', '-5.00', 'Misc', 'x', '4111 1111 1111 1111']);

    expect($row->description)->toBe('REFUND **** 0005')
        ->and($row->extra['Reference'])->toBe('**** 1111');
});

it('labels unnamed extra columns by position and skips empty cells', function () {
    $row = demoParser()->parse(3, ['03/10/2026', 'SHOP', 'S CARDHOLDER', '1001', '1.00', '', 'note']);

    expect($row->extra)->toBe(['Column 7' => 'note']);
});

it('uses the cardholder name as the card reference when there is no card number', function () {
    $parser = new RowParser(ColumnMap::fromArray(['date' => 0, 'description' => 1, 'amount' => 2, 'cardholder' => 3]), ['Date', 'Desc', 'Amount', 'Name']);

    $row = $parser->parse(2, ['03/10/2026', 'SHOP', '1.00', 'J MANAGER']);

    expect($row->last4)->toBeNull()->and($row->cardRef())->toBe('J MANAGER');
});

it('builds the same duplicate key regardless of case and spacing', function () {
    $a = demoParser()->parse(2, ['03/10/2026', 'Trainline  Ltd', 'X', '1001', '45.20']);
    $b = demoParser()->parse(3, ['2026-10-03', 'TRAINLINE LTD', 'X', '1001', '£45.20']);

    expect($a->duplicateKey())->toBe($b->duplicateKey());
});

it('turns spreadsheet cell values into text', function (mixed $input, string $expected) {
    expect(RowParser::text($input))->toBe($expected);
})->with([
    [null, ''],
    [12.0, '12'],
    [12.5, '12.5'],
    ['  hi ', 'hi'],
    [true, 'TRUE'],
]);
