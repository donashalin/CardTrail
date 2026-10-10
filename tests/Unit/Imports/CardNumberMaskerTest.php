<?php

use App\Imports\Parsing\CardNumberMasker;

it('masks anything that looks like a full card number', function (string $input, string $expected) {
    expect(CardNumberMasker::mask($input))->toBe($expected);
})->with([
    ['Ref 378282246310005 paid', 'Ref **** 0005 paid'],
    ['3782 822463 10005', '**** 0005'],
    ['4111-1111-1111-1111', '**** 1111'],
]);

it('leaves shorter numbers alone', function (string $input) {
    expect(CardNumberMasker::mask($input))->toBe($input);
})->with(['07700 900123', '1001', 'INV-20261003', 'Order 123456789012']);

it('extracts the last four digits of a card reference', function (mixed $input, ?string $expected) {
    expect(CardNumberMasker::last4($input))->toBe($expected);
})->with([
    ['XXXX-XXXXXX-01001', '1001'],
    ['1001', '1001'],
    [1001, '1001'],
    ['0012', '0012'],
    ['123', null],
    ['', null],
    [null, null],
]);

it('masks card numbers split by non-breaking spaces, double spaces or dots', function (string $input) {
    expect(CardNumberMasker::mask($input))->toBe('Card **** 0005');
})->with([
    'non-breaking spaces' => "Card 3782\u{00A0}822463\u{00A0}10005",
    'double spaces' => 'Card 3782  822463  10005',
    'dots' => 'Card 3782.822463.10005',
]);
