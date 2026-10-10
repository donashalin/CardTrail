<?php

use App\Imports\CardholderMatcher;

function matcher(): CardholderMatcher
{
    return new CardholderMatcher([
        ['user_id' => 1, 'last4' => '1001', 'cardholder_name' => 'S CARDHOLDER'],
        ['user_id' => 2, 'last4' => '1002', 'cardholder_name' => 'J Manager'],
        ['user_id' => 3, 'last4' => '7777', 'cardholder_name' => null],
        ['user_id' => 4, 'last4' => '7777', 'cardholder_name' => 'SHARED NAME'],
        ['user_id' => 5, 'last4' => null, 'cardholder_name' => 'shared name'],
    ]);
}

it('matches on a unique last four digits', function () {
    expect(matcher()->match('1001', null))->toBe(1);
});

it('leaves a last four shared by two people unassigned rather than guessing', function () {
    expect(matcher()->match('7777', 'J MANAGER'))->toBeNull();
});

it('falls back to the cardholder name when the last four is unknown', function () {
    expect(matcher()->match('9999', '  j   manager '))->toBe(2)
        ->and(matcher()->match(null, 'J MANAGER'))->toBe(2);
});

it('leaves an ambiguous cardholder name unassigned', function () {
    expect(matcher()->match(null, 'Shared Name'))->toBeNull();
});

it('returns null when nothing matches', function () {
    expect(matcher()->match('0000', 'NOBODY'))->toBeNull()
        ->and(matcher()->match(null, null))->toBeNull();
});
