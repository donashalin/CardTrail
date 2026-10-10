<?php

use App\Imports\CardholderMatcher;
use App\Models\CardIdentifier;
use App\Models\User;

it('only matches active cardholders', function () {
    $active = User::factory()->create();
    CardIdentifier::factory()->for($active)->create(['last4' => '1001']);

    $inactive = User::factory()->inactive()->create();
    CardIdentifier::factory()->for($inactive)->create(['last4' => '2002']);

    $adminOnly = User::factory()->admin()->create(['is_cardholder' => false]);
    CardIdentifier::factory()->for($adminOnly)->create(['last4' => '3003']);

    $matcher = CardholderMatcher::forActiveCardholders();

    expect($matcher->match('1001', null))->toBe($active->id)
        ->and($matcher->match('2002', null))->toBeNull()
        ->and($matcher->match('3003', null))->toBeNull();
});

it('leaves a last four shared with an inactive or non-cardholder account unassigned', function (string $other) {
    $active = User::factory()->create();
    CardIdentifier::factory()->for($active)->create(['last4' => '1234', 'cardholder_name' => 'ACTIVE PERSON']);

    $clash = match ($other) {
        'leaver' => User::factory()->inactive()->create(),
        'admin only' => User::factory()->admin()->create(['is_cardholder' => false]),
    };
    CardIdentifier::factory()->for($clash)->create(['last4' => '1234', 'cardholder_name' => 'SHARED NAME']);

    $matcher = CardholderMatcher::forActiveCardholders();

    expect($matcher->match('1234', null))->toBeNull();
})->with(['leaver', 'admin only']);

it('leaves a cardholder name shared with a leaver unassigned', function () {
    $active = User::factory()->create();
    CardIdentifier::factory()->for($active)->nameOnly('J SMITH')->create();
    $leaver = User::factory()->inactive()->create();
    CardIdentifier::factory()->for($leaver)->nameOnly('J Smith')->create();

    expect(CardholderMatcher::forActiveCardholders()->match(null, 'J SMITH'))->toBeNull();
});

it('never matches a leaver even when they are the only match', function () {
    $leaver = User::factory()->inactive()->create();
    CardIdentifier::factory()->for($leaver)->create(['last4' => '4321']);

    expect(CardholderMatcher::forActiveCardholders()->match('4321', null))->toBeNull();
});
