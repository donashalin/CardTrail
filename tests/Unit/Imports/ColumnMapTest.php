<?php

use App\Imports\ColumnMap;

it('round-trips through an array', function () {
    $map = ColumnMap::fromArray(['date' => 0, 'description' => 1, 'amount' => '4', 'card' => 3, 'cardholder' => null]);

    expect($map->amount)->toBe(4)
        ->and($map->purpose)->toBeNull()
        ->and($map->toArray())->toBe(['date' => 0, 'description' => 1, 'amount' => 4, 'card' => 3, 'cardholder' => null, 'purpose' => null])
        ->and($map->mappedColumns())->toBe([0, 1, 4, 3]);
});

it('guesses columns from common header names', function () {
    $guess = ColumnMap::guess(['Date', 'Description', 'Card Member', 'Account #', 'Amount', 'What was it for?']);

    expect($guess)->toBe(['date' => 0, 'description' => 1, 'amount' => 4, 'card' => 3, 'cardholder' => 2, 'purpose' => 5]);
});

it('leaves fields it cannot recognise empty', function () {
    expect(ColumnMap::guess(['Foo', 'Bar']))->toBe([
        'date' => null, 'description' => null, 'amount' => null, 'card' => null, 'cardholder' => null, 'purpose' => null,
    ]);
});
